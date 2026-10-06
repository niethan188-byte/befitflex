<?php
define('IN_SUBDIR', true);
require __DIR__ . '/../includes/auth.php';
$u = require_role('admin');

$page = 'dashboard';
$title = 'Dashboard';
$subtitle = 'Everything across Santa Rosa and Cabuyao, at a glance.';

/* One round trip instead of eight separate COUNT/SUM queries. */
$s = Analytics::snapshot();

$totalMembers  = (int) $s['members_total'];
$activeMembers = (int) $s['members_active'];
$classes       = (int) $s['classes_active'];
$revenue       = (float) $s['revenue_total'];
$outstanding   = (float) $s['outstanding'];
$todayCheckins = (int) $s['checkins_today'];
$upcoming      = (int) $s['bookings_upcoming'];

$revSeries   = Analytics::revenueSeries(8);
$visitSeries = Analytics::visitSeries(14);
$revSpark    = array_map(static fn($r) => (float) $r['total'], $revSeries);
$visitSpark  = array_map(static fn($r) => (float) $r['total'], $visitSeries);

$byType = rows('SELECT membership_type, COUNT(*) n FROM members GROUP BY membership_type');
$maxType = max(array_map(fn($r) => (int) $r['n'], $byType ?: [['n' => 1]]));

$recentPayments = rows(
    'SELECT p.*, m.member_name FROM payments p
       JOIN members m ON m.member_id = p.member_id
     ORDER BY p.created_at DESC LIMIT 6'
);

$nextSessions = rows(
    "SELECT s.*, m.member_name
       FROM sessions s
       LEFT JOIN members  m ON m.member_id  = s.member_id
      WHERE s.session_status = 'Scheduled'
      ORDER BY s.session_date, s.session_time LIMIT 6"
);

$recentLog = rows(
    'SELECT a.*, u.email FROM activity_log a JOIN users u ON u.user_id = a.user_id
     ORDER BY a.created_at DESC LIMIT 7'
);

include __DIR__ . '/../includes/header.php';
?>

<div class="panel glass" style="display:flex;align-items:center;gap:18px;flex-wrap:wrap">
  <div style="flex:1;min-width:240px">
    <div style="font-size:11px;letter-spacing:.16em;text-transform:uppercase;color:var(--muted)">
      <?= date('l, j F') ?></div>
    <div style="font-size:17px;font-weight:600;margin-top:4px">
      <?php
        $h = (int) date('G');
        echo $h < 12 ? 'Good morning' : ($h < 18 ? 'Good afternoon' : 'Good evening');
      ?>. <?php
        $bits = [];
        if ($todayCheckins) { $bits[] = $todayCheckins . ' check-in' . ($todayCheckins === 1 ? '' : 's') . ' so far'; }
        if ((int) $s['inside_now']) { $bits[] = (int) $s['inside_now'] . ' on the floor'; }
        if ($upcoming) { $bits[] = $upcoming . ' booking' . ($upcoming === 1 ? '' : 's') . ' ahead'; }
        echo $bits ? e(ucfirst(implode(', ', $bits))) . '.' : 'A quiet start — nobody has checked in yet.';
      ?>
    </div>
  </div>
  <a class="btn red" href="../kiosk.php"><i class="fa-solid fa-door-open"></i> Open check-in station</a>
  <a class="btn" href="announcements.php"><i class="fa-solid fa-bullhorn"></i> Announce</a>
  <form method="post" action="backup.php" style="display:inline">
    <?= csrf_field() ?>
    <button class="btn" type="submit"><i class="fa-solid fa-database"></i> Backup database</button>
  </form>
</div>

