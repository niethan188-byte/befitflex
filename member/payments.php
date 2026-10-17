<?php
define('IN_SUBDIR', true);
require __DIR__ . '/../includes/auth.php';
$u = require_role('member');
$mid = $_SESSION['member_id'] ?? '';

$page = 'payments';
$bodyClass = 'member-payments';
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
  <p class="note">Pay at the front desk in cash or by card, or use Maya's secure online checkout
  below. Online payments are recorded after Maya confirms the transaction.</p>
  <?php if (!maya_payment_flow_configured()): ?>
  <p class="note">Online Maya checkout is unavailable until the Maya public and secret API keys
  are configured and PHP cURL is enabled. Payments will remain pending without server-side verification.</p>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="panel glass payment-history-panel">
  <h2><i class="fa-solid fa-receipt"></i> Payment history
  </h2>
  <?php if (!$payments): ?>
    <div class="empty"><i class="fa-solid fa-receipt"></i>No payment records yet.</div>
  <?php else: ?>
  <div class="table-wrap"><table class="stackable payment-history-table" aria-label="Payment history">
    <thead><tr><th>Reference</th><th>For</th><th>Method</th><th class="num">Amount</th><th>Date</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($payments as $p): ?>
      <tr>
        <td class="mono" data-label="Reference"><?= e($p['payment_id']) ?></td>
        <td data-label="For"><?= e($p['notes'] ?: 'Membership dues') ?></td>
        <td data-label="Method"><?= e($p['payment_method']) ?></td>
        <td class="num" data-label="Amount"><b><?= money($p['amount']) ?></b></td>
        <td data-label="Date"><?= dt($p['payment_date']) ?></td>
        <td data-label="Status"><?= badge($p['payment_status']) ?></td>
        <td data-label="Actions">
          <a class="btn sm" href="../invoice.php?payment_id=<?= urlencode($p['payment_id']) ?>"><i class="fa-solid fa-file-invoice"></i> View invoice</a>
          <?php if ($p['payment_status'] !== 'Paid'): ?>
            <?php if (!empty($p['maya_checkout_id'])): ?>
            <a class="btn sm" href="../api/maya-return.php?payment_id=<?= urlencode($p['payment_id']) ?>"><i class="fa-solid fa-rotate"></i> Check Maya status</a>
            <?php if (maya_payment_flow_configured()): ?>
            <form method="post" action="../api/maya-retry.php" class="payment-action">
              <?= csrf_field() ?><input type="hidden" name="payment_id" value="<?= e($p['payment_id']) ?>">
              <button class="btn sm red" type="submit"><i class="fa-solid fa-wallet"></i> Retry Maya payment</button>
            </form>
            <?php endif; ?>
            <?php elseif ($p['payment_method'] === 'Maya'): ?>
            <span class="note">No Maya checkout ID is saved, so this payment cannot be checked safely. Do not pay again; contact the gym with your Maya receipt for verification.</span>
            <?php else: ?>
            <span class="note">No Maya checkout ID is saved, so this page cannot check an older session. If you already paid, do not start another checkout; contact the gym with your receipt. Staff can enter an existing checkout ID from the receipt or Maya dashboard for server-side verification.</span>
            <?php endif; ?>
            <?php if (maya_payment_flow_configured() && empty($p['maya_checkout_id']) && $p['payment_method'] !== 'Maya'): ?>
            <form method="post" action="../api/maya-checkout.php" class="payment-action">
              <?= csrf_field() ?><input type="hidden" name="payment_id" value="<?= e($p['payment_id']) ?>">
              <button class="btn sm red" type="submit"><i class="fa-solid fa-wallet"></i> Pay online with Maya</button>
            </form>
            <?php elseif (!maya_payment_flow_configured() && empty($p['maya_checkout_id']) && $p['payment_method'] !== 'Maya'): ?>
            <span class="note">Online checkout unavailable until Maya verification is configured</span>
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
