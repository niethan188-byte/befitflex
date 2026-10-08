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

$action = (string) ($_GET['action'] ?? '');
$type = $user['user_type'];
$items = [];

if ($action === 'roster' && $type === 'admin') {
    foreach (rows('SELECT member_id, member_name, membership_type, status FROM members ORDER BY member_name LIMIT 50') as $item) {
        $items[] = ['title' => $item['member_name'], 'subtitle' => $item['membership_type'], 'status' => $item['status'], 'icon' => 'person'];
    }
} elseif ($action === 'schedule' && $type === 'admin') {
    foreach (rows('SELECT s.session_name, s.session_date, s.session_time, s.session_status, m.member_name FROM sessions s LEFT JOIN members m ON m.member_id = s.member_id WHERE s.session_date >= CURDATE() ORDER BY s.session_date, s.session_time LIMIT 30') as $item) {
        $items[] = ['title' => $item['session_name'], 'subtitle' => ($item['member_name'] ?? 'Open session') . ' · ' . date('M j, g:i A', strtotime($item['session_date'] . ' ' . $item['session_time'])), 'status' => $item['session_status'], 'icon' => 'calendar'];
    }
} elseif ($action === 'insights' && $type === 'admin') {
    $head = row("SELECT (SELECT COUNT(*) FROM members) members, (SELECT COUNT(*) FROM members WHERE status = 'Active') active, (SELECT COUNT(*) FROM attendance WHERE attendance_date = CURDATE()) visits, (SELECT COUNT(*) FROM sessions WHERE session_status = 'Scheduled' AND session_date >= CURDATE()) upcoming") ?? [];
    foreach ($head as $key => $value) {
        $items[] = ['title' => ucwords($key), 'subtitle' => (string) $value, 'status' => '', 'icon' => 'insights'];
    }
} elseif ($action === 'classes' && $type === 'member') {
    foreach (rows('SELECT class_name, schedule_day, schedule_time, class_status FROM classes WHERE class_status = "Active" ORDER BY schedule_day, schedule_time LIMIT 30') as $item) {
        $items[] = ['title' => $item['class_name'], 'subtitle' => $item['schedule_day'] . ' · ' . date('g:i A', strtotime($item['schedule_time'])), 'status' => $item['class_status'], 'icon' => 'calendar'];
    }
} elseif ($action === 'profile') {
    $profile = $type === 'member'
        ? row('SELECT member_name name, email, contact_number, contact_number_encrypted, membership_type, status FROM members WHERE user_id = ?', [$user['user_id']])
        : ['name' => 'Administrator', 'email' => $user['email']];
    if ($type === 'member' && $profile) {
        $profile['contact_number'] = member_contact($profile);
        unset($profile['contact_number_encrypted']);
    }
    foreach (($profile ?: []) as $key => $value) {
        $items[] = ['title' => ucwords(str_replace('_', ' ', $key)), 'subtitle' => (string) $value, 'status' => '', 'icon' => 'profile'];
    }
} elseif ($action === 'notifications') {
    foreach (rows('SELECT notification_title, notification_message, priority, created_at FROM notifications WHERE user_id = ? ORDER BY is_read, created_at DESC LIMIT 40', [$user['user_id']]) as $item) {
        $items[] = ['title' => $item['notification_title'], 'subtitle' => date('M j, Y · g:i A', strtotime($item['created_at'])), 'status' => $item['notification_message'], 'icon' => 'bell'];
    }
} elseif ($action === 'payments') {
    if ($type === 'member') {
        $member = row('SELECT member_id FROM members WHERE user_id = ?', [$user['user_id']]);
        $paymentRows = rows('SELECT payment_id, amount, payment_status, payment_date, payment_method FROM payments WHERE member_id = ? ORDER BY payment_date DESC LIMIT 40', [$member['member_id'] ?? '']);
    } else {
        $paymentRows = rows('SELECT p.payment_id, p.amount, p.payment_status, p.payment_date, p.payment_method, m.member_name FROM payments p JOIN members m ON m.member_id = p.member_id ORDER BY p.payment_date DESC LIMIT 40');
    }
    foreach ($paymentRows as $item) {
        $person = $item['member_name'] ?? 'Payment';
        $items[] = ['title' => $person . ' · ' . $item['payment_id'], 'subtitle' => date('M j, Y', strtotime($item['payment_date'])), 'status' => '&#8369;' . number_format((float) $item['amount'], 2) . ' · ' . $item['payment_status'] . ' · ' . $item['payment_method'], 'icon' => 'wallet'];
    }
} elseif ($action === 'attendance') {
    if ($type === 'member') {
        $member = row('SELECT member_id FROM members WHERE user_id = ?', [$user['user_id']]);
        $attendanceRows = rows('SELECT attendance_date, check_in_time, check_out_time FROM attendance WHERE member_id = ? ORDER BY attendance_date DESC, check_in_time DESC LIMIT 40', [$member['member_id'] ?? '']);
    } else {
        $attendanceRows = rows('SELECT a.attendance_date, a.check_in_time, a.check_out_time, m.member_name FROM attendance a JOIN members m ON m.member_id = a.member_id ORDER BY a.attendance_date DESC, a.check_in_time DESC LIMIT 40');
    }
    foreach ($attendanceRows as $item) {
        $person = $item['member_name'] ?? 'Gym visit';
        $items[] = ['title' => $person, 'subtitle' => date('M j, Y · g:i A', strtotime($item['check_in_time'])), 'status' => $item['check_out_time'] ? 'Checked out ' . date('g:i A', strtotime($item['check_out_time'])) : 'Currently checked in', 'icon' => 'fitness'];
    }
}

echo json_encode(['ok' => true, 'items' => $items]);