<?php
define('IN_SUBDIR', true);
require __DIR__ . '/../includes/auth.php';
$u = require_role('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['action'] ?? '';

    if ($act === 'create') {
        $paymentMethod = (string) ($_POST['payment_method'] ?? '');
        $paymentStatus = (string) ($_POST['payment_status'] ?? '');
        if (!maya_manual_payment_creation_allowed($paymentMethod, $paymentStatus)) {
            flash('Maya payments cannot be recorded as paid manually. Save the payment as Pending and wait for Maya server-side confirmation.', 'err');
            redirect('payments.php');
        }

        $pid = next_id('payments', 'payment_id', 'PAY', 5);
        db()->prepare(
            'INSERT INTO payments (payment_id,member_id,amount,payment_method,payment_status,payment_date,notes)
             VALUES (?,?,?,?,?,?,?)'
        )->execute([$pid, $_POST['member_id'], (float) $_POST['amount'], $paymentMethod,
                    $paymentStatus, $_POST['payment_date'] ?: null, $_POST['notes']]);
        log_activity('Record payment', 'Payments', $pid);
        flash('Payment ' . $pid . ' recorded.');
    }

    if ($act === 'mark_paid') {
        $p = row('SELECT p.*, m.user_id, m.member_name FROM payments p
                    JOIN members m ON m.member_id = p.member_id WHERE p.payment_id = ?', [$_POST['payment_id']]);
        if (!$p) {
            flash('Payment record not found.', 'err');
        } elseif (!maya_manual_mark_allowed($p)) {
            flash('Maya-linked payments must be confirmed by Maya. Use Check Maya status; this payment was not changed.', 'err');
        } else {
            $stmt = db()->prepare(
                "UPDATE payments SET payment_status='Paid', payment_date=CURDATE()
                  WHERE payment_id = ? AND payment_status <> 'Paid'
                    AND payment_method <> 'Maya'
                    AND (maya_checkout_id IS NULL OR maya_checkout_id = '')"
            );
            $stmt->execute([$_POST['payment_id']]);
            if ($stmt->rowCount() !== 1) {
                flash('The payment changed before it could be recorded. Refresh and check its current status.', 'err');
                redirect('payments.php');
            }
            notify((int) $p['user_id'], 'payment', 'Payment received',
                'We received ' . number_format((float) $p['amount'], 2) . ' for your membership. Thank you.',
                'circle-check', 'success', 'member/payments.php');
            log_activity('Mark paid', 'Payments', (string) $_POST['payment_id']);
            flash('Marked as paid and the member was notified.');
        }
    }

    if ($act === 'delete') {
        db()->prepare('DELETE FROM payments WHERE payment_id = ?')->execute([$_POST['payment_id']]);
        log_activity('Delete payment', 'Payments', (string) $_POST['payment_id']);
        flash('Payment record deleted.');
    }

    redirect('payments.php');
}

$page = 'payments';
$title = 'Payments';
$subtitle = 'Dues, methods and outstanding balances.';

$filter = $_GET['status'] ?? 'all';
$sql = 'SELECT p.*, m.member_name FROM payments p JOIN members m ON m.member_id = p.member_id';
$args = [];
if (in_array($filter, ['Paid', 'Pending', 'Overdue'], true)) {
    $sql .= ' WHERE p.payment_status = ?';
    $args[] = $filter;
}
$sql .= ' ORDER BY (p.payment_status <> "Overdue"), p.created_at DESC';
$payments = rows($sql, $args);

$paid    = (float) scalar("SELECT COALESCE(SUM(amount),0) FROM payments WHERE payment_status='Paid'");
$pending = (float) scalar("SELECT COALESCE(SUM(amount),0) FROM payments WHERE payment_status='Pending'");
$overdue = (float) scalar("SELECT COALESCE(SUM(amount),0) FROM payments WHERE payment_status='Overdue'");
$members = rows('SELECT member_id, member_name, membership_type FROM members ORDER BY member_name');

include __DIR__ . '/../includes/header.php';
?>

<div class="stats">
  <div class="stat glass hover"><div class="ico"><i class="fa-solid fa-circle-check"></i></div>
    <div class="label">Collected</div><div class="value" style="font-size:26px"><?= money($paid) ?></div>
    <div class="note">Settled payments</div></div>
  <div class="stat glass hover"><div class="ico"><i class="fa-solid fa-hourglass-half"></i></div>
    <div class="label">Pending</div><div class="value" style="font-size:26px"><?= money($pending) ?></div>
    <div class="note">Awaiting settlement</div></div>
  <div class="stat glass hover"><div class="ico"><i class="fa-solid fa-triangle-exclamation"></i></div>
    <div class="label">Overdue</div><div class="value" style="font-size:26px"><?= money($overdue) ?></div>
    <div class="note">Needs follow-up</div></div>
</div>

<div class="tabs">
  <?php foreach (['all' => 'All', 'Paid' => 'Paid', 'Pending' => 'Pending', 'Overdue' => 'Overdue'] as $k => $lbl): ?>
    <a class="<?= $filter === $k ? 'on' : '' ?>" href="?status=<?= $k ?>"><?= $lbl ?></a>
  <?php endforeach; ?>
</div>

<div class="panel glass">
  <h2><i class="fa-solid fa-money-bill-wave"></i> <?= count($payments) ?> record<?= count($payments) === 1 ? '' : 's' ?>
    <span class="spacer"></span>
    <input type="search" placeholder="Filter…" data-filter-for="tblPay" style="width:200px">
    <button class="btn red sm" data-shortcut="new" onclick="openModal('mPay')"><i class="fa-solid fa-plus"></i> Record payment</button>
  </h2>

  <?php if (!$payments): ?>
    <div class="empty"><i class="fa-solid fa-receipt"></i>Nothing here.</div>
  <?php else: ?>
  <div class="table-wrap"><table id="tblPay">
    <thead><tr><th>Payment</th><th>Member</th><th>Method</th><th class="num">Amount</th><th>Date</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($payments as $p): ?>
      <tr>
        <td><span class="mono"><?= e($p['payment_id']) ?></span>
            <?php if ($p['notes']): ?><div class="note"><?= e($p['notes']) ?></div><?php endif; ?></td>
        <td><?= e($p['member_name']) ?><div class="mono"><?= e($p['member_id']) ?></div></td>
        <td><?= e($p['payment_method']) ?></td>
        <td class="num"><b><?= money($p['amount']) ?></b></td>
        <td><?= dt($p['payment_date']) ?></td>
        <td><?= badge($p['payment_status']) ?></td>
        <td style="white-space:nowrap">
          <?php if ($p['payment_status'] !== 'Paid'): ?>
            <?php if (!empty($p['maya_checkout_id'])): ?>
              <a class="btn sm" href="../api/maya-return.php?payment_id=<?= urlencode($p['payment_id']) ?>" title="Check Maya status"><i class="fa-solid fa-rotate"></i></a>
              <?php if (maya_payment_flow_configured()): ?>
                <form method="post" action="../api/maya-retry.php" style="display:inline">
                  <?= csrf_field() ?>
                  <input type="hidden" name="payment_id" value="<?= e($p['payment_id']) ?>">
                  <button class="btn sm red" type="submit" title="Retry Maya payment"><i class="fa-solid fa-wallet"></i></button>
                </form>
              <?php endif; ?>
            <?php endif; ?>
            <?php if (empty($p['maya_checkout_id']) && $p['payment_method'] === 'Maya'): ?>
              <span class="note" title="No checkout ID is saved, so Maya status cannot be checked. Ask for the receipt and verify it in Maya before taking further action.">No saved Maya checkout ID; verify receipt with Maya</span>
            <?php endif; ?>
            <?php if (empty($p['maya_checkout_id'])): ?>
              <details style="display:inline-block">
                <summary class="btn sm">Verify existing Maya checkout</summary>
                <p class="note">Enter the checkout ID from the member's receipt or Maya merchant dashboard. The server will verify its payment reference, amount, and currency before linking it.</p>
                <?php if (maya_payment_flow_configured()): ?>
                  <form method="post" action="../api/maya-recover.php" class="payment-action">
                    <?= csrf_field() ?>
                    <input type="hidden" name="payment_id" value="<?= e($p['payment_id']) ?>">
                    <input type="text" name="checkout_id" maxlength="100" required placeholder="Maya checkout ID">
                    <button class="btn sm" type="submit">Verify and link</button>
                  </form>
                <?php else: ?>
                  <p class="note">Configure both Maya API keys and PHP cURL before trying to verify a checkout.</p>
                <?php endif; ?>
              </details>
            <?php endif; ?>
            <?php if (!empty($p['maya_checkout_id']) || $p['payment_method'] === 'Maya'): ?>
              <span class="note" title="Maya-linked payments can only be confirmed by Maya.">Maya confirmation required</span>
            <?php else: ?>
              <form method="post" style="display:inline">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="mark_paid">
                <input type="hidden" name="payment_id" value="<?= e($p['payment_id']) ?>">
                <button class="btn sm" title="Mark as paid"><i class="fa-solid fa-check"></i></button>
              </form>
            <?php endif; ?>
          <?php endif; ?>
          <form method="post" style="display:inline" onsubmit="return confirm('Delete this payment record?')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="payment_id" value="<?= e($p['payment_id']) ?>">
            <button class="btn sm icon danger"><i class="fa-solid fa-trash"></i></button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>

<div class="modal" id="mPay">
  <div class="modal-bg"></div>
  <div class="modal-box">
    <h3><i class="fa-solid fa-receipt"></i> Record a payment</h3>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="create">

      <div class="field-row"><label>Member</label>
        <select name="member_id" required id="paySelect" onchange="suggest()">
          <option value="">— choose a member —</option>
          <?php foreach ($members as $m): ?>
            <option value="<?= e($m['member_id']) ?>" data-price="<?= MEMBERSHIP_PRICE[$m['membership_type']] ?? 0 ?>">
              <?= e($m['member_name']) ?> — <?= e($m['membership_type']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-grid">
        <div class="field-row"><label>Amount</label>
          <input type="number" name="amount" id="payAmount" step="0.01" min="0" required></div>
        <div class="field-row"><label>Method</label>
          <select name="payment_method">
            <option>Cash</option><option>Card</option><option>GCash</option><option>Bank Transfer</option><option>Maya</option>
          </select></div>
      </div>

      <div class="form-grid">
        <div class="field-row"><label>Status</label>
          <select name="payment_status" id="payStatus">
            <option>Paid</option><option>Pending</option><option>Overdue</option>
          </select></div>
        <div class="field-row"><label>Payment date</label>
          <input type="date" name="payment_date" value="<?= date('Y-m-d') ?>"></div>
      </div>

      <div class="field-row"><label>Notes</label>
        <input type="text" name="notes" placeholder="August dues"></div>

      <div class="modal-actions">
        <button type="button" class="btn" onclick="closeModal('mPay')">Cancel</button>
        <button class="btn red"><i class="fa-solid fa-check"></i> Save payment</button>
      </div>
    </form>
  </div>
</div>

<script>
function suggest() {
  const opt = document.getElementById('paySelect').selectedOptions[0];
  if (opt && opt.dataset.price) document.getElementById('payAmount').value = opt.dataset.price;
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
