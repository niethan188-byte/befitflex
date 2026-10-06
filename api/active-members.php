<?php
/** Active member list for the admin dashboard card. */
declare(strict_types=1);

define('IN_SUBDIR', true);
require __DIR__ . '/../includes/auth.php';

require_role('admin');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$members = rows(
    'SELECT member_name, membership_type, join_date
       FROM members
      WHERE status = ?
      ORDER BY member_name',
    ['Active']
);

echo json_encode(['ok' => true, 'members' => $members]);