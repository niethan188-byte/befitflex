<?php
declare(strict_types=1);

require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../vendor/autoload.php';

$checks = [];
$checks['database connection'] = db() instanceof PDO;
$columns = db()->query('SHOW COLUMNS FROM users')->fetchAll();
$columnNames = array_column($columns, 'Field');
$checks['email verification schema'] = count(array_intersect([
    'email_verified_at', 'email_verification_token', 'email_verification_expires_at',
], $columnNames)) === 3;
$checks['admin/member roles only'] = (int) scalar(
    "SELECT COUNT(*) FROM users WHERE user_type NOT IN ('admin','member')"
) === 0 && (int) scalar("SELECT COUNT(*) FROM users WHERE user_type IN ('admin','member')") > 0;
$checks['prepared statements'] = !(bool) db()->getAttribute(PDO::ATTR_EMULATE_PREPARES);
$checks['maya webhook rejects missing secret'] = maya_webhook_valid('{}', '') === false;
$checks['pdf dependency'] = class_exists('Dompdf\\Dompdf');
$checks['AI insights'] = class_exists('AIInsights') && AIInsights::generate([], [], []);
$memberId = scalar('SELECT member_id FROM members ORDER BY member_id LIMIT 1');
$progress = $memberId ? Analytics::memberProgress((string) $memberId) : [];
$checks['member progress metrics'] = count($progress['monthly'] ?? []) === 12
    && array_key_exists('visits', $progress)
    && array_key_exists('consistency', $progress);
$checks['pii encryption round trip'] = getenv('BEFITFLEX_ENCRYPTION_KEY')
    ? decrypt_pii(encrypt_pii('09171234567')) === '09171234567'
    : false;

$failed = array_keys(array_filter($checks, static fn(bool $passed): bool => !$passed));
foreach ($checks as $name => $passed) {
    echo ($passed ? 'PASS' : 'FAIL') . "  " . $name . PHP_EOL;
}
if ($failed) {
    fwrite(STDERR, count($failed) . " smoke check(s) failed." . PHP_EOL);
    exit(1);
}
echo 'All smoke checks passed.' . PHP_EOL;