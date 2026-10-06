<?php
require __DIR__ . '/includes/auth.php';

$token = trim((string) ($_GET['token'] ?? ''));
$verified = false;

if (preg_match('/^[a-f0-9]{64}$/', $token)) {
    $stmt = db()->prepare(
        'UPDATE users
            SET email_verified_at = NOW(), email_verification_token = NULL,
                email_verification_expires_at = NULL
          WHERE email_verification_token = ?
            AND email_verification_expires_at > NOW()
            AND email_verified_at IS NULL'
    );
    $stmt->execute([hash('sha256', $token)]);
    $verified = $stmt->rowCount() === 1;
}

flash($verified ? 'Email verified. You can now sign in.' : 'That verification link is invalid or expired.', $verified ? 'ok' : 'err');
redirect('index.php');