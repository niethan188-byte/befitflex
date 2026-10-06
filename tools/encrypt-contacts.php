<?php
declare(strict_types=1);

require __DIR__ . '/../includes/auth.php';

$pdo = db();
$pdo->exec('ALTER TABLE members MODIFY contact_number TEXT NOT NULL');
$rows = $pdo->query('SELECT member_id, contact_number, contact_number_encrypted FROM members')->fetchAll();
$update = $pdo->prepare('UPDATE members SET contact_number = ?, contact_number_encrypted = ? WHERE member_id = ?');
$count = 0;

foreach ($rows as $member) {
    $plain = decrypt_pii($member['contact_number_encrypted'] ?? null);
    if ($plain === null) {
        $plain = (string) $member['contact_number'];
    }
    $ciphertext = encrypt_pii($plain);
    $update->execute([$ciphertext, $ciphertext, $member['member_id']]);
    $count++;
}

echo $count . ' member contact records encrypted.' . PHP_EOL;