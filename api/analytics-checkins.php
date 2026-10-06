<?php
/** Check-in members for one weekday/hour heatmap slot. */
declare(strict_types=1);

define('IN_SUBDIR', true);
require __DIR__ . '/../includes/auth.php';

require_role('admin');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$day = filter_input(INPUT_GET, 'day', FILTER_VALIDATE_INT);
$hour = filter_input(INPUT_GET, 'hour', FILTER_VALIDATE_INT);
$date = (string) ($_GET['date'] ?? '');
$hasDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1;

if ($hasDate) {
    $dateCheck = DateTime::createFromFormat('!Y-m-d', $date);
    $hasDate = $dateCheck !== false && $dateCheck->format('Y-m-d') === $date
        && $date >= date('Y-m-d', strtotime('-89 days')) && $date <= date('Y-m-d');
}

if (!$hasDate && ($day === false || $day === null || $day < 0 || $day > 6
    || $hour === false || $hour === null || $hour < 5 || $hour > 23)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'invalid_time_slot']);
    exit;
}

$where = $hasDate ? 'a.attendance_date = ?' : 'WEEKDAY(a.check_in_time) = ? AND HOUR(a.check_in_time) = ?';
$params = $hasDate ? [$date] : [$day, $hour];
$checkins = rows(
    "SELECT m.member_name, DATE_FORMAT(a.check_in_time, '%h:%i %p') check_in_time,
            DATE_FORMAT(a.check_out_time, '%h:%i %p') check_out_time
       FROM attendance a
       JOIN members m ON m.member_id = a.member_id
      WHERE {$where}
        AND a.check_in_time >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
      ORDER BY a.check_in_time DESC, m.member_name",
    $params
);

echo json_encode(['ok' => true, 'checkins' => $checkins]);