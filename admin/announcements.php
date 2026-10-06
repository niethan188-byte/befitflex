<?php
define('IN_SUBDIR', true);
require __DIR__ . '/../includes/auth.php';
$u = require_role('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $title    = trim((string) ($_POST['title'] ?? '')) ?: 'Announcement';
    $message  = trim((string) ($_POST['message'] ?? ''));
    $audience = (string) ($_POST['audience'] ?? 'all');
    $priority = (string) ($_POST['priority'] ?? 'normal');
    $icon     = (string) ($_POST['icon'] ?? 'bullhorn');
    $imagePath = null;
    $videoPath = null;

    if ($message === '') {
      flash('A message is required.', 'err');
        redirect('announcements.php');
    }

    if (isset($_FILES['image']) && $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE) {
      $image = $_FILES['image'];
      $allowed = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
      ];
      $mime = '';
      if ($image['error'] !== UPLOAD_ERR_OK || (int) $image['size'] > 5 * 1024 * 1024) {
        flash('The image must be 5 MB or smaller.', 'err');
        redirect('announcements.php');
      }
      $fileInfo = finfo_open(FILEINFO_MIME_TYPE);
      $mime = $fileInfo ? (string) finfo_file($fileInfo, $image['tmp_name']) : '';
      if ($fileInfo) {
        finfo_close($fileInfo);
      }
      if (!isset($allowed[$mime]) || @getimagesize($image['tmp_name']) === false) {
        flash('Upload a valid JPG, PNG, WEBP, or GIF image.', 'err');
        redirect('announcements.php');
      }
      $uploadDir = __DIR__ . '/../assets/uploads/announcements';
      if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
        flash('The announcement image folder is not available.', 'err');
        redirect('announcements.php');
      }
      $filename = bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
      if (!move_uploaded_file($image['tmp_name'], $uploadDir . '/' . $filename)) {
        flash('The announcement image could not be saved.', 'err');
        redirect('announcements.php');
      }
      $imagePath = 'assets/uploads/announcements/' . $filename;
    }

    if (isset($_FILES['video']) && $_FILES['video']['error'] !== UPLOAD_ERR_NO_FILE) {
      $video = $_FILES['video'];
      $allowed = [
        'video/mp4'       => 'mp4',
        'video/webm'      => 'webm',
        'video/ogg'       => 'ogv',
        'application/ogg' => 'ogv',
      ];
      if ($video['error'] !== UPLOAD_ERR_OK || (int) $video['size'] > 1024 * 1024 * 1024) {
        flash('The video must be 1 GB or smaller.', 'err');
        redirect('announcements.php');
      }
      $fileInfo = finfo_open(FILEINFO_MIME_TYPE);
      $mime = $fileInfo ? (string) finfo_file($fileInfo, $video['tmp_name']) : '';
      if ($fileInfo) {
        finfo_close($fileInfo);
      }
      if (!isset($allowed[$mime])) {
        flash('Upload a valid MP4, WEBM, or OGG video.', 'err');
        redirect('announcements.php');
      }
      $uploadDir = __DIR__ . '/../assets/uploads/announcements';
      if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
        flash('The announcement video folder is not available.', 'err');
        redirect('announcements.php');
      }
      $filename = bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
      if (!move_uploaded_file($video['tmp_name'], $uploadDir . '/' . $filename)) {
        flash('The announcement video could not be saved.', 'err');
        redirect('announcements.php');
      }
      $videoPath = 'assets/uploads/announcements/' . $filename;
    }

    $sql = match ($audience) {
        'members'  => "SELECT user_id FROM users WHERE user_type = 'member'",
        'active'   => "SELECT u.user_id FROM users u JOIN members m ON m.user_id = u.user_id
                        WHERE m.status = 'Active'",
        'overdue'  => "SELECT DISTINCT u.user_id FROM users u
                         JOIN members m ON m.user_id = u.user_id
                         JOIN payments p ON p.member_id = m.member_id
                        WHERE p.payment_status IN ('Pending','Overdue')",
        'lapsed'   => "SELECT u.user_id FROM users u JOIN members m ON m.user_id = u.user_id
                        WHERE m.status <> 'Inactive'
                          AND NOT EXISTS (SELECT 1 FROM attendance a
                                           WHERE a.member_id = m.member_id
                                             AND a.attendance_date >= DATE_SUB(CURDATE(), INTERVAL 21 DAY))",
        default    => "SELECT user_id FROM users WHERE user_type <> 'admin'",
    };

    $recipients = rows($sql);

    /* One prepared statement, one transaction — a broadcast to 500 members
       is a single round trip's worth of overhead, not 500. */
    $pdo = db();
    $pdo->beginTransaction();
    $ins = $pdo->prepare(
        'INSERT INTO notifications
            (user_id, notification_type, notification_title, notification_message,
            notification_icon, icon_color, priority, image_path, video_path)
          VALUES (?, "system", ?, ?, ?, ?, ?, ?, ?)' 
    );
    $colour = $priority === 'urgent' ? 'danger' : ($priority === 'high' ? 'warning' : 'primary');
    $notificationIds = [];
    foreach ($recipients as $r) {
        $ins->execute([(int) $r['user_id'], $title, $message, $icon, $colour, $priority, $imagePath, $videoPath]);
      $notificationIds[] = [
        'id' => (int) $pdo->lastInsertId(),
        'user_id' => (int) $r['user_id'],
      ];
    }
    $pdo->commit();

    $emailRecipients = rows(
      'SELECT u.user_id, u.email
         FROM users u
         LEFT JOIN notification_preferences np ON np.user_id = u.user_id
        WHERE np.user_id IS NULL OR np.email_system = 1'
    );
    $emailByUser = [];
    foreach ($emailRecipients as $recipient) {
      $emailByUser[(int) $recipient['user_id']] = (string) $recipient['email'];
    }
    $markEmail = $pdo->prepare(
      'UPDATE notifications SET email_sent = 1, email_sent_at = NOW() WHERE notification_id = ?'
    );
    $emailSent = 0;
    $emailFailed = 0;
    foreach ($notificationIds as $notification) {
      $email = $emailByUser[$notification['user_id']] ?? '';
      if ($email && send_email($email, $title, '<h2>' . e($title) . '</h2><p>' . nl2br(e($message)) . '</p>')) {
        $markEmail->execute([$notification['id']]);
        $emailSent++;
      } elseif ($email) {
        $emailFailed++;
      }
    }

    log_activity('Broadcast', 'Announcements', $title . ' → ' . count($recipients) . ' recipients');
    $people = count($recipients) === 1 ? 'person' : 'people';
    flash('In-app notification sent to ' . count($recipients) . " $people. Email: $emailSent sent, $emailFailed failed.");
    redirect('announcements.php');
}