<div class="stats">
  <div class="stat glass hover dashboard-clickable" id="activeMembersCard" role="button" tabindex="0" aria-label="Show active members">
    <div class="ico"><i class="fa-solid fa-users"></i></div>
    <div class="label">Members</div>
    <div class="value"><?= $totalMembers ?></div>
    <div class="note"><?= $activeMembers ?> active right now</div>
  </div>
  <div class="stat glass hover">
    <div class="ico"><i class="fa-solid fa-peso-sign"></i></div>
    <div class="label">Collected</div>
    <div class="value" style="font-size:26px"><?= money($revenue) ?></div>
    <div class="note"><?= money($outstanding) ?> still outstanding</div>
    <?= Chart::spark($revSpark) ?>
  </div>
  <div class="stat glass hover">
    <div class="ico"><i class="fa-solid fa-dumbbell"></i></div>
    <div class="label">Classes</div>
    <div class="value"><?= $classes ?></div>
    <div class="note">Active class schedule</div>
  </div>
  <div class="stat glass hover">
    <div class="ico"><i class="fa-solid fa-door-open"></i></div>
    <div class="label">Check-ins today</div>
    <div class="value"><?= $todayCheckins ?></div>
    <div class="note"><?= $upcoming ?> upcoming reservations</div>
    <?= Chart::spark($visitSpark) ?>
  </div>
</div>

<div class="modal" id="mActiveMembers" aria-hidden="true">
  <div class="modal-bg" onclick="closeModal('mActiveMembers')"></div>
  <div class="modal-box active-members-box">
    <h3><i class="fa-solid fa-users"></i><span id="activeMembersTitle">Active members</span></h3>
    <div id="activeMembersBody">
      <div class="empty"><i class="fa-solid fa-spinner fa-spin"></i> Loading members...</div>
    </div>
    <div class="modal-actions">
      <button type="button" class="btn" onclick="closeModal('mActiveMembers')">Close</button>
    </div>
  </div>
</div>

<script>
(() => {
  const card = document.getElementById('activeMembersCard');
  const title = document.getElementById('activeMembersTitle');
  const body = document.getElementById('activeMembersBody');
  if (!card || !title || !body) return;

  const showActiveMembers = async () => {
    title.textContent = 'Active members';
    body.innerHTML = '<div class="empty"><i class="fa-solid fa-spinner fa-spin"></i> Loading members...</div>';
    openModal('mActiveMembers');

    try {
      const response = await fetch('../api/active-members.php', { headers: { Accept: 'application/json' } });
      const result = await response.json();
      if (!response.ok || !result.ok) throw new Error(result.error || 'Unable to load active members.');

      title.textContent = `Active members (${result.members.length})`;
      if (!result.members.length) {
        body.innerHTML = '<div class="empty"><i class="fa-solid fa-user-slash"></i> No active members found.</div>';
        return;
      }

      const list = document.createElement('div');
      list.className = 'active-members-list';
      result.members.forEach((member) => {
        const row = document.createElement('div');
        row.className = 'active-member-row';
        row.innerHTML = '<span class="avatar"></span><span><b></b><small></small></span><span class="chip ok">Active</span>';
        row.querySelector('.avatar').textContent = (member.member_name || 'M').charAt(0).toUpperCase();
        row.querySelector('b').textContent = member.member_name;
        row.querySelector('small').textContent = `${member.membership_type} · Joined ${member.join_date}`;
        list.appendChild(row);
      });
      body.replaceChildren(list);
    } catch (error) {
      body.innerHTML = '<div class="empty"><i class="fa-solid fa-circle-exclamation"></i> '
        + (error.message || 'Unable to load active members.') + '</div>';
    }
  };

  card.addEventListener('click', showActiveMembers);
  card.addEventListener('keydown', (event) => {
    if (event.key === 'Enter' || event.key === ' ') {
      event.preventDefault();
      showActiveMembers();
    }
  });
})();
</script>

