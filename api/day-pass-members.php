<?php
declare(strict_types=1);

define('IN_SUBDIR', true);
require __DIR__ . '/../includes/auth.php';
require_role('admin');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$query = trim((string) ($_GET['q'] ?? ''));
if (mb_strlen($query) < 2) {
    exit(json_encode(['ok' => true, 'members' => []]));
}

$like = '%' . mb_substr($query, 0, 80) . '%';
$members = [];
foreach (rows(
    "SELECT m.member_id, m.member_name, m.contact_number, m.contact_number_encrypted,
            m.membership_type, m.status,
            (SELECT p.payment_status FROM payments p
              WHERE p.member_id = m.member_id
              ORDER BY p.created_at DESC, p.payment_id DESC LIMIT 1) latest_payment_status
       FROM members m
      WHERE m.member_name LIKE ?
      ORDER BY m.member_name
      LIMIT 8",
    [$like]
) as $member) {
    $contact = member_contact($member);
    $members[] = [
        'id' => $member['member_id'],
        'name' => $member['member_name'],
        'contact' => $contact === 'Protected contact' ? '' : $contact,
        'active_monthly' => $member['membership_type'] === 'Monthly'
            && $member['status'] === 'Active'
            && $member['latest_payment_status'] === 'Paid',
    ];
}

echo json_encode(['ok' => true, 'members' => $members], JSON_UNESCAPED_UNICODE);
