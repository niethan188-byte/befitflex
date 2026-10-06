<?php
require __DIR__ . '/includes/auth.php';
$u = current_user();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Data Privacy Notice · <?= APP_NAME ?></title>
<link rel="stylesheet" href="assets/css/glass.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
</head>
<body>
<div class="field"><div class="blob a"></div><div class="blob b"></div><div class="blob c"></div></div>

<div style="max-width:820px;margin:0 auto;padding:40px 22px 70px">
  <div class="panel glass">
    <h2><i class="fa-solid fa-user-shield"></i> Data Privacy Notice</h2>
    <p class="note" style="margin-bottom:18px">
      Be Fit Flex Gym — Laguna processes personal data in accordance with
      Republic Act No. 10173, the Data Privacy Act of 2012.
    </p>

    <h3 style="font-size:14px;margin:20px 0 8px">What we collect</h3>
    <p class="note">Your name, email address, contact number, membership type and dates,
    attendance records, class enrollments, payment records, and your assigned workout plans.</p>

    <h3 style="font-size:14px;margin:20px 0 8px">Why we collect it</h3>
    <p class="note">To administer your membership, schedule sessions and classes,
    record attendance, process dues, and contact you about your account. We do not
    sell your data and we do not share it with third parties for marketing.</p>

    <h3 style="font-size:14px;margin:20px 0 8px">How long we keep it</h3>
    <p class="note">Membership and payment records are retained for the duration of
    your membership and for five years afterward for accounting and audit purposes.
    Attendance logs are retained for two years.</p>

    <h3 style="font-size:14px;margin:20px 0 8px">Your rights as a data subject</h3>
    <p class="note">You have the right to be informed, to object, to access, to
    rectify, to erasure or blocking, to damages, to data portability, and to file a
    complaint with the National Privacy Commission. To exercise any of these,
    contact the front desk or email <?= APP_EMAIL ?>.</p>

    <h3 style="font-size:14px;margin:20px 0 8px">Security</h3>
    <p class="note">Passwords are stored as one-way bcrypt hashes. Access to member
    records is limited by role: administrators manage the roster, and members see only their own records.</p>

    <p style="margin-top:26px">
      <a class="btn red" href="<?= $u ? home_for($u['type']) : 'index.php' ?>">
        <i class="fa-solid fa-arrow-left"></i> Back
      </a>
    </p>
  </div>
</div>
<script src="assets/js/app.js"></script>
</body>
</html>
