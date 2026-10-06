<?php
require __DIR__ . '/includes/auth.php';

if (current_user()) {
    redirect(home_for($_SESSION['user_type']));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $name    = trim((string) ($_POST['member_name'] ?? ''));
    $email   = trim((string) ($_POST['email'] ?? ''));
    $contact = trim((string) ($_POST['contact_number'] ?? ''));
    $type    = (string) ($_POST['membership_type'] ?? 'Monthly');
    $pass    = (string) ($_POST['password'] ?? '');
    $consent = !empty($_POST['consent']);

    $errors = [];
    if ($name === '')                              $errors[] = 'Your full name is required.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'That email address is not valid.';
    if (strlen($pass) < 6)                          $errors[] = 'Choose a password of at least 6 characters.';
    if (!in_array($type, ['Monthly', 'Quarterly', 'Annual'], true)) $errors[] = 'Pick a membership type.';
    if (!$consent)                                  $errors[] = 'Privacy consent is required to enroll.';
    if (scalar('SELECT COUNT(*) FROM users WHERE email = ?', [$email])) {
        $errors[] = 'An account with that email already exists.';
    }

    if ($errors) {
        foreach ($errors as $err) { flash($err, 'err'); }
    } elseif (!encryption_available()) {
      flash('PII encryption is unavailable in the web server. Restart Laragon and try again.', 'err');
    } else {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $verificationToken = bin2hex(random_bytes(32));
            $pdo->prepare(
              'INSERT INTO users (email, password, user_type, email_verification_token, email_verification_expires_at)
               VALUES (?,?,?,?,DATE_ADD(NOW(), INTERVAL 24 HOUR))'
            )->execute([$email, password_hash($pass, PASSWORD_DEFAULT), 'member', hash('sha256', $verificationToken)]);
            $userId = (int) $pdo->lastInsertId();

            $memberId = next_id('members', 'member_id', 'MEM', 4);
            $pdo->prepare(
                'INSERT INTO members (member_id,user_id,member_name,contact_number,contact_number_encrypted,email,membership_type,join_date,status)
                  VALUES (?,?,?,?,?,?,?,CURDATE(),?)'
              )->execute([$memberId, $userId, $name, encrypt_pii($contact), encrypt_pii($contact), $email, $type, 'Active']);

            $pdo->prepare('INSERT INTO notification_preferences (user_id) VALUES (?)')->execute([$userId]);

            // Opening dues for the chosen membership.
            $pdo->prepare(
                'INSERT INTO payments (payment_id,member_id,amount,payment_method,payment_status,notes)
                 VALUES (?,?,?,?,?,?)'
            )->execute([
                next_id('payments', 'payment_id', 'PAY', 5), $memberId,
                MEMBERSHIP_PRICE[$type], 'Cash', 'Pending', $type . ' membership — first payment',
            ]);

            $pdo->prepare('INSERT INTO activity_log (user_id,action,module,details) VALUES (?,?,?,?)')
                ->execute([$userId, 'Privacy consent', 'Data Privacy',
                           'Consent given at online enrollment on ' . date('Y-m-d')]);

            $pdo->commit();

            $verificationUrl = 'http://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
              . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/')
              . '/verify-email.php?token=' . urlencode($verificationToken);
            send_email($email, 'Verify your Be Fit Flex Gym account',
              '<h2>Welcome to Be Fit Flex Gym</h2><p>Confirm your email address to activate your account.</p>'
              . '<p><a href="' . e($verificationUrl) . '">Verify my email address</a></p>'
              . '<p>This link expires in 24 hours.</p>');

            notify($userId, 'system', 'Welcome to Be Fit Flex Gym',
              'Your member profile ' . $memberId . ' was created. Verify your email address before signing in.',
                'dumbbell', 'danger', 'member/dashboard.php');

            flash('Account created. Check your email to verify it before signing in.');
            redirect('index.php');
        } catch (Throwable $ex) {
            $pdo->rollBack();
            flash('Something went wrong creating the account. Please try again.', 'err');
        }
    }
}

$flashes = take_flash();
$hasLogo = is_file(__DIR__ . '/assets/img/logo.png');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Join · <?= APP_NAME ?></title>
<link rel="stylesheet" href="assets/css/glass.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
</head>
<body>
<div class="field"><div class="blob a"></div><div class="blob b"></div><div class="blob c"></div></div>

<?php if ($flashes): ?>
<div class="flash-stack">
  <?php foreach ($flashes as $f): ?>
    <div class="flash <?= $f['type'] === 'err' ? 'err' : 'ok' ?>">
      <i class="fa-solid <?= $f['type'] === 'err' ? 'fa-circle-exclamation' : 'fa-circle-check' ?>"></i>
      <span><?= e($f['msg']) ?></span>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="auth-wrap">
  <div class="auth-card glass" style="width:min(560px,100%)">
    <div class="auth-logo">
      <?php if ($hasLogo): ?><img src="assets/img/logo.png" alt="">
      <?php else: ?><svg viewBox="0 0 100 100" fill="none"><path d="M50 4 92 28v48L50 100 8 76V28z" stroke="#E53935" stroke-width="6"/><path d="M34 30h26M34 50h22M34 70h26" stroke="#E53935" stroke-width="7" stroke-linecap="round"/></svg><?php endif; ?>
    </div>
    <h1>Become a member</h1>
    <p class="tag">Santa Rosa · Cabuyao</p>

    <form method="post">
      <?= csrf_field() ?>
      <div class="field-row">
        <label>Full name</label>
        <input type="text" name="member_name" required value="<?= e($_POST['member_name'] ?? '') ?>" placeholder="Juan Dela Cruz">
      </div>
      <div class="form-grid">
        <div class="field-row">
          <label>Email address</label>
          <input type="email" name="email" required value="<?= e($_POST['email'] ?? '') ?>" placeholder="you@example.com">
        </div>
        <div class="field-row">
          <label>Contact number</label>
          <input type="tel" name="contact_number" required value="<?= e($_POST['contact_number'] ?? '') ?>" placeholder="09XX XXX XXXX">
        </div>
      </div>
      <div class="form-grid">
        <div class="field-row">
          <label>Membership</label>
          <select name="membership_type" required>
            <?php foreach (MEMBERSHIP_PRICE as $t => $p): ?>
              <option value="<?= $t ?>" <?= ($_POST['membership_type'] ?? '') === $t ? 'selected' : '' ?>>
                <?= $t ?> — <?= number_format($p, 2) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field-row">
          <label>Password</label>
          <input type="password" name="password" required minlength="6" placeholder="At least 6 characters">
        </div>
      </div>

      <label style="display:flex;gap:10px;align-items:flex-start;font-size:12.5px;color:var(--muted);margin:6px 0 18px">
        <input type="checkbox" name="consent" value="1" style="width:auto;margin-top:3px" required>
        <span>I consent to Be Fit Flex Gym processing my personal data for membership
        administration under the Data Privacy Act (RA 10173).
        <a href="privacy.php" style="color:var(--red-hot)">Read the notice</a>.</span>
      </label>

      <button class="btn red full" type="submit"><i class="fa-solid fa-user-plus"></i> Create my account</button>
    </form>

    <p class="auth-alt">Already a member? <a href="index.php">Sign in</a></p>
  </div>
</div>
<script src="assets/js/app.js"></script>
</body>
</html>
