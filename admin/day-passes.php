<?php
define('IN_SUBDIR', true);
require __DIR__ . '/../includes/auth.php';
$u = require_role('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = (string) ($_POST['action'] ?? '');

    if ($act === 'create') {
        $name = trim((string) ($_POST['visitor_name'] ?? ''));
        $contact = trim((string) ($_POST['contact_number'] ?? ''));
        $date = (string) ($_POST['visit_date'] ?? date('Y-m-d'));
        $amount = (float) ($_POST['amount'] ?? DAY_PASS_PRICE);
        if ($name === '' || $contact === '' || $date < date('Y-m-d') || $amount < 0) {
            flash('Enter a valid visitor, contact number, date and amount.', 'err');
        } else {
            $passId = next_id('day_passes', 'pass_id', 'DAY', 5);
            db()->prepare(
                'INSERT INTO day_passes (pass_id,visitor_name,contact_number,visit_date,amount,payment_method,pass_status,notes)
                 VALUES (?,?,?,?,?,?,?,?)'
            )->execute([$passId, $name, $contact, $date, $amount, $_POST['payment_method'], $_POST['pass_status'], trim((string) ($_POST['notes'] ?? ''))]);
            log_activity('Create day pass', 'Day Passes', $passId . ' - ' . $name);
            flash('Day pass ' . $passId . ' created. Its QR code is ready to share.');
        }
    }

    if ($act === 'cancel') {
        db()->prepare("UPDATE day_passes SET pass_status='Cancelled' WHERE pass_id=? AND visit_date >= CURDATE()")
            ->execute([(string) $_POST['pass_id']]);
        log_activity('Cancel day pass', 'Day Passes', (string) $_POST['pass_id']);
        flash('Day pass cancelled.');
    }

    redirect('day-passes.php');
}

$page = 'day-passes';
$title = 'Day Passes';
$subtitle = 'One-day access for walk-in customers without a membership.';

$passes = rows(
    'SELECT d.*, a.check_in_time, a.check_out_time
       FROM day_passes d
       LEFT JOIN attendance a ON a.day_pass_id = d.pass_id
      WHERE d.visit_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
      ORDER BY d.visit_date DESC, d.created_at DESC'
);

include __DIR__ . '/../includes/header.php';
?>

<div class="panel glass">
  <h2><i class="fa-solid fa-ticket"></i> One-day access
    <span class="spacer"></span>
    <button class="btn red sm" onclick="openModal('mDayPass')"><i class="fa-solid fa-plus"></i> New day pass</button>
  </h2>
  <p class="note">After payment, open or download the QR below and share it with the visitor. They can show it on their phone to check in.</p>
</div>

<div class="panel glass">
  <h2><i class="fa-solid fa-clock-rotate-left"></i> Recent passes</h2>
  <?php if (!$passes): ?>
    <div class="empty"><i class="fa-solid fa-ticket"></i>No day passes yet.</div>
  <?php else: ?>
  <div class="table-wrap"><table>
    <thead><tr><th>Pass</th><th>Customer</th><th>Visit date</th><th>Amount</th><th>Payment</th><th>Access</th><th>QR code</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($passes as $pass): ?>
      <tr>
        <td><b><?= e($pass['pass_id']) ?></b><div class="mono">Kiosk: 9<?= e(substr($pass['pass_id'], 3)) ?></div></td>
        <td><?= e($pass['visitor_name']) ?><div class="mono"><?= e($pass['contact_number']) ?></div></td>
        <td><?= dt($pass['visit_date']) ?></td>
        <td><?= money($pass['amount']) ?></td>
        <td><?= e($pass['payment_method']) ?><div><?= badge($pass['pass_status']) ?></div></td>
        <td>
          <?php if ($pass['check_in_time']): ?>
            <span class="chip ok">checked in</span>
            <?php if ($pass['check_out_time']): ?><div class="note">completed</div><?php endif; ?>
          <?php else: ?><span class="chip mute">not used</span><?php endif; ?>
        </td>
        <td>
          <?php if ($pass['pass_status'] === 'Paid' && $pass['visit_date'] >= date('Y-m-d')): ?>
            <a href="../api/qr.php?type=day_pass&amp;id=<?= rawurlencode((string) $pass['pass_id']) ?>" target="_blank" rel="noopener" title="Open day-pass QR at full size">
              <img src="../api/qr.php?type=day_pass&amp;id=<?= rawurlencode((string) $pass['pass_id']) ?>" alt="QR code for <?= e($pass['pass_id']) ?>" width="88" height="88">
              <span class="note">Open full size</span>
            </a>
            <a class="note" href="../api/qr.php?type=day_pass&amp;id=<?= rawurlencode((string) $pass['pass_id']) ?>" download="<?= e($pass['pass_id']) ?>.svg">Download QR</a>
          <?php else: ?><span class="note">Available after payment</span><?php endif; ?>
        </td>
        <td>
          <?php if ($pass['pass_status'] !== 'Cancelled' && $pass['visit_date'] >= date('Y-m-d')): ?>
            <form method="post" onsubmit="return confirm('Cancel this day pass?')">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="cancel">
              <input type="hidden" name="pass_id" value="<?= e($pass['pass_id']) ?>">
              <button class="btn sm danger" title="Cancel"><i class="fa-solid fa-ban"></i></button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>

<div class="modal" id="mDayPass">
  <div class="modal-bg"></div>
  <div class="modal-box">
    <h3><i class="fa-solid fa-ticket"></i> New day pass</h3>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="create">
      <div class="field-row"><label>Customer name</label><input type="text" name="visitor_name" required autofocus></div>
      <div class="field-row"><label>Contact number</label><input type="tel" name="contact_number" required></div>
      <div class="form-grid">
        <div class="field-row"><label>Visit date</label><input type="date" name="visit_date" value="<?= date('Y-m-d') ?>" min="<?= date('Y-m-d') ?>" required></div>
        <div class="field-row"><label>Amount</label><input type="number" name="amount" value="<?= DAY_PASS_PRICE ?>" min="0" step="0.01" required></div>
      </div>
      <div class="form-grid">
        <div class="field-row"><label>Payment method</label><select name="payment_method"><option>Cash</option><option>Card</option><option>GCash</option><option>Bank Transfer</option><option>Maya</option></select></div>
        <div class="field-row"><label>Payment status</label><select name="pass_status"><option>Paid</option><option>Pending</option></select></div>
      </div>
      <div class="field-row"><label>Notes</label><input type="text" name="notes" placeholder="Walk-in customer"></div>
      <p class="note">The pass appears as <b>DAY00001</b>. At the numeric kiosk, enter <b>900001</b>.</p>
      <div class="modal-actions">
        <button type="button" class="btn" onclick="closeModal('mDayPass')">Cancel</button>
        <button class="btn red"><i class="fa-solid fa-check"></i> Create pass</button>
      </div>
    </form>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
