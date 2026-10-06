<?php
/**
 * The notifications screen. Identical for every role, so all three
 * role folders include this one file.
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['action'] ?? '';

    if ($act === 'read') {
        db()->prepare('UPDATE notifications SET is_read = 1, read_at = NOW() WHERE notification_id = ? AND user_id = ?')
            ->execute([(int) $_POST['notification_id'], $u['id']]);
    }
    if ($act === 'read_all') {
        db()->prepare('UPDATE notifications SET is_read = 1, read_at = NOW() WHERE user_id = ? AND is_read = 0')
            ->execute([$u['id']]);
        flash('All notifications marked as read.');
    }
    if ($act === 'delete') {
        db()->prepare('DELETE FROM notifications WHERE notification_id = ? AND user_id = ?')
            ->execute([(int) $_POST['notification_id'], $u['id']]);
    }
    redirect('notifications.php');
}

$page = 'notifications';
$title = 'Notifications';
$subtitle = 'Payments, bookings and account messages.';

$notes = rows(
    'SELECT * FROM notifications WHERE user_id = ? ORDER BY is_read, created_at DESC LIMIT 60',
    [$u['id']]
);
$unread = count(array_filter($notes, fn($n) => !$n['is_read']));

include __DIR__ . '/../includes/header.php';
?>

<div class="panel glass">
  <h2><i class="fa-solid fa-bell"></i> <?= $unread ?> unread of <?= count($notes) ?>
    <span class="spacer"></span>
    <?php if ($unread): ?>
    <form method="post" style="display:inline">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="read_all">
      <button class="btn sm"><i class="fa-solid fa-check-double"></i> Mark all read</button>
    </form>
    <?php endif; ?>
  </h2>

  <?php if (!$notes): ?>
    <div class="empty"><i class="fa-solid fa-bell-slash"></i>Nothing here yet.</div>
  <?php else: ?>
    <?php foreach ($notes as $n): ?>
      <div class="notif <?= $n['is_read'] ? '' : 'unread' ?>">
        <div class="dot"><i class="fa-solid fa-<?= e($n['notification_icon'] ?: 'bell') ?>"></i></div>
        <div class="body" style="flex:1">
          <b><?= e($n['notification_title']) ?>
            <?php if ($n['priority'] === 'high' || $n['priority'] === 'urgent'): ?>
              <span class="chip bad" style="margin-left:6px"><?= e($n['priority']) ?></span>
            <?php endif; ?>
          </b>
          <p><?= e($n['notification_message']) ?></p>
          <?php if (!empty($n['image_path'])): ?>
            <img class="announcement-image" src="../<?= e($n['image_path']) ?>" alt="Announcement image" loading="lazy">
          <?php endif; ?>
          <?php if (!empty($n['video_path'])): ?>
            <video class="announcement-video" controls preload="metadata">
              <source src="../<?= e($n['video_path']) ?>">
              Your browser does not support video playback.
            </video>
          <?php endif; ?>
          <div class="when"><?= dt($n['created_at'], 'M j, Y · g:i A') ?></div>
        </div>
        <div style="display:flex;gap:6px;align-items:flex-start">
          <?php if (!$n['is_read']): ?>
            <form method="post">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="read">
              <input type="hidden" name="notification_id" value="<?= (int) $n['notification_id'] ?>">
              <button class="btn sm icon" title="Mark read"><i class="fa-solid fa-check"></i></button>
            </form>
          <?php endif; ?>
          <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="notification_id" value="<?= (int) $n['notification_id'] ?>">
            <button class="btn sm icon danger" title="Delete"><i class="fa-solid fa-trash"></i></button>
          </form>
        </div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
