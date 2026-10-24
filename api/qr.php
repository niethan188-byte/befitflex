<?php
declare(strict_types=1);

require __DIR__ . '/../includes/auth.php';

header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');

$type = (string) ($_GET['type'] ?? '');
$id = strtoupper(trim((string) ($_GET['id'] ?? '')));
$payload = '';

if ($type === 'day_pass_purchase') {
    require_role('admin', 'member');
    $configuredBase = getenv('BEFITFLEX_APP_URL');
    if (is_string($configuredBase) && trim($configuredBase) !== '') {
        $base = rtrim(trim($configuredBase), '/');
    } else {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
        $origin = $scheme . '://' . $host;
        if ($host === '' || filter_var($origin, FILTER_VALIDATE_URL) === false
            || parse_url($origin, PHP_URL_USER) !== null || parse_url($origin, PHP_URL_PASS) !== null) {
            http_response_code(400);
            exit('Application URL is not valid');
        }
        $base = rtrim($origin . dirname(dirname($_SERVER['SCRIPT_NAME'])), '/');
    }
    $payload = $base . '/day-pass.php';
} elseif ($type === 'member' && preg_match('/^(?:MEM|DUMMY)\d{4,}$/', $id)) {
    $u = require_role('member', 'admin');
    if ($u['type'] === 'member' && ($_SESSION['member_id'] ?? '') !== $id) {
        http_response_code(403);
        exit('Forbidden');
    }
    $member = row(
        "SELECT m.member_id, m.status,
                (SELECT p.payment_status FROM payments p
                   WHERE p.member_id = m.member_id
                   ORDER BY p.created_at DESC, p.payment_id DESC LIMIT 1) latest_payment_status
           FROM members m WHERE m.member_id = ?",
        [$id]
    );
    if (!$member) {
        http_response_code(404);
        exit('Member not found');
    }

    if ($member['status'] === 'Active' && $member['latest_payment_status'] === 'Paid') {
        $payload = 'BEFITFLEX:' . $id;
    } else {
        $pass = row(
            "SELECT pass_id FROM day_passes
              WHERE member_id = ? AND visit_date >= CURDATE() AND pass_status = 'Paid'
              ORDER BY visit_date, created_at DESC, pass_id DESC LIMIT 1",
            [$id]
        );
        if (!$pass) {
            http_response_code(403);
            exit('No active paid membership or upcoming paid day pass');
        }
        $payload = 'BEFITFLEX:' . $pass['pass_id'];
    }
} elseif ($type === 'day_pass' && preg_match('/^DAY\d{5,}$/', $id)) {
    $user = current_user();
    $token = (string) ($_GET['token'] ?? '');
    $pass = row(
        "SELECT pass_id, public_token FROM day_passes
          WHERE pass_id = ? AND visit_date >= CURDATE() AND pass_status = 'Paid'",
        [$id]
    );
    $admin = $user && $user['type'] === 'admin';
    $tokenValid = $pass && $token !== '' && is_string($pass['public_token'])
        && $pass['public_token'] !== '' && hash_equals($pass['public_token'], $token);
    if (!$pass || (!$admin && !$tokenValid)) {
        http_response_code(404);
        exit('Paid day pass not found');
    }
    $payload = 'BEFITFLEX:' . $id;
} else {
    http_response_code(400);
    exit('Invalid QR request');
}

require_once __DIR__ . '/../vendor/autoload.php';

$options = new chillerlan\QRCode\QROptions([
    'outputType' => chillerlan\QRCode\QRCode::OUTPUT_MARKUP_SVG,
    'eccLevel' => chillerlan\QRCode\QRCode::ECC_M,
    'dataModeOverride' => 'Byte',
    'addQuietzone' => true,
    'imageBase64' => false,
]);

header('Content-Type: image/svg+xml; charset=utf-8');
header('Cache-Control: private, max-age=300');
echo (new chillerlan\QRCode\QRCode($options))->render($payload);
