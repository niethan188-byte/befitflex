<?php
declare(strict_types=1);

require __DIR__ . '/../config/config.php';
require __DIR__ . '/../config/db.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

$pdo = db();
$count = 100;
$progressTable = $pdo->query("SHOW TABLES LIKE 'member_progress'")->fetchColumn();
if (!$progressTable) {
    fwrite(STDERR, "The member progress table is missing; apply the database schema upgrade first.\n");
    exit(1);
}

$emailPrefix = 'dummy.member.';
$password = 'dummy123';
$methods = ['Cash', 'Card', 'GCash', 'Bank Transfer'];
$membershipType = 'Monthly';
$firstNames = ['Alex', 'Bianca', 'Carlo', 'Diana', 'Emil', 'Faith', 'Gabriel', 'Hana', 'Ivan', 'Jessa'];
$lastNames = ['Santos', 'Reyes', 'Cruz', 'Garcia', 'Mendoza', 'Torres', 'Navarro', 'Ramos', 'Flores', 'Castillo'];
$progressProfiles = [
    [-2.4, -1.2, -3.0, -1.2],
    [1.2, -0.8, -1.5, 0.2],
    [-3.6, -1.6, -4.2, -2.0],
    [0.2, -0.4, -0.8, -0.3],
    [0.8, -1.0, -1.2, 0.4],
];

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
    'INSERT INTO payments
        (payment_id, member_id, amount, payment_method, payment_status, payment_date, maya_checkout_id, notes)
     VALUES (?, ?, ?, ?, ?, ?, NULL, ?)'
);
$insertProgress = $pdo->prepare(
    'INSERT INTO member_progress
        (member_id, recorded_by_user_id, check_date, weight_kg, body_fat_percent, waist_cm, hips_cm, notes)
     VALUES (?, NULL, ?, ?, ?, ?, ?, ?)'
);
$today = new DateTimeImmutable('today');
$progressCount = 0;

$pdo->beginTransaction();
try {
    for ($index = 1; $index <= $count; $index++) {
        $memberId = sprintf('DUMMY%04d', $index);
        $email = sprintf('%s%03d@example.com', $emailPrefix, $index);
        $name = $firstNames[($index - 1) % count($firstNames)] . ' ' . $lastNames[(int) floor(($index - 1) / count($firstNames))];
        $joinDate = (new DateTimeImmutable('2025-01-01'))->modify('+' . (($index * 13) % 620) . ' days');
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
            MEMBERSHIP_PRICE[$membershipType],
            $paymentMethod,
            $paymentStatus,
            $paymentDate,
            'Generated dummy payment for test data',
        ]);

        $activityDays = match ($status) {
            'Expired' => 120,
            'Inactive' => 84,
            default => (int) $joinDate->diff($today)->format('%a'),
        };
        $progressEnd = $joinDate->modify('+' . $activityDays . ' days');
        if ($progressEnd > $today) {
            $progressEnd = $today;
        }
        $availableDays = (int) $joinDate->diff($progressEnd)->format('%a');
        $checkCount = min(5, intdiv($availableDays, 28) + 1);
        $progressStart = $progressEnd->modify('-' . (($checkCount - 1) * 28) . ' days');
        $profile = $progressProfiles[($index - 1) % count($progressProfiles)];
        $startWeight = 54 + (($index * 13) % 46);
        $startBodyFat = 17 + (($index * 7) % 18);
        $startWaist = 70 + (($index * 11) % 40);
        $startHips = 86 + (($index * 9) % 31);

        for ($point = 0; $point < $checkCount; $point++) {
            $factor = $checkCount > 1 ? $point / ($checkCount - 1) : 0;
            $checkDate = $progressStart->modify('+' . ($point * 28) . ' days')->format('Y-m-d');
            $insertProgress->execute([
                $memberId,
                $checkDate,
                number_format($startWeight + $profile[0] * $factor, 2, '.', ''),
                number_format($startBodyFat + $profile[1] * $factor, 2, '.', ''),
                number_format($startWaist + $profile[2] * $factor, 2, '.', ''),
                number_format($startHips + $profile[3] * $factor, 2, '.', ''),
                sprintf(
                    'SAMPLE DEMO ONLY — %s check-in %d of %d; synthetic example, not a real measurement.',
                    $memberId,
                    $point + 1,
                    $checkCount
                ),
            ]);
            $progressCount++;
        }
    }

    $pdo->commit();
    echo sprintf(
        "Created %d dummy member accounts, %d payment records, and %d clearly labeled sample progress checks.\n",
        $count,
        $count,
        $progressCount
    );
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Seed failed: ' . $error->getMessage() . "\n");
    exit(1);
}