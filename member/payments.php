<?php
define('IN_SUBDIR', true);
require __DIR__ . '/../includes/auth.php';
$u = require_role('member');
$mid = $_SESSION['member_id'] ?? '';

$page = 'payments';
$title = 'Payments';
$subtitle = 'Your dues and payment history.';

$me = row('SELECT * FROM members WHERE member_id = ?', [$mid]);
$payments = rows('SELECT * FROM payments WHERE member_id = ? ORDER BY created_at DESC', [$mid]);

$paid    = (float) scalar("SELECT COALESCE(SUM(amount),0) FROM payments WHERE member_id = ? AND payment_status='Paid'", [$mid]);
$pending = (float) scalar("SELECT COALESCE(SUM(amount),0) FROM payments WHERE member_id = ? AND payment_status='Pending'", [$mid]);
$overdue = (float) scalar("SELECT COALESCE(SUM(amount),0) FROM payments WHERE member_id = ? AND payment_status='Overdue'", [$mid]);

include __DIR__ . '/../includes/header.php';
?>

<div class="stats">
  <div class="stat glass hover"><div class="ico"><i class="fa-solid fa-circle-check"></i></div>
    <div class="label">Paid to date</div><div class="value" style="font-size:25px"><?= money($paid) ?></div>
    <div class="note">Thank you</div></div>
  <div class="stat glass hover"><div class="ico"><i class="fa-solid fa-hourglass-half"></i></div>
    <div class="label">Pending</div><div class="value" style="font-size:25px"><?= money($pending) ?></div>
    <div class="note">Not yet settled</div></div>
  <div class="stat glass hover"><div class="ico"><i class="fa-solid fa-triangle-exclamation"></i></div>
    <div class="label">Overdue</div><div class="value" style="font-size:25px"><?= money($overdue) ?></div>
    <div class="note">Please settle soon</div></div>
  <div class="stat glass hover"><div class="ico"><i class="fa-solid fa-id-card"></i></div>
    <div class="label">Your plan</div><div class="value" style="font-size:22px"><?= e($me['membership_type'] ?? '—') ?></div>
    <div class="note"><?= money(MEMBERSHIP_PRICE[$me['membership_type']] ?? 0) ?> per term</div></div>
</div>

<?php if ($pending + $overdue > 0): ?>
<div class="panel glass" style="border-color:rgba(245,158,11,.4)">
  <h2><i class="fa-solid fa-circle-info"></i> How to settle</h2>
  <p class="note">Pay at the front desk in cash or by card, or use GCash through the secure Maya
  checkout below when it is enabled for this merchant account. Online payments are recorded after
  Maya confirms the transaction.</p>
</div>
<?php endif; ?>

<div class="panel glass">
  <h2><i class="fa-solid fa-receipt"></i> Payment history
    <span class="spacer"></span>
    <button class="btn sm" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button>
  </h2>
  <?php if (!$payments): ?>
    <div class="empty"><i class="fa-solid fa-receipt"></i>No payment records yet.</div>
  <?php else: ?>
  <div class="table-wrap"><table>
    <thead><tr><th>Reference</th><th>For</th><th>Method</th><th class="num">Amount</th><th>Date</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($payments as $p): ?>
      <tr>
        <td class="mono"><?= e($p['payment_id']) ?></td>
        <td><?= e($p['notes'] ?: 'Membership dues') ?></td>
        <td><?= e($p['payment_method']) ?></td>
        <td class="num"><b><?= money($p['amount']) ?></b></td>
        <td><?= dt($p['payment_date']) ?></td>
        <td><?= badge($p['payment_status']) ?></td>
        <td style="white-space:nowrap">
          <a class="btn sm" href="../invoice.php?payment_id=<?= urlencode($p['payment_id']) ?>"><i class="fa-solid fa-file-pdf"></i> Invoice</a>
          <?php if ($p['payment_status'] !== 'Paid'): ?>
            <?php if (maya_configured()): ?>
            <form method="post" action="../api/maya-checkout.php" style="display:inline">
              <?= csrf_field() ?><input type="hidden" name="payment_id" value="<?= e($p['payment_id']) ?>">
              <button class="btn sm red" type="submit"><i class="fa-solid fa-wallet"></i> Pay with GCash</button>
            </form>
            <?php else: ?>
            <span class="note">Online checkout unavailable</span>
            <?php endif; ?>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
