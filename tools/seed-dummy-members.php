<?php
declare(strict_types=1);

require __DIR__ . '/../config/db.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

$pdo = db();
$count = 100;
$emailPrefix = 'dummy.member.';
$password = 'dummy123';
$methods = ['Cash', 'Card', 'GCash', 'Bank Transfer', 'Maya'];
$membershipTypes = ['Monthly', 'Quarterly', 'Annual'];
$amounts = ['Monthly' => 1500.00, 'Quarterly' => 4200.00, 'Annual' => 12000.00];
$firstNames = ['Alex', 'Bianca', 'Carlo', 'Diana', 'Emil', 'Faith', 'Gabriel', 'Hana', 'Ivan', 'Jessa'];
$lastNames = ['Santos', 'Reyes', 'Cruz', 'Garcia', 'Mendoza', 'Torres', 'Navarro', 'Ramos', 'Flores', 'Castillo'];

$existing = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE email LIKE 'dummy.member.%@example.com'")->fetchColumn();
if ($existing > 0) {
    fwrite(STDERR, "Dummy member accounts already exist ($existing found); no rows were changed.\n");
    exit(1);
}

$insertUser = $pdo->prepare(
    'INSERT INTO users (email, password, user_type, email_verified_at) VALUES (?, ?, \'member\', ?)'
);
$insertMember = $pdo->prepare(
    'INSERT INTO members (member_id, user_id, member_name, contact_number, email, membership_type, join_date, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
);
$insertPayment = $pdo->prepare(
    'INSERT INTO payments (payment_id, member_id, amount, payment_method, payment_status, payment_date, notes) VALUES (?, ?, ?, ?, ?, ?, ?)'
);

$pdo->beginTransaction();
try {
    for ($index = 1; $index <= $count; $index++) {
        $memberId = sprintf('DUMMY%04d', $index);
        $email = sprintf('%s%03d@example.com', $emailPrefix, $index);
        $name = $firstNames[($index - 1) % count($firstNames)] . ' ' . $lastNames[(int) floor(($index - 1) / count($firstNames))];
        $joinDate = (new DateTimeImmutable('2025-01-01'))->modify('+' . (($index * 13) % 620) . ' days');
        $membershipType = $membershipTypes[($index - 1) % count($membershipTypes)];
        $status = $index % 9 === 0 ? 'Expired' : ($index % 7 === 0 ? 'Inactive' : 'Active');
        $paymentMethod = $methods[($index - 1) % count($methods)];
        $paymentStatus = $index % 11 === 0 ? 'Overdue' : ($index % 6 === 0 ? 'Pending' : 'Paid');
        $paymentDate = $paymentStatus === 'Pending'
            ? null
            : $joinDate->modify('+' . ($index % 5) . ' days')->format('Y-m-d');

        $insertUser->execute([$email, $password, $joinDate->format('Y-m-d 09:00:00')]);
        $userId = (int) $pdo->lastInsertId();
        $insertMember->execute([
            $memberId,
            $userId,
            $name,
            sprintf('09%09d', 100000000 + $index),
            $email,
            $membershipType,
            $joinDate->format('Y-m-d'),
            $status,
        ]);
        $insertPayment->execute([
            sprintf('DUMPAY%04d', $index),
            $memberId,
            $amounts[$membershipType],
            $paymentMethod,
            $paymentStatus,
            $paymentDate,
            'Generated dummy payment for test data',
        ]);
    }

    $pdo->commit();
    echo "Created $count dummy member accounts and $count payment records.\n";
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Seed failed: ' . $error->getMessage() . "\n");
    exit(1);
}