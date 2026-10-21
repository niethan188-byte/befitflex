<?php
require __DIR__ . '/includes/auth.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if (current_user()) {
    redirect(home_for($_SESSION['user_type']));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    if (!turnstile_verify_token((string) ($_POST['cf-turnstile-response'] ?? ''), 'register')) {
        flash('Security verification failed or is unavailable. Please try again or contact the gym.', 'err');
        redirect('register.php');
    }

    $name    = trim((string) ($_POST['member_name'] ?? ''));
    $email   = trim((string) ($_POST['email'] ?? ''));
    $contact = trim((string) ($_POST['contact_number'] ?? ''));
    $type    = (string) ($_POST['membership_type'] ?? 'Monthly');
    $pass    = (string) ($_POST['password'] ?? '');
    $consent = !empty($_POST['consent']);
    $logRegistrationError = static function (string $context, Throwable $ex) use ($name, $email, $contact, $pass): void {
        $message = str_replace(
            array_values(array_filter([$name, $email, $contact, $pass], static fn (string $value): bool => $value !== '')),
            '[redacted]',
            $ex->getMessage()
        );
        error_log($context . ': ' . get_class($ex) . ': ' . $message);
    };

    $errors = [];
    if ($name === '')                              $errors[] = 'Your full name is required.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'That email address is not valid.';
    if (strlen($pass) < 6)                          $errors[] = 'Choose a password of at least 6 characters.';
    if (!array_key_exists($type, MEMBERSHIP_PRICE)) $errors[] = 'Pick a membership type.';
    if (!$consent)                                  $errors[] = 'Privacy consent is required to enroll.';
    if (scalar('SELECT COUNT(*) FROM users WHERE email = ?', [$email])) {
        $errors[] = 'An account with that email already exists.';
    }

    if ($errors) {
        foreach ($errors as $err) { flash($err, 'err'); }
    } elseif (!encryption_available()) {
      flash('PII encryption is unavailable. Configure BEFITFLEX_ENCRYPTION_KEY for Apache, then restart Apache from the XAMPP Control Panel.', 'err');
    } else {
        $pdo = db();
        try {
            $pdo->beginTransaction();
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
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) {
                try {
                    $pdo->rollBack();
                } catch (Throwable $rollbackError) {
                    $logRegistrationError('Account registration rollback failed', $rollbackError);
                }
            }
            $logRegistrationError('Account registration transaction failed', $ex);
            flash('Something went wrong creating the account. Please try again.', 'err');
            redirect('register.php');
        }

        $configuredBase = getenv('BEFITFLEX_APP_URL');
        if (is_string($configuredBase) && trim($configuredBase) !== '') {
            $base = rtrim(trim($configuredBase), '/');
        } else {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $base = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
                . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
        }
        $verificationUrl = $base . '/verify-email.php?token=' . urlencode($verificationToken);
        $escapedVerificationUrl = e($verificationUrl);
        $verificationHtml =
            '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>Verify your email</title></head>'
            . '<body style="margin:0;padding:0;background:#f1f3f5;font-family:Arial,Helvetica,sans-serif;color:#20242a">'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#f1f3f5;padding:32px 12px">'
            . '<tr><td align="center">'
            . '<table role="presentation" width="600" cellspacing="0" cellpadding="0" border="0" style="width:100%;max-width:600px;background:#ffffff;border-radius:12px;overflow:hidden">'
            . '<tr><td style="height:7px;background:#e53935;font-size:0;line-height:0">&nbsp;</td></tr>'
            . '<tr><td style="padding:30px 36px 12px">'
            . '<p style="margin:0;color:#e53935;font-size:13px;font-weight:bold;letter-spacing:1.5px">BE FIT FLEX GYM</p>'
            . '<h1 style="margin:22px 0 12px;font-size:27px;line-height:1.25;color:#17191d">Welcome, ' . e($name) . '!</h1>'
            . '<p style="margin:0;color:#555d68;font-size:16px;line-height:1.65">Thanks for joining us. Confirm your email address to finish setting up your member account.</p>'
            . '</td></tr>'
            . '<tr><td align="center" style="padding:26px 36px 22px">'
            . '<a href="' . $escapedVerificationUrl . '" style="display:inline-block;padding:15px 28px;background:#e53935;border-radius:7px;color:#ffffff;text-decoration:none;font-size:15px;font-weight:bold">Verify my email address</a>'
            . '</td></tr>'
            . '<tr><td style="padding:0 36px 28px">'
            . '<p style="margin:0 0 10px;color:#68707b;font-size:13px;line-height:1.6">This secure link expires in 24 hours. If you didn’t create this account, you can ignore this email.</p>'
            . '<p style="margin:18px 0 6px;color:#68707b;font-size:12px">If the button doesn’t work, copy and paste this link into your browser:</p>'
            . '<p style="margin:0;word-break:break-all;font-size:12px;line-height:1.6"><a href="' . $escapedVerificationUrl . '" style="color:#c62828">' . $escapedVerificationUrl . '</a></p>'
            . '</td></tr>'
            . '<tr><td style="padding:18px 36px;background:#f8f8f9;border-top:1px solid #eceef0">'
            . '<p style="margin:0;color:#7a818b;font-size:12px;line-height:1.6">Be Fit Flex Gym · Santa Rosa and Cabuyao, Laguna</p>'
            . '</td></tr></table>'
            . '</td></tr></table></body></html>';
        $verificationText = "Welcome to Be Fit Flex Gym, {$name}!\n\n"
            . "Confirm your email address to finish setting up your member account:\n"
            . $verificationUrl . "\n\n"
            . "This secure link expires in 24 hours. If you didn't create this account, you can ignore this email.\n\n"
            . "Be Fit Flex Gym · Santa Rosa and Cabuyao, Laguna";
        try {
            $emailSent = send_email(
                $email,
                'Verify your Be Fit Flex Gym email address',
                $verificationHtml,
                $verificationText
            );
        } catch (Throwable $ex) {
            $logRegistrationError('Account verification email failed', $ex);
            $emailSent = false;
        }

        try {
            notify($userId, 'system', 'Welcome to Be Fit Flex Gym',
              'Your member profile ' . $memberId . ' was created. Verify your email address before signing in.',
                'dumbbell', 'danger', 'member/dashboard.php', 'normal', false);
        } catch (Throwable $ex) {
            $logRegistrationError('Account welcome notification failed', $ex);
        }

        if ($emailSent) {
            flash('Account created. Check your email to verify it before signing in.');
        } else {
            flash('Account created, but the verification email could not be sent. Contact the gym for help activating your account.', 'err');
        }
        redirect('index.php');
    }
}

$flashes = take_flash();
$turnstileReady = turnstile_is_configured();
$hasLogo = is_file(__DIR__ . '/assets/img/logo.png');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Join · <?= APP_NAME ?></title>
<link rel="stylesheet" href="assets/css/glass.css">
<link rel="stylesheet" href="assets/css/login.css?v=3">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
</head>
<body class="auth-page">
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
  <div class="auth-card glass register-card">
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

      <?php if ($turnstileReady): ?>
      <div class="turnstile-row"><div class="cf-turnstile" data-sitekey="<?= e(turnstile_site_key()) ?>" data-action="register" data-size="flexible"></div></div>
      <?php else: ?>
      <p class="note">Registration is temporarily unavailable because security verification is not configured. Please contact the gym.</p>
      <?php endif; ?>
      <button class="btn red full" type="submit" <?= $turnstileReady ? '' : 'disabled' ?>><i class="fa-solid fa-user-plus"></i> Create my account</button>
    </form>

    <p class="auth-alt">Already a member? <a href="index.php">Sign in</a></p>
  </div>
</div>
<?php if ($turnstileReady): ?><script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script><?php endif; ?>
<script src="assets/js/app.js"></script>
</body>
</html>