<div class="panel glass">
  <h2><i class="fa-solid fa-chart-line"></i> Revenue, last eight months
    <span class="spacer"></span>
    <span class="chip mute"><?= $s['engagement_pct'] ?>% engaged</span>
    <span class="chip <?= $s['collection_pct'] >= 80 ? 'ok' : 'warn' ?>"><?= $s['collection_pct'] ?>% collected</span>
    <a class="btn sm" href="analytics.php"><i class="fa-solid fa-chart-pie"></i> Full analytics</a>
  </h2>
  <?= Chart::area($revSeries, ['h' => 190, 'format' => 'peso']) ?>
</div>

<div class="grid c2">
  <div class="panel glass">
    <h2><i class="fa-solid fa-money-bill-wave"></i> Recent payments
      <span class="spacer"></span>
      <a class="btn sm" href="payments.php">All payments</a>
    </h2>
    <?php if (!$recentPayments): ?>
      <div class="empty"><i class="fa-solid fa-receipt"></i>No payments recorded yet.</div>
    <?php else: ?>
      <div class="table-wrap"><table>
        <thead><tr><th>Member</th><th>Method</th><th class="num">Amount</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($recentPayments as $p): ?>
          <tr>
            <td><?= e($p['member_name']) ?><div class="mono"><?= e($p['payment_id']) ?></div></td>
            <td><?= e($p['payment_method']) ?></td>
            <td class="num"><?= money($p['amount']) ?></td>
            <td><?= badge($p['payment_status']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    <?php endif; ?>
  </div>

  <div class="panel glass">
    <h2><i class="fa-solid fa-calendar-day"></i> Next sessions
      <span class="spacer"></span>
      <a class="btn sm" href="sessions.php">Schedule</a>
    </h2>
    <?php if (!$nextSessions): ?>
      <div class="empty"><i class="fa-solid fa-calendar-xmark"></i>Nothing scheduled.</div>
    <?php else: ?>
      <div class="table-wrap"><table>
        <thead><tr><th>Session</th><th>Member</th><th>When</th></tr></thead>
        <tbody>
        <?php foreach ($nextSessions as $s): ?>
          <tr>
            <td><?= e($s['session_name']) ?><div class="mono"><?= e($s['member_name'] ?? 'Open') ?></div></td>
            <td><?= e($s['member_name'] ?? '—') ?></td>
            <td><?= dt($s['session_date']) ?><div class="mono"><?= timeh($s['session_time']) ?></div></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    <?php endif; ?>
  </div>
</div>

<div class="grid c2">
  <div class="panel glass">
    <h2><i class="fa-solid fa-chart-simple"></i> Membership mix</h2>
    <?php foreach ($byType as $t): ?>
      <div style="margin-bottom:14px">
        <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:6px">
          <span><?= e($t['membership_type']) ?></span>
          <span style="color:var(--muted)"><?= (int) $t['n'] ?> member<?= $t['n'] == 1 ? '' : 's' ?></span>
        </div>
        <div class="bar"><span style="width:<?= $maxType ? round($t['n'] / $maxType * 100) : 0 ?>%"></span></div>
      </div>
    <?php endforeach; ?>
    <?php if (!$byType): ?><div class="empty"><i class="fa-solid fa-chart-simple"></i>No members yet.</div><?php endif; ?>
  </div>

  <div class="panel glass">
    <h2><i class="fa-solid fa-clock-rotate-left"></i> Latest activity
      <span class="spacer"></span>
      <a class="btn sm" href="activity-log.php">Full log</a>
    </h2>
    <?php if (!$recentLog): ?>
      <div class="empty"><i class="fa-solid fa-clock"></i>No activity recorded.</div>
    <?php else: ?>
      <div class="timeline">
        <?php foreach ($recentLog as $l): ?>
          <div class="tl-item">
            <div style="font-size:13.5px"><b><?= e($l['action']) ?></b> · <?= e($l['module']) ?></div>
            <div class="note"><?= e($l['details'] ?: $l['email']) ?></div>
            <div class="when"><?= dt($l['created_at'], 'M j, g:i A') ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
