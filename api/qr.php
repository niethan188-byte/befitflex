<?php
declare(strict_types=1);

require __DIR__ . '/../includes/auth.php';

$type = (string) ($_GET['type'] ?? '');
$id = strtoupper(trim((string) ($_GET['id'] ?? '')));
$payload = '';

if ($type === 'member' && preg_match('/^MEM\d{4,}$/', $id)) {
    $u = require_role('member', 'admin');
    if ($u['type'] === 'member' && ($_SESSION['member_id'] ?? '') !== $id) {
        http_response_code(403);
        exit('Forbidden');
    }
    $member = row(
        "SELECT m.member_id, m.status,
                (SELECT p.payment_status FROM payments p
                   WHERE p.member_id = m.member_id
                   ORDER BY p.created_at DESC LIMIT 1) latest_payment_status
           FROM members m WHERE m.member_id = ?",
        [$id]
    );
    if (!$member) {
        http_response_code(404);
        exit('Member not found');
    }
    if ($member['status'] !== 'Active') {
        http_response_code(403);
        exit('Membership is not active');
    }
    if ($member['latest_payment_status'] !== 'Paid') {
        http_response_code(403);
        exit('Latest membership payment is not paid');
    }
    $payload = 'BEFITFLEX:' . $id;
} elseif ($type === 'day_pass' && preg_match('/^DAY\d{5,}$/', $id)) {
    require_role('admin');
    if (!row('SELECT pass_id FROM day_passes WHERE pass_id = ?', [$id])) {
        http_response_code(404);
        exit('Day pass not found');
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
    'svgViewBoxSize' => 33,
    'addQuietzone' => true,
    'imageBase64' => false,
]);

header('Content-Type: image/svg+xml; charset=utf-8');
header('Cache-Control: private, max-age=300');
echo (new chillerlan\QRCode\QRCode($options))->render($payload);
