<?php
/**
 * Global search behind the command palette (Ctrl+K).
 *
 * Results are scoped by role in SQL and do not expose records outside
 * the current user scope.
 */
declare(strict_types=1);

define('IN_SUBDIR', true);
require __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$u = current_user();
if (!$u) {
    http_response_code(401);
    exit(json_encode(['ok' => false, 'error' => 'not_authenticated']));
}

$q = trim((string) ($_GET['q'] ?? ''));
if (mb_strlen($q) > 80) {
    $q = mb_substr($q, 0, 80);
}
if (mb_strlen($q) < 2) {
    exit(json_encode(['ok' => true, 'results' => []]));
}

$like = '%' . $q . '%';
$out  = [];

if ($u['type'] === 'admin') {

    foreach (rows(
        'SELECT member_id, member_name, email, membership_type, status FROM members
          WHERE member_name LIKE ? OR email LIKE ? OR member_id LIKE ?
          ORDER BY member_name LIMIT 6', [$like, $like, $like]
    ) as $m) {
        $out[] = ['group' => 'Members', 'icon' => 'user', 'label' => $m['member_name'],
                  'sub' => $m['member_id'] . ' · ' . $m['membership_type'] . ' · ' . $m['status'],
                  'href' => 'admin/members.php?q=' . urlencode($m['member_name'])];
    }

    foreach (rows(
        'SELECT class_id, class_name, schedule_day FROM classes
          WHERE class_name LIKE ? ORDER BY class_name LIMIT 4', [$like]
    ) as $c) {
        $out[] = ['group' => 'Classes', 'icon' => 'people-group', 'label' => $c['class_name'],
                  'sub' => $c['class_id'] . ' · ' . $c['schedule_day'],
                  'href' => 'admin/classes.php'];
    }

    foreach (rows(
        'SELECT p.payment_id, p.amount, p.payment_status, m.member_name
           FROM payments p JOIN members m ON m.member_id = p.member_id
          WHERE p.payment_id LIKE ? OR m.member_name LIKE ?
          ORDER BY p.created_at DESC LIMIT 4', [$like, $like]
    ) as $p) {
        $out[] = ['group' => 'Payments', 'icon' => 'receipt', 'label' => $p['payment_id'],
                  'sub' => $p['member_name'] . ' · ' . number_format((float) $p['amount'], 2) . ' · ' . $p['payment_status'],
                  'href' => 'admin/payments.php'];
    }

} else {

    $mid = $_SESSION['member_id'] ?? '';
    foreach (rows(
        'SELECT c.class_id, c.class_name, c.schedule_day
           FROM classes c
          WHERE c.class_status = "Active" AND c.class_name LIKE ?
          LIMIT 5', [$like]
    ) as $c) {
        $out[] = ['group' => 'Classes', 'icon' => 'people-group', 'label' => $c['class_name'],
                  'sub' => $c['schedule_day'], 'href' => 'member/classes.php'];
    }
    foreach (rows(
        'SELECT payment_id, amount, payment_status FROM payments
          WHERE member_id = ? AND payment_id LIKE ? LIMIT 3', [$mid, $like]
    ) as $p) {
        $out[] = ['group' => 'My payments', 'icon' => 'receipt', 'label' => $p['payment_id'],
                  'sub' => number_format((float) $p['amount'], 2) . ' · ' . $p['payment_status'],
                  'href' => 'member/payments.php'];
    }
}

echo json_encode(['ok' => true, 'q' => $q, 'results' => $out], JSON_UNESCAPED_UNICODE);