$page = 'announcements';
$title = 'Announcements';
$subtitle = 'Send one message to a whole group at once.';

$sizes = row(
    "SELECT
       (SELECT COUNT(*) FROM users WHERE user_type <> 'admin') AS all_n,
       (SELECT COUNT(*) FROM users WHERE user_type = 'member') AS members_n,
       (SELECT COUNT(*) FROM members WHERE status = 'Active') AS active_n,
       (SELECT COUNT(DISTINCT m.user_id) FROM members m JOIN payments p ON p.member_id = m.member_id
         WHERE p.payment_status IN ('Pending','Overdue')) AS overdue_n,
       (SELECT COUNT(*) FROM members m WHERE m.status <> 'Inactive'
          AND NOT EXISTS (SELECT 1 FROM attendance a WHERE a.member_id = m.member_id
                           AND a.attendance_date >= DATE_SUB(CURDATE(), INTERVAL 21 DAY))) AS lapsed_n"
) ?? [];

$sent = rows(
    "SELECT notification_title, notification_message, priority, image_path, video_path, created_at,
            COUNT(*) recipients, SUM(is_read) opened
       FROM notifications
      WHERE notification_type = 'system'
      GROUP BY notification_title, notification_message, priority, image_path, video_path, created_at
      ORDER BY created_at DESC LIMIT 12"
);

include __DIR__ . '/../includes/header.php';
?>

