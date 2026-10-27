<?php
require __DIR__ . '/includes/auth.php';
$u = current_user();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#121212">
<title>Data Privacy Notice · <?= APP_NAME ?></title>
<script src="assets/js/theme.js?v=<?= filemtime(__DIR__ . '/assets/js/theme.js') ?>"></script>
<link rel="stylesheet" href="assets/css/glass.css">
<link rel="stylesheet" href="assets/css/ui.css?v=<?= filemtime(__DIR__ . '/assets/css/ui.css') ?>">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
</head>
<body class="privacy-page">
<div class="field"><div class="blob a"></div><div class="blob b"></div><div class="blob c"></div></div>
<button class="icon-btn privacy-theme-toggle" type="button" onclick="toggleTheme()" aria-label="Toggle theme" title="Toggle light or dark theme">
  <i id="themeIcon" class="fa-solid fa-sun" aria-hidden="true"></i>
</button>

<main class="privacy-wrap">
  <article class="panel glass privacy-panel">
    <p class="privacy-eyebrow">BE FIT FLEX GYM · LAGUNA</p>
    <h1><i class="fa-solid fa-user-shield"></i> Data Privacy Notice</h1>
    <p class="note privacy-intro">
      Be Fit Flex Gym — Laguna processes personal data in accordance with
      Republic Act No. 10173, the Data Privacy Act of 2012.
    </p>

    <h2>What we collect</h2>
    <p class="note">Your name, email address, contact number, membership type and dates,
    attendance records, class enrollments, and payment records.</p>

    <h2>Why we collect it</h2>
    <p class="note">To administer your membership, schedule sessions and classes,
    record attendance, process dues, and contact you about your account. We do not
    sell your data and we do not share it with third parties for marketing.</p>

    <h2>How long we keep it</h2>
    <p class="note">Membership and payment records are retained for the duration of
    your membership and for five years afterward for accounting and audit purposes.
    Attendance logs are retained for two years.</p>

    <h2>Your rights as a data subject</h2>
    <p class="note">You have the right to be informed, to object, to access, to
    rectify, to erasure or blocking, to damages, to data portability, and to file a
    complaint with the National Privacy Commission. To exercise any of these,
    contact the front desk or email <?= APP_EMAIL ?>.</p>

    <h2>Security</h2>
    <p class="note">Passwords are stored as one-way bcrypt hashes. Access to member
    records is limited by role: administrators manage the roster, and members see only their own records.</p>

    <div class="privacy-actions">
      <a class="btn red" href="<?= $u ? home_for($u['type']) : 'index.php' ?>">
        <i class="fa-solid fa-arrow-left"></i> Back
      </a>
    </div>
  </article>
</main>
<script src="assets/js/ui.js"></script>
</body>
</html>
