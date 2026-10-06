<?php
declare(strict_types=1);

require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/mobile-auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$user = mobile_user();
if (!$user) {
    http_response_code(401);
    exit(json_encode(['ok' => false, 'error' => 'not_authenticated']));
}

$type = $user['user_type'];
$stats = [];

if ($type === 'admin') {
    $head = row("SELECT
        (SELECT COUNT(*) FROM members) members,
        (SELECT COUNT(*) FROM members WHERE status = 'Active') active,
        (SELECT COUNT(*) FROM sessions WHERE session_status = 'Scheduled' AND session_date >= CURDATE()) upcoming,
        (SELECT COUNT(*) FROM attendance WHERE attendance_date = CURDATE()) visits") ?? [];
    $stats = [
        ['label' => 'Members', 'value' => (int) ($head['members'] ?? 0), 'icon' => 'people'],
        ['label' => 'Active', 'value' => (int) ($head['active'] ?? 0), 'icon' => 'check'],
        ['label' => 'Upcoming', 'value' => (int) ($head['upcoming'] ?? 0), 'icon' => 'calendar'],
        ['label' => 'Today visits', 'value' => (int) ($head['visits'] ?? 0), 'icon' => 'fitness'],
    ];
} else {
    $member = row('SELECT member_id FROM members WHERE user_id = ?', [$user['user_id']]);
    $mid = $member['member_id'] ?? '';
    $head = row("SELECT
        (SELECT COUNT(*) FROM attendance WHERE member_id = ? AND attendance_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)) visits,
        (SELECT COUNT(*) FROM payments WHERE member_id = ? AND payment_status IN ('Pending','Overdue')) dues", [$mid, $mid]) ?? [];
    $stats = [
        ['label' => 'Visits this month', 'value' => (int) ($head['visits'] ?? 0), 'icon' => 'fitness'],
        ['label' => 'Open payments', 'value' => (int) ($head['dues'] ?? 0), 'icon' => 'wallet'],
    ];
}

echo json_encode(['ok' => true, 'user' => $user, 'stats' => $stats]);