<div class="grid c2">
  <div class="panel glass">
    <h2><i class="fa-solid fa-bullhorn"></i> Compose</h2>

    <form method="post" enctype="multipart/form-data">
      <?= csrf_field() ?>

      <div class="field-row">
        <label for="aud">Who should receive it</label>
        <select name="audience" id="aud" required>
          <option value="all">Everyone — <?= (int) ($sizes['all_n'] ?? 0) ?> people</option>
          <option value="members">All members — <?= (int) ($sizes['members_n'] ?? 0) ?></option>
          <option value="active">Active members only — <?= (int) ($sizes['active_n'] ?? 0) ?></option>
          <option value="overdue">Members with a balance — <?= (int) ($sizes['overdue_n'] ?? 0) ?></option>
          <option value="lapsed">Not seen in 3 weeks — <?= (int) ($sizes['lapsed_n'] ?? 0) ?></option>
        </select>
      </div>

      <div class="field-row">
        <label for="ttl">Title <span class="note">optional</span></label>
        <input type="text" name="title" id="ttl" maxlength="120"
               placeholder="Holiday hours this week">
      </div>

      <div class="field-row">
        <label for="msg">Message</label>
        <textarea name="message" id="msg" rows="4" required maxlength="600"
                  placeholder="Both branches close at 6 PM on Friday. Normal hours resume Saturday."></textarea>
        <div class="note" style="text-align:right;margin-top:4px"><span id="cc">0</span>/600</div>
      </div>

      <div class="field-row">
        <label for="announcementImage">Image <span class="note">optional, max 5 MB</span></label>
        <input type="file" name="image" id="announcementImage"
               accept="image/jpeg,image/png,image/webp,image/gif">
        <div class="note">The image will be shown in every recipient's notification.</div>
      </div>

      <div class="field-row">
        <label for="announcementVideo">Video <span class="note">optional, max 1 GB</span></label>
        <input type="file" name="video" id="announcementVideo" accept="video/mp4,video/webm,video/ogg">
        <div class="note">MP4, WEBM, or OGG. The video will be shown in every recipient's notification.</div>
      </div>

      <div class="form-grid">
        <div class="field-row">
          <label for="pri">Priority</label>
          <select name="priority" id="pri">
            <option value="normal">Normal</option>
            <option value="high">High</option>
            <option value="urgent">Urgent</option>
          </select>
        </div>
        <div class="field-row">
          <label for="ico">Icon</label>
          <select name="icon" id="ico">
            <option value="bullhorn">Announcement</option>
            <option value="calendar-day">Schedule change</option>
            <option value="peso-sign">Billing</option>
            <option value="dumbbell">Training</option>
            <option value="triangle-exclamation">Important</option>
            <option value="gift">Promo</option>
          </select>
        </div>
      </div>

      <button class="btn red full"><i class="fa-solid fa-paper-plane"></i> Send announcement</button>
      <p class="note" style="margin-top:12px">
        This lands in the recipients' notification list immediately. It cannot be recalled,
        so read it once more before sending.
      </p>
    </form>
  </div>

  <div class="panel glass">
    <h2><i class="fa-solid fa-clock-rotate-left"></i> Already sent</h2>
    <?php if (!$sent): ?>
      <div class="empty">
        <i class="fa-solid fa-bullhorn"></i>
        <h4>Nothing sent yet</h4>
        <p class="note">Broadcasts you send appear here with how many people opened them.</p>
      </div>
    <?php else: ?>
      <?php foreach ($sent as $s):
        $rate = (int) $s['recipients'] > 0 ? round((int) $s['opened'] / (int) $s['recipients'] * 100) : 0; ?>
        <div class="notif">
          <div class="dot"><i class="fa-solid fa-bullhorn"></i></div>
          <div class="body" style="flex:1">
            <b><?= e($s['notification_title']) ?>
              <?php if ($s['priority'] !== 'normal'): ?>
                <span class="chip <?= $s['priority'] === 'urgent' ? 'bad' : 'warn' ?>"><?= e($s['priority']) ?></span>
              <?php endif; ?>
            </b>
            <p><?= e($s['notification_message']) ?></p>
            <?php if (!empty($s['image_path'])): ?>
              <img class="announcement-image" src="../<?= e($s['image_path']) ?>" alt="Announcement image" loading="lazy">
            <?php endif; ?>
            <?php if (!empty($s['video_path'])): ?>
              <video class="announcement-video" controls preload="metadata">
                <source src="../<?= e($s['video_path']) ?>">
                Your browser does not support video playback.
              </video>
            <?php endif; ?>
            <div class="when">
              <?= dt($s['created_at'], 'M j · g:i A') ?> ·
              <?= (int) $s['recipients'] ?> sent ·
              <span style="color:<?= $rate >= 50 ? '#4ADE80' : 'var(--dim)' ?>"><?= $rate ?>% opened</span>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>

<script>
const msg = document.getElementById('msg'), cc = document.getElementById('cc');
msg.addEventListener('input', () => { cc.textContent = msg.value.length; });
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
