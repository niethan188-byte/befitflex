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
$rememberTokensTable = db()->query("SHOW TABLES LIKE 'remember_tokens'")->fetchColumn();
$rememberTokenColumns = $rememberTokensTable
    ? db()->query('SHOW COLUMNS FROM remember_tokens')->fetchAll()
    : [];
$checks['remember-me token schema'] = count(array_intersect(
    ['selector', 'token_hash', 'user_id', 'expires_at'],
    array_column($rememberTokenColumns, 'Field')
)) === 4;
$memberColumns = db()->query('SHOW COLUMNS FROM members')->fetchAll();
$contactColumn = array_values(array_filter(
    $memberColumns,
    static fn(array $column): bool => $column['Field'] === 'contact_number'
))[0] ?? null;
$checks['member contact column supports encrypted values'] = $contactColumn !== null
    && strtolower((string) $contactColumn['Type']) === 'text';
$checks['admin/member roles only'] = (int) scalar(
    "SELECT COUNT(*) FROM users WHERE user_type NOT IN ('admin','member')"
) === 0 && (int) scalar("SELECT COUNT(*) FROM users WHERE user_type IN ('admin','member')") > 0;
$checks['prepared statements'] = !(bool) db()->getAttribute(PDO::ATTR_EMULATE_PREPARES);
$checks['maya webhook rejects missing secret'] = maya_webhook_valid('{}', '') === false;
$checks['maya status requires matching successful checkout'] = maya_checkout_confirms_payment([
    'checkoutId' => 'checkout-123',
    'paymentStatus' => 'PAYMENT_SUCCESS',
    'requestReferenceNumber' => 'PAY00001',
    'totalAmount' => ['value' => 995, 'currency' => 'PHP'],
], ['payment_id' => 'PAY00001', 'amount' => '995.00', 'maya_checkout_id' => 'checkout-123'], 'checkout-123')
    && !maya_checkout_confirms_payment([
        'checkoutId' => 'checkout-123',
        'paymentStatus' => 'PAYMENT_SUCCESS',
        'requestReferenceNumber' => 'PAY00001',
        'totalAmount' => ['value' => 1000, 'currency' => 'PHP'],
    ], ['payment_id' => 'PAY00001', 'amount' => '995.00', 'maya_checkout_id' => 'checkout-123'], 'checkout-123')
    && !maya_checkout_confirms_payment([
        'checkoutId' => 'checkout-123',
        'paymentStatus' => 'PENDING_TOKEN',
        'requestReferenceNumber' => 'PAY00001',
        'totalAmount' => ['value' => 995, 'currency' => 'PHP'],
    ], ['payment_id' => 'PAY00001', 'amount' => '995.00', 'maya_checkout_id' => 'checkout-123'], 'checkout-123')
    && !maya_checkout_confirms_payment([
        'checkoutId' => 'checkout-123',
        'paymentStatus' => 'PAYMENT_SUCCESS',
        'requestReferenceNumber' => 'PAY00001',
        'totalAmount' => ['value' => 995, 'currency' => 'USD'],
    ], ['payment_id' => 'PAY00001', 'amount' => '995.00', 'maya_checkout_id' => 'checkout-123'], 'checkout-123')
    && !maya_checkout_confirms_payment([
        'checkoutId' => 'checkout-123',
        'paymentStatus' => 'PAYMENT_SUCCESS',
        'requestReferenceNumber' => 'PAY00001',
        'totalAmount' => ['value' => 995, 'currency' => 'PHP'],
    ], ['payment_id' => 'PAY00001', 'amount' => '995.00'], 'checkout-123');
$checks['pdf dependency'] = class_exists('Dompdf\\Dompdf');
$checks['AI insights'] = class_exists('AIInsights') && AIInsights::generate([], [], []);
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