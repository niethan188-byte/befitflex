<?php
define('IN_SUBDIR', true);
require __DIR__ . '/../includes/auth.php';
$u = require_role('member');
$mid = $_SESSION['member_id'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['action'] ?? '';

    if ($act === 'profile') {
      if (!encryption_available()) {
        flash('PII encryption is unavailable in the web server. Restart Laragon and try again.', 'err');
        redirect('profile.php');
      }
        $contact = trim((string) $_POST['contact_number']);
        db()->prepare('UPDATE members SET member_name = ?, contact_number = ?, contact_number_encrypted = ? WHERE member_id = ?')
          ->execute([trim((string) $_POST['member_name']), $contact, encrypt_pii($contact), $mid]);
        $_SESSION['name'] = trim((string) $_POST['member_name']);
        log_activity('Update profile', 'Profile', $mid);
        flash('Profile updated.');
    }

    if ($act === 'password') {
        $current = (string) $_POST['current_password'];
        $new     = (string) $_POST['new_password'];
        $user    = row('SELECT * FROM users WHERE user_id = ?', [$u['id']]);

        $stored = (string) $user['password'];
        $ok = str_starts_with($stored, '$2y$') ? password_verify($current, $stored) : hash_equals($stored, $current);

        if (!$ok) {
            flash('Your current password is not correct.', 'err');
        } elseif (strlen($new) < 6) {
            flash('The new password must be at least 6 characters.', 'err');
        } else {
            db()->prepare('UPDATE users SET password = ? WHERE user_id = ?')
                ->execute([password_hash($new, PASSWORD_DEFAULT), $u['id']]);
            log_activity('Change password', 'Profile', '');
            flash('Password changed.');
        }
    }

    if ($act === 'prefs') {
        db()->prepare(
            'UPDATE notification_preferences
                SET email_payments=?, email_reservations=?, email_account=?, email_system=?,
                    in_app_payments=?, in_app_reservations=?, in_app_account=?, in_app_system=?
              WHERE user_id = ?'
        )->execute([
            isset($_POST['email_payments']) ? 1 : 0, isset($_POST['email_reservations']) ? 1 : 0,
            isset($_POST['email_account']) ? 1 : 0,  isset($_POST['email_system']) ? 1 : 0,
            isset($_POST['in_app_payments']) ? 1 : 0, isset($_POST['in_app_reservations']) ? 1 : 0,
            isset($_POST['in_app_account']) ? 1 : 0,  isset($_POST['in_app_system']) ? 1 : 0,
            $u['id'],
        ]);
        flash('Notification preferences saved.');
    }

    redirect('profile.php');
}

$page = 'profile';
$title = 'My Profile';
$subtitle = 'Your details, password and notification settings.';

$me = row('SELECT m.* FROM members m WHERE m.member_id = ?', [$mid]);
$prefs = row('SELECT * FROM notification_preferences WHERE user_id = ?', [$u['id']]) ?? [];
$consent = row("SELECT * FROM activity_log WHERE user_id = ? AND action = 'Privacy consent' ORDER BY created_at DESC LIMIT 1", [$u['id']]);

$ck = fn(string $k) => !empty($prefs[$k]) ? 'checked' : '';

include __DIR__ . '/../includes/header.php';
?>

<div class="grid c2">
  <div class="panel glass">
    <h2><i class="fa-solid fa-id-card"></i> Membership</h2>
    <table class="kv" style="width:100%">
      <tr><th>Member ID</th><td class="mono"><?= e($me['member_id']) ?></td></tr>
      <tr><th>Email</th><td><?= e($me['email']) ?></td></tr>
      <tr><th>Plan</th><td><?= e($me['membership_type']) ?> · <?= money(MEMBERSHIP_PRICE[$me['membership_type']] ?? 0) ?></td></tr>
      <tr><th>Joined</th><td><?= dt($me['join_date']) ?></td></tr>
      <tr><th>Status</th><td><?= badge($me['status']) ?></td></tr>
      <?php if ($consent): ?>
        <tr><th>Privacy consent</th><td class="note"><?= e($consent['details']) ?></td></tr>
      <?php endif; ?>
    </table>
    <p class="note" style="margin-top:14px">To update your membership details or plan, speak to the front desk.</p>
  </div>

  <div class="panel glass">
    <h2><i class="fa-solid fa-user-pen"></i> Edit your details</h2>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="profile">
      <div class="field-row"><label>Full name</label>
        <input type="text" name="member_name" required value="<?= e($me['member_name']) ?>"></div>
      <div class="field-row"><label>Contact number</label>
        <input type="tel" name="contact_number" required value="<?= e(member_contact($me)) ?>"></div>
      <button class="btn red"><i class="fa-solid fa-check"></i> Save changes</button>
    </form>

    <h2 style="margin-top:28px"><i class="fa-solid fa-key"></i> Change password</h2>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="password">
      <div class="field-row"><label>Current password</label>
        <input type="password" name="current_password" required></div>
      <div class="field-row"><label>New password</label>
        <input type="password" name="new_password" required minlength="6"></div>
      <button class="btn"><i class="fa-solid fa-lock"></i> Update password</button>
    </form>
  </div>
</div>

<div class="panel glass">
  <h2><i class="fa-solid fa-bell"></i> Notification preferences</h2>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="prefs">
    <div class="grid c2">
      <div>
        <div style="font-size:11px;letter-spacing:.14em;text-transform:uppercase;color:var(--muted);margin-bottom:10px">In-app</div>
        <?php foreach (['in_app_payments' => 'Payments', 'in_app_reservations' => 'Reservations',
                        'in_app_account' => 'Account', 'in_app_system' => 'System messages'] as $k => $lbl): ?>
          <label style="display:flex;gap:10px;align-items:center;margin-bottom:10px;font-size:13.5px;color:var(--text)">
            <input type="checkbox" name="<?= $k ?>" value="1" style="width:auto" <?= $ck($k) ?>> <?= $lbl ?>
          </label>
        <?php endforeach; ?>
      </div>
      <div>
        <div style="font-size:11px;letter-spacing:.14em;text-transform:uppercase;color:var(--muted);margin-bottom:10px">Email</div>
        <?php foreach (['email_payments' => 'Payments', 'email_reservations' => 'Reservations',
                        'email_account' => 'Account', 'email_system' => 'System messages'] as $k => $lbl): ?>
          <label style="display:flex;gap:10px;align-items:center;margin-bottom:10px;font-size:13.5px;color:var(--text)">
            <input type="checkbox" name="<?= $k ?>" value="1" style="width:auto" <?= $ck($k) ?>> <?= $lbl ?>
          </label>
        <?php endforeach; ?>
      </div>
    </div>
    <button class="btn red" style="margin-top:14px"><i class="fa-solid fa-check"></i> Save preferences</button>
  </form>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
