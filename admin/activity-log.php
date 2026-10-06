<?php
define('IN_SUBDIR', true);
require __DIR__ . '/../includes/auth.php';
$u = require_role('admin');

$page = 'activity';
$title = 'Activity Log';
$subtitle = 'Who did what, and when.';

$logs = rows(
    'SELECT a.*, u.email, u.user_type FROM activity_log a
       JOIN users u ON u.user_id = a.user_id
      ORDER BY a.created_at DESC LIMIT 200'
);

include __DIR__ . '/../includes/header.php';
?>

<div class="panel glass">
  <h2><i class="fa-solid fa-clock-rotate-left"></i> Last <?= count($logs) ?> entries
    <span class="spacer"></span>
    <input type="search" placeholder="Filter…" data-filter-for="tblLog" style="width:220px"></h2>

  <?php if (!$logs): ?>
    <div class="empty"><i class="fa-solid fa-clock"></i>Nothing logged yet.</div>
  <?php else: ?>
  <div class="table-wrap"><table id="tblLog">
    <thead><tr><th>When</th><th>Who</th><th>Action</th><th>Module</th><th>Details</th></tr></thead>
    <tbody>
    <?php foreach ($logs as $l): ?>
      <tr>
        <td style="white-space:nowrap"><?= dt($l['created_at'], 'M j, Y') ?>
            <div class="mono"><?= dt($l['created_at'], 'g:i A') ?></div></td>
        <td><?= e($l['email']) ?><div class="mono"><?= e($l['user_type']) ?></div></td>
        <td><b><?= e($l['action']) ?></b></td>
        <td><?= e($l['module']) ?></td>
        <td class="note"><?= e($l['details'] ?: '—') ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
