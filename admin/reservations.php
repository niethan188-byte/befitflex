<?php
define('IN_SUBDIR', true);
require __DIR__ . '/../includes/auth.php';
$u = require_role('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (($_POST['action'] ?? '') === 'status') {
        db()->prepare('UPDATE reservations SET status = ? WHERE reservation_id = ?')
            ->execute([$_POST['status'], (int) $_POST['reservation_id']]);
        log_activity('Update reservation', 'Reservations', '#' . $_POST['reservation_id'] . ' → ' . $_POST['status']);
        flash('Reservation ' . strtolower((string) $_POST['status']) . '.');
    }
    if (($_POST['action'] ?? '') === 'delete') {
        db()->prepare('DELETE FROM reservations WHERE reservation_id = ?')->execute([(int) $_POST['reservation_id']]);
        flash('Reservation deleted.');
    }
    redirect('reservations.php');
}

$page = 'reservations';
$title = 'Reservations';
$subtitle = 'Member bookings and space reservations.';

$res = rows(
  'SELECT r.*, m.member_name
       FROM reservations r
       JOIN members  m ON m.member_id  = r.member_id
      ORDER BY r.reservation_date DESC, r.reservation_time DESC'
);

include __DIR__ . '/../includes/header.php';
?>

<div class="panel glass">
  <h2><i class="fa-solid fa-calendar-check"></i> <?= count($res) ?> booking<?= count($res) === 1 ? '' : 's' ?>
    <span class="spacer"></span>
    <input type="search" placeholder="Filter…" data-filter-for="tblRes" style="width:200px"></h2>

  <?php if (!$res): ?>
    <div class="empty"><i class="fa-solid fa-calendar-xmark"></i>No reservations.</div>
  <?php else: ?>
  <div class="table-wrap"><table id="tblRes">
    <thead><tr><th>#</th><th>Member</th><th>When</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($res as $r): ?>
      <tr>
        <td class="mono">#<?= (int) $r['reservation_id'] ?></td>
        <td><?= e($r['member_name']) ?><div class="mono"><?= e($r['member_id']) ?></div></td>
        <td><?= dt($r['reservation_date']) ?><div class="mono"><?= timeh($r['reservation_time']) ?></div></td>
        <td><?= badge($r['status']) ?></td>
        <td style="white-space:nowrap">
          <?php if ($r['status'] === 'Confirmed'): ?>
            <?php foreach ([['Completed', 'fa-check', ''], ['Cancelled', 'fa-xmark', 'danger']] as [$st, $ic, $cls]): ?>
              <form method="post" style="display:inline">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="status">
                <input type="hidden" name="reservation_id" value="<?= (int) $r['reservation_id'] ?>">
                <input type="hidden" name="status" value="<?= $st ?>">
                <button class="btn sm icon <?= $cls ?>" title="<?= $st ?>"><i class="fa-solid <?= $ic ?>"></i></button>
              </form>
            <?php endforeach; ?>
          <?php endif; ?>
          <form method="post" style="display:inline" onsubmit="return confirm('Delete this reservation?')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="reservation_id" value="<?= (int) $r['reservation_id'] ?>">
            <button class="btn sm icon danger"><i class="fa-solid fa-trash"></i></button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
