<?php
define('IN_SUBDIR', true);
require __DIR__ . '/../includes/auth.php';
$u = require_role('admin');

$page = 'reports';
$title = 'Reports';
$subtitle = 'Revenue, retention and utilisation.';

$monthly = rows(
    "SELECT DATE_FORMAT(payment_date,'%Y-%m') ym, SUM(amount) total, COUNT(*) n
       FROM payments WHERE payment_status='Paid' AND payment_date IS NOT NULL
      GROUP BY ym ORDER BY ym DESC LIMIT 12"
);
$maxMonth = max(array_map(fn($r) => (float) $r['total'], $monthly ?: [['total' => 1]]));

$byMethod = rows(
    "SELECT payment_method, COUNT(*) n, SUM(amount) total
       FROM payments WHERE payment_status='Paid' GROUP BY payment_method ORDER BY total DESC"
);

$joins = rows(
    "SELECT DATE_FORMAT(join_date,'%Y-%m') ym, COUNT(*) n
       FROM members GROUP BY ym ORDER BY ym DESC LIMIT 8"
);

$topMembers = rows(
    'SELECT m.member_name, m.membership_type,
            COALESCE(SUM(CASE WHEN p.payment_status="Paid" THEN p.amount END),0) paid,
            (SELECT COUNT(*) FROM attendance a WHERE a.member_id = m.member_id) visits
       FROM members m LEFT JOIN payments p ON p.member_id = m.member_id
      GROUP BY m.member_id ORDER BY paid DESC LIMIT 8'
);

$statusMix = rows('SELECT status, COUNT(*) n FROM members GROUP BY status');
$totalM = max(1, (int) scalar('SELECT COUNT(*) FROM members'));

include __DIR__ . '/../includes/header.php';
?>

<div class="stats">
  <div class="stat glass hover"><div class="ico"><i class="fa-solid fa-peso-sign"></i></div>
    <div class="label">Lifetime revenue</div>
    <div class="value" style="font-size:25px"><?= money(scalar("SELECT COALESCE(SUM(amount),0) FROM payments WHERE payment_status='Paid'")) ?></div>
    <div class="note">All settled payments</div></div>
  <div class="stat glass hover"><div class="ico"><i class="fa-solid fa-arrow-trend-up"></i></div>
    <div class="label">This month</div>
    <div class="value" style="font-size:25px"><?= money(scalar("SELECT COALESCE(SUM(amount),0) FROM payments WHERE payment_status='Paid' AND DATE_FORMAT(payment_date,'%Y-%m') = DATE_FORMAT(CURDATE(),'%Y-%m')")) ?></div>
    <div class="note"><?= date('F Y') ?></div></div>
  <div class="stat glass hover"><div class="ico"><i class="fa-solid fa-user-check"></i></div>
    <div class="label">Retention</div>
    <div class="value"><?= round((int) scalar("SELECT COUNT(*) FROM members WHERE status='Active'") / $totalM * 100) ?>%</div>
    <div class="note">Active over total members</div></div>
  <div class="stat glass hover"><div class="ico"><i class="fa-solid fa-repeat"></i></div>
    <div class="label">Avg visits</div>
    <div class="value"><?= round((float) scalar('SELECT COUNT(*) FROM attendance') / $totalM, 1) ?></div>
    <div class="note">Per member, all time</div></div>
</div>

<div class="grid c2">
  <div class="panel glass">
    <h2><i class="fa-solid fa-chart-column"></i> Revenue by month</h2>
    <?php if (!$monthly): ?><div class="empty"><i class="fa-solid fa-chart-column"></i>No settled payments yet.</div><?php else: ?>
      <?php foreach ($monthly as $m): ?>
        <div style="margin-bottom:14px">
          <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:6px">
            <span><?= date('F Y', strtotime($m['ym'] . '-01')) ?></span>
            <span style="color:var(--muted)"><?= money($m['total']) ?> · <?= (int) $m['n'] ?> payment<?= $m['n'] == 1 ? '' : 's' ?></span>
          </div>
          <div class="bar"><span style="width:<?= $maxMonth ? round($m['total'] / $maxMonth * 100) : 0 ?>%"></span></div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <div class="panel glass">
    <h2><i class="fa-solid fa-credit-card"></i> How members pay</h2>
    <?php if (!$byMethod): ?><div class="empty"><i class="fa-solid fa-credit-card"></i>No data.</div><?php else: ?>
    <div class="table-wrap"><table>
      <thead><tr><th>Method</th><th class="num">Payments</th><th class="num">Total</th></tr></thead>
      <tbody>
      <?php foreach ($byMethod as $m): ?>
        <tr><td><?= e($m['payment_method']) ?></td>
            <td class="num"><?= (int) $m['n'] ?></td>
            <td class="num"><b><?= money($m['total']) ?></b></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>

    <h2 style="margin-top:24px"><i class="fa-solid fa-user-group"></i> Member status</h2>
    <?php foreach ($statusMix as $s): ?>
      <div style="margin-bottom:12px">
        <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:6px">
          <span><?= badge($s['status']) ?></span>
          <span style="color:var(--muted)"><?= (int) $s['n'] ?> · <?= round($s['n'] / $totalM * 100) ?>%</span>
        </div>
        <div class="bar"><span style="width:<?= round($s['n'] / $totalM * 100) ?>%"></span></div>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<div class="grid c2">
  <div class="panel glass">
    <h2><i class="fa-solid fa-medal"></i> Top members by contribution</h2>
    <div class="table-wrap"><table>
      <thead><tr><th>Member</th><th>Plan</th><th class="num">Paid</th><th class="num">Visits</th></tr></thead>
      <tbody>
      <?php foreach ($topMembers as $m): ?>
        <tr>
          <td><?= e($m['member_name']) ?></td>
          <td><?= e($m['membership_type']) ?></td>
          <td class="num"><b><?= money($m['paid']) ?></b></td>
          <td class="num"><?= (int) $m['visits'] ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
</div>

<div class="panel glass">
  <h2><i class="fa-solid fa-user-plus"></i> New members by month
    <span class="spacer"></span>
    <button class="btn sm" onclick="window.print()"><i class="fa-solid fa-print"></i> Print report</button>
  </h2>
  <div class="table-wrap"><table>
    <thead><tr><th>Month</th><th class="num">Joined</th></tr></thead>
    <tbody>
    <?php foreach ($joins as $j): ?>
      <tr><td><?= date('F Y', strtotime($j['ym'] . '-01')) ?></td><td class="num"><?= (int) $j['n'] ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
