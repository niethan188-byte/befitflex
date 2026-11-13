<?php
define('IN_SUBDIR', true);
require __DIR__ . '/../includes/auth.php';
$u = require_role('admin');

$paymentsHasCustomerName = (bool) db()->query("SHOW COLUMNS FROM payments LIKE 'customer_name'")->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['action'] ?? '';

    if ($act === 'create') {
        $paymentMethod = (string) ($_POST['payment_method'] ?? '');
        $paymentStatus = $paymentMethod === 'Maya' ? 'Paid' : (string) ($_POST['payment_status'] ?? '');
        $memberId = trim((string) ($_POST['member_id'] ?? ''));
        $customerName = trim((string) ($_POST['customer_name'] ?? ''));
        $member = $memberId !== '' ? row('SELECT member_id, member_name FROM members WHERE member_id = ?', [$memberId]) : null;

        if (!in_array($paymentMethod, ['Cash', 'Maya'], true)) {
            flash('Choose a valid payment method.', 'err');
            redirect('payments.php');
        }
        if ($memberId !== '' && !$member) {
            flash('Choose a valid member from the search results.', 'err');
            redirect('payments.php');
        }
        if ($memberId === '' && !$paymentsHasCustomerName) {
            flash('This installation is missing the non-member payment schema update. Run the database upgrade before recording walk-in payments.', 'err');
            redirect('payments.php');
        }
        if ($memberId === '' && $customerName === '') {
            flash('Choose a member or enter a non-member customer name.', 'err');
            redirect('payments.php');
        }

        $customerName = $customerName !== '' ? $customerName : (string) ($member['member_name'] ?? '');
        $pid = next_id('payments', 'payment_id', 'PAY', 5);
        if ($paymentsHasCustomerName) {
            db()->prepare(
                'INSERT INTO payments (payment_id,member_id,customer_name,amount,payment_method,payment_status,payment_date,notes)
                 VALUES (?,?,?,?,?,?,?,?)'
            )->execute([$pid, $member ? $memberId : null, $customerName, (float) $_POST['amount'], $paymentMethod,
                        $paymentStatus, $_POST['payment_date'] ?: null, $_POST['notes']]);
        } else {
            db()->prepare(
                'INSERT INTO payments (payment_id,member_id,amount,payment_method,payment_status,payment_date,notes)
                 VALUES (?,?,?,?,?,?,?)'
            )->execute([$pid, $memberId, (float) $_POST['amount'], $paymentMethod,
                        $paymentStatus, $_POST['payment_date'] ?: null, $_POST['notes']]);
        }
        log_activity('Record payment', 'Payments', $pid);
        flash('Payment ' . $pid . ' recorded.');
    }

    if ($act === 'mark_paid') {
        if ($paymentsHasCustomerName) {
            $p = row('SELECT p.*, m.user_id, COALESCE(m.member_name, p.customer_name) AS payer_name
                        FROM payments p
                        LEFT JOIN members m ON m.member_id = p.member_id
                        WHERE p.payment_id = ?', [$_POST['payment_id']]);
        } else {
            $p = row('SELECT p.*, m.user_id, m.member_name AS payer_name
                        FROM payments p
                        JOIN members m ON m.member_id = p.member_id
                        WHERE p.payment_id = ?', [$_POST['payment_id']]);
        }
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
            if (!empty($p['user_id'])) {
                notify((int) $p['user_id'], 'payment', 'Payment received',
                    'We received ' . number_format((float) $p['amount'], 2) . ' for your membership. Thank you.',
                    'circle-check', 'success', 'member/payments.php');
            }
            log_activity('Mark paid', 'Payments', (string) $_POST['payment_id']);
            flash('Marked as paid.');
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
$bodyClass = 'admin-payments';

$filter = $_GET['status'] ?? 'all';
if ($paymentsHasCustomerName) {
    $sql = 'SELECT p.*, COALESCE(m.member_name, p.customer_name) AS payer_name
            FROM payments p
            LEFT JOIN members m ON m.member_id = p.member_id';
} else {
    $sql = 'SELECT p.*, m.member_name AS payer_name
            FROM payments p
            JOIN members m ON m.member_id = p.member_id';
}
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
    <div class="label">Collected</div><div class="value payment-stat-value"><?= money($paid) ?></div>
    <div class="note">Settled payments</div></div>
  <div class="stat glass hover"><div class="ico"><i class="fa-solid fa-hourglass-half"></i></div>
    <div class="label">Pending</div><div class="value payment-stat-value"><?= money($pending) ?></div>
    <div class="note">Awaiting settlement</div></div>
  <div class="stat glass hover"><div class="ico"><i class="fa-solid fa-triangle-exclamation"></i></div>
    <div class="label">Overdue</div><div class="value payment-stat-value"><?= money($overdue) ?></div>
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
    <button class="btn red sm" data-shortcut="new" onclick="openPaymentModal()"><i class="fa-solid fa-plus"></i> Record payment</button>
  </h2>

  <?php if (!$payments): ?>
    <div class="empty"><i class="fa-solid fa-receipt"></i>Nothing here.</div>
  <?php else: ?>
  <div class="table-wrap"><table id="tblPay" class="payment-table"
      data-page-size-options="10,25,50,100">
    <thead><tr><th>Payment</th><th>Member</th><th>Method</th><th class="num">Amount</th><th>Date</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($payments as $p): ?>
      <tr>
        <td><span class="mono"><?= e($p['payment_id']) ?></span>
            <?php if ($p['notes']): ?><div class="note"><?= e($p['notes']) ?></div><?php endif; ?></td>
        <td><?= e($p['payer_name'] ?: $p['customer_name'] ?: 'Walk-in customer') ?>
            <?php if (!empty($p['member_id'])): ?><div class="mono"><?= e($p['member_id']) ?></div><?php endif; ?>
            <?php if (empty($p['member_id']) && !empty($p['customer_name'])): ?><div class="mono">Non-member</div><?php endif; ?></td>
        <td><?= e($p['payment_method']) ?></td>
        <td class="num"><b><?= money($p['amount']) ?></b></td>
        <td><?= dt($p['payment_date']) ?></td>
        <td><?= badge($p['payment_status']) ?></td>
        <td class="payment-actions" data-label="Actions">
          <a class="btn sm" href="../invoice.php?payment_id=<?= urlencode($p['payment_id']) ?>"><i class="fa-solid fa-file-invoice"></i> View receipt</a>
          <?php if ($p['payment_status'] !== 'Paid'): ?>
            <?php if (!empty($p['maya_checkout_id'])): ?>
              <a class="btn sm" href="../api/maya-return.php?payment_id=<?= urlencode($p['payment_id']) ?>" title="Check Maya status"><i class="fa-solid fa-rotate"></i> Check status</a>
              <?php if (maya_payment_flow_configured()): ?>
                <form method="post" action="../api/maya-retry.php">
                  <?= csrf_field() ?>
                  <input type="hidden" name="payment_id" value="<?= e($p['payment_id']) ?>">
                  <button class="btn sm red" type="submit" title="Retry Maya payment"><i class="fa-solid fa-wallet"></i> Retry payment</button>
                </form>
              <?php endif; ?>
            <?php endif; ?>
            <?php if (empty($p['maya_checkout_id']) && $p['payment_method'] === 'Maya'): ?>
              <span class="note" title="No checkout ID is saved, so Maya status cannot be checked. Ask for the receipt and verify it in Maya before taking further action.">No saved Maya checkout ID; verify receipt with Maya</span>
            <?php endif; ?>
            <?php if (empty($p['maya_checkout_id'])): ?>
              <details class="maya-verify">
                <summary class="btn sm">Link existing Maya checkout</summary>
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
              <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="mark_paid">
                <input type="hidden" name="payment_id" value="<?= e($p['payment_id']) ?>">
                <button class="btn sm" title="Mark as paid"><i class="fa-solid fa-check"></i></button>
              </form>
            <?php endif; ?>
          <?php endif; ?>
          <form method="post" onsubmit="return confirm('Delete this payment record?')">
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

      <div class="field-row member-picker" data-member-picker>
        <label for="paymentMemberSearch">Member (optional)</label>
        <input type="search" id="paymentMemberSearch" placeholder="Search by name or member ID…"
               autocomplete="off" aria-autocomplete="list"
               aria-controls="paymentMemberResults" aria-expanded="false">
        <input type="hidden" name="member_id" id="paymentMemberId">
        <div class="member-picker-results" id="paymentMemberResults" role="listbox" hidden></div>
        <small class="member-picker-hint">Pick an existing member, or leave this blank for a non-member customer.</small>
        <div class="member-picker-options" hidden>
          <?php foreach ($members as $m): ?>
            <span data-member-id="<?= e($m['member_id']) ?>"
                  data-member-name="<?= e($m['member_name']) ?>"
                  data-price="<?= e((string) (MEMBERSHIP_PRICE[$m['membership_type']] ?? 0)) ?>"
                  data-membership="<?= e($m['membership_type']) ?>"></span>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="field-row">
        <label for="paymentCustomerName">Customer name for non-member payment</label>
        <input type="text" name="customer_name" id="paymentCustomerName" placeholder="Walk-in customer or guest">
      </div>

      <div class="form-grid">
        <div class="field-row"><label>Amount</label>
          <input type="number" name="amount" id="payAmount" step="0.01" min="0" required></div>
        <div class="field-row"><label>Method</label>
          <select name="payment_method" id="payMethod">
            <option>Cash</option><option>Maya</option>
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
function openPaymentModal() {
  const form = document.querySelector('#mPay form');
  form.reset();
  document.getElementById('paymentMemberId').value = '';
  openModal('mPay');
  window.setTimeout(() => document.getElementById('paymentMemberSearch').focus(), 0);
}

(() => {
  const paymentMethod = document.getElementById('payMethod');
  const paymentStatus = document.getElementById('payStatus');
  const updatePaymentStatus = () => {
    const mayaSelected = paymentMethod.value === 'Maya';
    if (mayaSelected) paymentStatus.value = 'Paid';
    paymentStatus.disabled = mayaSelected;
  };
  paymentMethod.addEventListener('change', updatePaymentStatus);
  paymentMethod.form.addEventListener('reset', () => window.setTimeout(updatePaymentStatus));
  updatePaymentStatus();

  const picker = document.querySelector('[data-member-picker]');
  if (!picker) return;

  const input = picker.querySelector('#paymentMemberSearch');
  const memberId = picker.querySelector('#paymentMemberId');
  const results = picker.querySelector('#paymentMemberResults');
  const amount = document.getElementById('payAmount');
  const customerName = document.getElementById('paymentCustomerName');
  const members = Array.from(picker.querySelectorAll('.member-picker-options [data-member-id]'))
    .map(option => ({
      id: option.dataset.memberId,
      name: option.dataset.memberName,
      price: option.dataset.price,
      membership: option.dataset.membership,
      label: `${option.dataset.memberName} · ${option.dataset.memberId}`
    }));
  let matches = [];
  let activeIndex = -1;

  const closeResults = () => {
    results.hidden = true;
    input.setAttribute('aria-expanded', 'false');
    input.removeAttribute('aria-activedescendant');
    activeIndex = -1;
  };

  const chooseMember = member => {
    input.value = member.label;
    memberId.value = member.id;
    customerName.value = member.name;
    input.setCustomValidity('');
    if (member.price !== '') amount.value = member.price;
    closeResults();
  };

  const renderResults = () => {
    const query = input.value.trim().toLocaleLowerCase();
    memberId.value = '';
    input.setCustomValidity(query ? 'Choose a member from the search results.' : '');
    results.replaceChildren();
    activeIndex = -1;

    if (!query) {
      closeResults();
      return;
    }

    matches = members.filter(member =>
      `${member.name} ${member.id}`.toLocaleLowerCase().includes(query)
    ).slice(0, 8);

    if (!matches.length) {
      const empty = document.createElement('div');
      empty.className = 'member-picker-empty';
      empty.textContent = 'No matching members.';
      results.appendChild(empty);
    } else {
      matches.forEach((member, index) => {
        const option = document.createElement('button');
        option.type = 'button';
        option.className = 'member-picker-option';
        option.id = `paymentMemberOption${index}`;
        option.setAttribute('role', 'option');
        option.setAttribute('aria-selected', 'false');

        const name = document.createElement('span');
        name.textContent = member.name;
        const details = document.createElement('small');
        details.textContent = `${member.id} · ${member.membership}`;
        option.append(name, details);
        option.addEventListener('click', () => chooseMember(member));
        results.appendChild(option);
      });
    }

    results.hidden = false;
    input.setAttribute('aria-expanded', 'true');
  };

  input.addEventListener('input', renderResults);
  input.addEventListener('keydown', event => {
    if (results.hidden) return;
    if (event.key === 'ArrowDown' && matches.length) {
      event.preventDefault();
      activeIndex = (activeIndex + 1) % matches.length;
    } else if (event.key === 'ArrowUp' && matches.length) {
      event.preventDefault();
      activeIndex = (activeIndex - 1 + matches.length) % matches.length;
    } else if (event.key === 'Enter' && activeIndex >= 0) {
      event.preventDefault();
      chooseMember(matches[activeIndex]);
      return;
    } else if (event.key === 'Escape') {
      closeResults();
      return;
    } else {
      return;
    }

    Array.from(results.querySelectorAll('[role="option"]')).forEach((option, index) => {
      const active = index === activeIndex;
      option.classList.toggle('active', active);
      option.setAttribute('aria-selected', String(active));
    });
    input.setAttribute('aria-activedescendant', `paymentMemberOption${activeIndex}`);
  });

  input.form.addEventListener('submit', event => {
    if (!memberId.value && !customerName.value.trim()) {
      event.preventDefault();
      input.setCustomValidity('Choose a member or enter a non-member customer name.');
      input.reportValidity();
    }
  });

  input.form.addEventListener('reset', () => {
    window.setTimeout(() => {
      memberId.value = '';
      customerName.value = '';
      input.setCustomValidity('');
      closeResults();
    });
  });

  document.addEventListener('click', event => {
    if (!picker.contains(event.target)) closeResults();
  });
})();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
