<?php
require __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit(json_encode(['ok' => false, 'error' => 'POST required.']));
}

$sentToken = $_POST['csrf'] ?? '';
$sessionToken = $_SESSION['csrf'] ?? '';
if (!is_string($sentToken) || !is_string($sessionToken) || $sessionToken === ''
    || !hash_equals($sessionToken, $sentToken)) {
    http_response_code(419);
    exit(json_encode(['ok' => false, 'error' => 'Your session expired. Reload the page and try again.']));
}

$email = trim((string) ($_POST['email'] ?? ''));
if ($email === '' || mb_strlen($email) > 255
    || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
    http_response_code(422);
    exit(json_encode(['ok' => false, 'error' => 'Enter a valid email address.']));
}

$memberExists = (bool) scalar(
    "SELECT COUNT(*)
       FROM users u
       JOIN members m ON m.user_id = u.user_id
      WHERE u.email = ? AND u.user_type = 'member'",
    [$email]
);

echo json_encode(['ok' => true, 'exists' => $memberExists]);
