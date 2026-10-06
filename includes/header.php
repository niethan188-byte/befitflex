<?php
/** Opening chrome for every signed-in page. Expects $title and $page. */
declare(strict_types=1);

/* Compress the response before it leaves PHP. A dashboard is mostly repeated
   markup, so gzip typically cuts it by 75-85% for the cost of a few ms. */
if (!ob_get_level() && extension_loaded('zlib') && !headers_sent()) {
    ob_start('ob_gzhandler');
}
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');

/* Cache-bust the stylesheet on change instead of on every request. */
$cssFile = __DIR__ . '/../assets/css/glass.css';
$jsFile  = __DIR__ . '/../assets/js/app.js';
$uiCss   = __DIR__ . '/../assets/css/ui.css';
$uiJs    = __DIR__ . '/../assets/js/ui.js';
$uiCssV  = is_file($uiCss) ? filemtime($uiCss) : 1;
$uiJsV   = is_file($uiJs)  ? filemtime($uiJs)  : 1;
$cssV    = is_file($cssFile) ? filemtime($cssFile) : 1;
$jsV     = is_file($jsFile)  ? filemtime($jsFile)  : 1;

$u    = current_user();
$b    = base_url();
$logo = __DIR__ . '/../assets/img/logo.png';
$hasLogo = is_file($logo);
$flashes = take_flash();
$unread  = $u ? unread_count($u['id']) : 0;
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#121212">
<meta name="description" content="Be Fit Flex Gym Laguna — membership, training and payments.">
<link rel="manifest" href="<?= $b ?>manifest.webmanifest">
<script>
/* Apply the stored theme before first paint, so the page never flashes
   the wrong colour scheme while the stylesheet loads. */
try {
  var _t = localStorage.getItem('bff_theme') || 'dark';
  var _d = localStorage.getItem('bff_density') || 'comfortable';
  document.documentElement.dataset.theme = _t;
  document.documentElement.dataset.density = _d;
} catch (e) {}
</script>
<title><?= e($title ?? 'Dashboard') ?> · <?= APP_NAME ?></title>
<?php if ($hasLogo): ?><link rel="icon" href="<?= $b ?>assets/img/logo.png"><?php endif; ?>
<link rel="stylesheet" href="<?= $b ?>assets/css/glass.css?v=<?= $cssV ?>">
<link rel="stylesheet" href="<?= $b ?>assets/css/ui.css?v=<?= $uiCssV ?>">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
</head>
<body data-base="<?= $b ?>">

<a class="skip" href="#main">Skip to content</a>

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

<div class="shell">
<?php include __DIR__ . '/sidebar.php'; ?>
  <main class="main" id="main">
    <div class="topbar">
      <button class="btn icon menu-btn" onclick="toggleSidebar()" aria-label="Open menu"><i class="fa-solid fa-bars"></i></button>
      <div>
        <h1><?= e($title ?? 'Dashboard') ?></h1>
        <?php if (!empty($subtitle)): ?><div class="sub"><?= e($subtitle) ?></div><?php endif; ?>
      </div>
      <div class="spacer"></div>

      <button class="cmd-trigger" onclick="openCmd()" aria-label="Search — Ctrl K">
        <i class="fa-solid fa-magnifying-glass"></i>
        <span class="t">Search anything</span>
        <span class="k"><kbd>Ctrl</kbd><kbd>K</kbd></span>
      </button>

      <button class="icon-btn" onclick="toggleTheme()" title="Light or dark" aria-label="Toggle theme">
        <i id="themeIcon" class="fa-solid fa-sun"></i>
      </button>

      <button class="icon-btn" onclick="toggleDensity()" title="Row height" aria-label="Toggle density">
        <i id="densityIcon" class="fa-solid fa-bars"></i>
      </button>

      <a class="btn icon" href="<?= $b ?><?= $u['type'] === 'member' ? 'member' : 'admin' ?>/notifications.php" title="Notifications">
        <i class="fa-solid fa-bell"></i>
        <?php if ($unread): ?><span class="chip bad" style="margin-left:4px"><?= $unread ?></span><?php endif; ?>
      </a>
      <div class="pill-user">
        <span class="avatar"><?= e(strtoupper(substr($u['name'] ?: 'U', 0, 1))) ?></span>
        <span><?= e($u['name']) ?></span>
      </div>
    </div>
