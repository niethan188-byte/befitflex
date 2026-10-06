<?php
require __DIR__ . '/includes/auth.php';

if (current_user()) {
    redirect(home_for($_SESSION['user_type']));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $email = trim((string) ($_POST['email'] ?? ''));
    $pass  = (string) ($_POST['password'] ?? '');

    $user = attempt_login($email, $pass);
    if ($user) {
        start_session_for($user);
        log_activity('Sign in', 'Authentication', 'Signed in as ' . $user['user_type']);
        redirect(home_for($user['user_type']));
    }
    flash('That email and password do not match an account.', 'err');
}

$flashes = take_flash();
$hasLogo   = is_file(__DIR__ . '/assets/img/logo.png');
$hasBanner = is_file(__DIR__ . '/assets/img/banner.png');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sign in · <?= APP_NAME ?> <?= APP_TAGLINE ?></title>
<?php if ($hasLogo): ?><link rel="icon" href="assets/img/logo.png"><?php endif; ?>
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
  <div class="auth-card glass">
    <div class="auth-logo">
      <?php if ($hasLogo): ?>
        <img src="assets/img/logo.png" alt="Be Fit Flex Gym">
      <?php else: ?>
        <svg viewBox="0 0 100 100" fill="none"><path d="M50 4 92 28v48L50 100 8 76V28z" stroke="#E53935" stroke-width="6"/><path d="M34 30h26M34 50h22M34 70h26" stroke="#E53935" stroke-width="7" stroke-linecap="round"/></svg>
      <?php endif; ?>
    </div>

    <h1>BE FIT FLEX GYM</h1>
    <p class="tag">Laguna</p>

    <form method="post" autocomplete="on">
      <?= csrf_field() ?>
      <div class="field-row">
        <label for="email">Email address</label>
        <input type="email" id="email" name="email" required autofocus
               placeholder="you@example.com" value="<?= e($_POST['email'] ?? '') ?>">
      </div>
      <div class="field-row">
        <label for="password">Password</label>
        <input type="password" id="password" name="password" required placeholder="••••••••">
      </div>
      <button class="btn red full" type="submit"><i class="fa-solid fa-right-to-bracket"></i> Sign in</button>
    </form>

    <p class="auth-alt">New here? <a href="register.php">Create a member account</a></p>

    <div class="demo-keys">
      <div class="t">Demo accounts — click to fill</div>
      <button class="demo-key" type="button" onclick="fill('admin@befitflexgym.com','admin123')">
        <i class="fa-solid fa-shield-halved"></i><b>Admin</b> admin@befitflexgym.com
      </button>
      <button class="demo-key" type="button" onclick="fill('anna@example.com','member123')">
        <i class="fa-solid fa-user"></i><b>Member</b> anna@example.com
      </button>
    </div>

    <div class="offers" style="margin-top:22px">
      <?php foreach (array_slice(APP_OFFERS, 0, 4) as $o): ?>
        <span class="offer"><?= e($o) ?></span>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<script>
function fill(email, pass) {
  document.getElementById('email').value = email;
  document.getElementById('password').value = pass;
  document.getElementById('password').focus();
}
</script>
<script src="assets/js/app.js"></script>
<script>
if ('serviceWorker' in navigator) {
  window.addEventListener('load', () => {
    navigator.serviceWorker.register('sw.js', { scope: './' }).catch(() => {});
  });
}
</script>
</body>
</html>
