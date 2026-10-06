<?php
define('IN_SUBDIR', true);
require __DIR__ . '/../includes/auth.php';
$u = require_role('member');
$mid = $_SESSION['member_id'] ?? '';

$page = 'idcard';
$title = 'My Member Card';
$subtitle = 'Show this at the desk, or use the number at the check-in station.';

$me = row(
  "SELECT m.*,
      (SELECT p.payment_status FROM payments p
         WHERE p.member_id = m.member_id
         ORDER BY p.created_at DESC LIMIT 1) latest_payment_status
     FROM members m WHERE m.member_id = ?",
  [$mid]
);
$qrAvailable = $me && $me['status'] === 'Active' && $me['latest_payment_status'] === 'Paid';

$hasLogo = is_file(__DIR__ . '/../assets/img/logo.png');
$b = base_url();

include __DIR__ . '/../includes/header.php';
?>

<div class="grid c2">
  <div class="panel glass">
    <h2><i class="fa-solid fa-id-card"></i> Your card
      <span class="spacer"></span>
      <button class="btn sm" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button>
    </h2>

    <div style="display:grid;place-items:center;padding:10px 0 4px">
      <div class="idcard">
        <div class="brandline">
          <?php if ($hasLogo): ?><img src="<?= $b ?>assets/img/logo.png" alt="">
          <?php else: ?><svg viewBox="0 0 100 100" fill="none"><path d="M50 4 92 28v48L50 100 8 76V28z" stroke="#E53935" stroke-width="6"/><path d="M34 30h26M34 50h22M34 70h26" stroke="#E53935" stroke-width="7" stroke-linecap="round"/></svg><?php endif; ?>
          <div><b>BE FIT FLEX GYM</b><span>Laguna</span></div>
        </div>

        <div class="who"><?= e($me['member_name']) ?></div>
        <div class="mid" data-copy="<?= e($me['member_id']) ?>"><?= e($me['member_id']) ?></div>

        <div class="rowline">
          <div>MEMBERSHIP<b><?= e($me['membership_type']) ?></b></div>
          <div>SINCE<b><?= dt($me['join_date'], 'M Y') ?></b></div>
          <div>STATUS<b><?= e($me['status']) ?></b></div>
        </div>

        <div style="display:grid;place-items:center;width:132px;height:132px;margin:18px auto 0;padding:6px;background:#fff;border-radius:4px;position:relative">
          <?php if ($qrAvailable): ?>
            <img src="<?= $b ?>api/qr.php?type=member&amp;id=<?= rawurlencode((string) $mid) ?>" alt="Member check-in QR code" width="120" height="120">
          <?php else: ?>
            <span style="font-size:11px;color:#333;text-align:center">QR available when membership is active and paid</span>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <p class="note" style="text-align:center;margin-top:16px">
      Show this QR code to the gym's check-in scanner. Your membership must be active and paid.
    </p>
  </div>

  <div class="panel glass">
    <h2><i class="fa-solid fa-circle-info"></i> Using the gym</h2>

    <table class="kv" style="width:100%">
      <tr><th>Member number</th><td class="mono"><?= e($me['member_id']) ?></td></tr>
      <tr><th>Plan</th><td><?= e($me['membership_type']) ?></td></tr>
      <tr><th>Status</th><td><?= badge($me['status']) ?></td></tr>
      <tr><th>Member since</th><td><?= dt($me['join_date']) ?></td></tr>
    </table>

    <h2 style="margin-top:26px"><i class="fa-solid fa-location-dot"></i> Branches</h2>
    <?php foreach (rows('SELECT * FROM gyms ORDER BY gym_id') as $g): ?>
      <div class="mini-row">
        <span><b><?= e($g['gym_branch']) ?></b><div class="note"><?= e($g['location']) ?></div></span>
        <span class="mono"><?= e($g['contact_number']) ?></span>
      </div>
    <?php endforeach; ?>

    <p class="note" style="margin-top:18px">
      Bring the card, or just your member number. If you lose access to your
      account, the front desk can look you up by name.
    </p>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
