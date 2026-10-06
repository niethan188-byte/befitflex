<?php
declare(strict_types=1);

require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/mobile-auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit(json_encode(['ok' => false, 'error' => 'method_not_allowed']));
}

$body = json_decode((string) file_get_contents('php://input'), true) ?: [];
$email = trim((string) ($body['email'] ?? ''));
$password = (string) ($body['password'] ?? '');

if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
    http_response_code(422);
    exit(json_encode(['ok' => false, 'error' => 'invalid_credentials']));
}

$user = attempt_login($email, $password);
if (!$user) {
    http_response_code(401);
    exit(json_encode(['ok' => false, 'error' => 'invalid_credentials']));
}

$profile = ['id' => (int) $user['user_id'], 'type' => $user['user_type'], 'email' => $user['email']];
if ($user['user_type'] === 'member') {
    $profile += row('SELECT member_id, member_name name FROM members WHERE user_id = ?', [$user['user_id']]) ?: [];
} else {
    $profile['name'] = 'Administrator';
}

echo json_encode(['ok' => true, 'token' => mobile_token($profile), 'user' => $profile]);