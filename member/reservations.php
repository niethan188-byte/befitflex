<?php
define('IN_SUBDIR', true);
require __DIR__ . '/../includes/auth.php';
$u = require_role('member');
$mid = $_SESSION['member_id'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['action'] ?? '';

    if ($act === 'book') {
        $date = (string) $_POST['reservation_date'];
        $time = (string) $_POST['reservation_time'];

        if ($date < date('Y-m-d')) {
            flash('Pick a date that has not passed yet.', 'err');
        } else {
          $pdo = db();
          $pdo->beginTransaction();
          try {
            $slot = $pdo->prepare(
              "SELECT reservation_id FROM reservations
                WHERE member_id = ? AND reservation_date = ? AND reservation_time = ?
                AND status = 'Confirmed' FOR UPDATE"
            );
            $slot->execute([$mid, $date, $time]);
            if ($slot->fetchColumn()) {
              $pdo->rollBack();
              flash('You already have a reservation for that time. Try another slot.', 'err');
              redirect('reservations.php');
            }
            $pdo->prepare(
              'INSERT INTO reservations (member_id,reservation_date,reservation_time,status)
               VALUES (?,?,?,"Confirmed")'
            )->execute([$mid, $date, $time]);
            $pdo->commit();
          } catch (Throwable $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            flash('The booking could not be saved. Please try again.', 'err');
            redirect('reservations.php');
          }

            log_activity('Book reservation', 'Reservations', $mid . ' ' . $date . ' ' . $time);
            flash('Reservation saved.');
        }
    }

    if ($act === 'cancel') {
        db()->prepare("UPDATE reservations SET status = 'Cancelled' WHERE reservation_id = ? AND member_id = ?")
            ->execute([(int) $_POST['reservation_id'], $mid]);
        flash('Booking cancelled.');
    }

    redirect('reservations.php');
}

$page = 'reservations';
$title = 'Reservations';
$subtitle = 'Reserve a preferred gym time.';

$mine = rows(
  'SELECT r.*
     FROM reservations r
      WHERE r.member_id = ? ORDER BY r.reservation_date DESC, r.reservation_time DESC', [$mid]
);

include __DIR__ . '/../includes/header.php';
?>

<div class="panel glass">
  <h2><i class="fa-solid fa-calendar-plus"></i> Reserve a time</h2>
  <div class="note" style="margin-bottom:14px">Select a date and time below for your next gym visit or appointment.</div>
  <button class="btn red sm" onclick="book()"><i class="fa-solid fa-calendar-plus"></i> New reservation</button>
</div>

<div class="panel glass">
  <h2><i class="fa-solid fa-calendar-check"></i> My bookings</h2>
  <?php if (!$mine): ?>
    <div class="empty"><i class="fa-solid fa-calendar-xmark"></i>No bookings yet.</div>
  <?php else: ?>
  <div class="table-wrap"><table>
    <thead><tr><th>Date</th><th>Time</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($mine as $r): ?>
      <tr>
        <td><?= dt($r['reservation_date']) ?></td>
        <td><?= timeh($r['reservation_time']) ?></td>
        <td><?= badge($r['status']) ?></td>
        <td>
          <?php if ($r['status'] === 'Confirmed' && $r['reservation_date'] >= date('Y-m-d')): ?>
            <form method="post" onsubmit="return confirm('Cancel this booking?')">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="cancel">
              <input type="hidden" name="reservation_id" value="<?= (int) $r['reservation_id'] ?>">
              <button class="btn sm danger"><i class="fa-solid fa-xmark"></i> Cancel</button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>

<div class="modal" id="mBook">
  <div class="modal-bg"></div>
  <div class="modal-box">
    <h3><i class="fa-solid fa-calendar-plus"></i> New reservation</h3>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="book">
      <div class="form-grid">
        <div class="field-row"><label>Date</label>
          <input type="date" name="reservation_date" required min="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>"></div>
        <div class="field-row"><label>Time</label>
          <input type="time" name="reservation_time" required value="07:00"></div>
      </div>
      <p class="note">If the slot is already taken you will be told, and nothing is booked.</p>
      <div class="modal-actions">
        <button type="button" class="btn" onclick="closeModal('mBook')">Cancel</button>
        <button class="btn red"><i class="fa-solid fa-check"></i> Confirm booking</button>
      </div>
    </form>
  </div>
</div>

<script>
function book(id, name) {
  openModal('mBook');
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
