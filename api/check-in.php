<?php
/**
 * Front-desk kiosk endpoint. POST { member_id } or { pass_id } to toggle a check-in.
 * Used by a tablet at the entrance; the admin Attendance screen calls the
 * same logic through its own form.
 */
declare(strict_types=1);

define('IN_SUBDIR', true);
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/mobile-auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit(json_encode(['ok' => false, 'error' => 'method_not_allowed']));
}

$u = current_user();
$mobileUser = mobile_user();
if (!$u && $mobileUser) {
    $u = ['type' => $mobileUser['user_type']];
}
if (!$u || $u['type'] !== 'admin') {
    http_response_code(401);
    exit(json_encode(['ok' => false, 'error' => 'not_authorised']));
}

$body = json_decode((string) file_get_contents('php://input'), true) ?: [];
$memberId = trim((string) ($body['member_id'] ?? ''));
$passId = trim((string) ($body['pass_id'] ?? ''));
$scanOnly = ($body['scan_only'] ?? false) === true;

if ($passId !== '' && preg_match('/^DAY\d{5,}$/', $passId)) {
    $pass = row('SELECT pass_id, visitor_name, visit_date, pass_status FROM day_passes WHERE pass_id = ?', [$passId]);
    if (!$pass) {
        http_response_code(404);
        exit(json_encode(['ok' => false, 'error' => 'day_pass_not_found']));
    }
    if ($pass['visit_date'] !== date('Y-m-d')) {
        http_response_code(403);
        exit(json_encode(['ok' => false, 'error' => 'day_pass_wrong_date']));
    }
    if ($pass['pass_status'] !== 'Paid') {
        http_response_code(403);
        exit(json_encode(['ok' => false, 'error' => 'day_pass_unpaid']));
    }

    $open = row('SELECT * FROM attendance WHERE day_pass_id = ? AND check_out_time IS NULL ORDER BY check_in_time DESC LIMIT 1', [$passId]);
    if ($open) {
        if ($scanOnly) {
            echo json_encode(['ok' => true, 'action' => 'already_checked_in', 'member' => $pass['visitor_name']]);
            exit;
        }
        db()->prepare('UPDATE attendance SET check_out_time = NOW() WHERE attendance_id = ?')->execute([$open['attendance_id']]);
        $mins = (int) round((time() - strtotime($open['check_in_time'])) / 60);
        echo json_encode(['ok' => true, 'action' => 'check_out', 'member' => $pass['visitor_name'], 'minutes' => $mins]);
    } else {
        $aid = next_id('attendance', 'attendance_id', 'ATT', 6);
        db()->prepare('INSERT INTO attendance (attendance_id,member_id,day_pass_id,check_in_time,attendance_date) VALUES (?,NULL,?,NOW(),CURDATE())')
            ->execute([$aid, $passId]);
        echo json_encode(['ok' => true, 'action' => 'check_in', 'member' => $pass['visitor_name'], 'at' => date('g:i A'), 'day_pass' => true]);
    }
    exit;
}

if (!preg_match('/^MEM\d{4,}$/', $memberId)) {
    http_response_code(422);
    exit(json_encode(['ok' => false, 'error' => 'invalid_member_id']));
}

$member = row(
    "SELECT m.member_id, m.member_name, m.status,
            (SELECT p.payment_status FROM payments p
               WHERE p.member_id = m.member_id
               ORDER BY p.created_at DESC LIMIT 1) latest_payment_status
       FROM members m WHERE m.member_id = ?",
    [$memberId]
);
if (!$member) {
    http_response_code(404);
    exit(json_encode(['ok' => false, 'error' => 'member_not_found']));
}
if ($member['status'] !== 'Active') {
    http_response_code(403);
    exit(json_encode(['ok' => false, 'error' => 'membership_' . strtolower($member['status'])]));
}
if ($member['latest_payment_status'] !== 'Paid') {
    http_response_code(403);
    exit(json_encode(['ok' => false, 'error' => 'member_payment_unpaid']));
}

$open = row('SELECT * FROM attendance WHERE member_id = ? AND check_out_time IS NULL ORDER BY check_in_time DESC LIMIT 1', [$memberId]);

if ($open) {
    if ($scanOnly) {
        echo json_encode(['ok' => true, 'action' => 'already_checked_in', 'member' => $member['member_name']]);
        exit;
    }
    db()->prepare('UPDATE attendance SET check_out_time = NOW() WHERE attendance_id = ?')
        ->execute([$open['attendance_id']]);
    $mins = (int) round((time() - strtotime($open['check_in_time'])) / 60);
    echo json_encode(['ok' => true, 'action' => 'check_out', 'member' => $member['member_name'], 'minutes' => $mins]);
} else {
    $aid = next_id('attendance', 'attendance_id', 'ATT', 6);
    db()->prepare('INSERT INTO attendance (attendance_id,member_id,check_in_time,attendance_date) VALUES (?,?,NOW(),CURDATE())')
        ->execute([$aid, $memberId]);
    echo json_encode(['ok' => true, 'action' => 'check_in', 'member' => $member['member_name'], 'at' => date('g:i A')]);
}
