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
$liftingTable = $pdo->query("SHOW TABLES LIKE 'member_lifting_logs'")->fetchColumn();
if (!$progressTable || !$liftingTable) {
    fwrite(STDERR, "The member progress tables are missing; apply the database schema upgrade first.\n");
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
$liftingExercises = [
    'Squat', 'Bench Press', 'Deadlift', 'Overhead Press', 'Barbell Row',
    'Lat Pulldown', 'Leg Press', 'Dumbbell Press', 'Pull-up', 'Push-up',
];
$liftingProgressions = [
    [0, 2.5, 2.5, 5, 5],
    [0, 0, 2.5, 2.5, 5],
    [0, 2.5, 2.5, 2.5, 5],
    [0, 0, 0, 2.5, 2.5],
    [0, 0, 2.5, 5, 5],
];
$checkInNotes = [
    'Morning check-in before training.',
    'Measurements recorded before the workout.',
    'Routine check-in; keeping the schedule consistent.',
    'Rest-day measurements.',
    'Measured at the same time of day.',
];
$weightedWorkoutNotes = [
    'Completed all sets with consistent form.',
    'Kept the load steady and focused on controlled reps.',
    'Added a small increment after completing the previous session.',
    'Finished all sets with a controlled tempo.',
    'Focused on a full range of motion.',
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
$insertLiftingLog = $pdo->prepare(
    'INSERT INTO member_lifting_logs
        (member_id, logged_by_user_id, exercise_name, logged_date, sets_count, reps_per_set, weight_kg, notes)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
);
$today = new DateTimeImmutable('today');
$progressCount = 0;
$liftingLogCount = 0;

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
                $checkInNotes[($index + $point) % count($checkInNotes)],
            ]);
            $progressCount++;
        }

        $liftingCount = min(5, intdiv($activityDays, 21) + 1);
        $liftingStart = $progressEnd->modify('-' . (($liftingCount - 1) * 21) . ' days');
        $exerciseName = $liftingExercises[($index - 1) % count($liftingExercises)];
        $isBodyweight = in_array($exerciseName, ['Pull-up', 'Push-up'], true);
        $startLoad = $isBodyweight ? 0 : 20 + (($index * 7) % 81);
        $profileIndex = ($index - 1) % count($liftingProgressions);

        for ($point = 0; $point < $liftingCount; $point++) {
            $loggedDate = $liftingStart->modify('+' . ($point * 21) . ' days')->format('Y-m-d');
            $sets = 3;
            $reps = $isBodyweight
                ? 4 + (($index * 3) % 5) + $point
                : 6 + (($index + $point) % 3);
            $load = $isBodyweight
                ? 0
                : $startLoad + $liftingProgressions[$profileIndex][$point];
            $insertLiftingLog->execute([
                $memberId,
                $userId,
                $exerciseName,
                $loggedDate,
                $sets,
                $reps,
                number_format((float) $load, 2, '.', ''),
                $isBodyweight
                    ? 'Controlled reps with a full range of motion.'
                    : $weightedWorkoutNotes[($profileIndex + $point) % count($weightedWorkoutNotes)],
            ]);
            $liftingLogCount++;
        }
    }

    $pdo->commit();
    echo sprintf(
        "Created %d dummy member accounts, %d payment records, %d fitness checks, and %d lifting logs.\n",
        $count,
        $count,
        $progressCount,
        $liftingLogCount
    );
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Seed failed: ' . $error->getMessage() . "\n");
    exit(1);
}