<?php
declare(strict_types=1);

require __DIR__ . '/../includes/email.php';

function check_email_test(string $name, bool $passed): void
{
    echo ($passed ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    if (!$passed) {
        exit(1);
    }
}

$variables = [
    'MAIL_MAILER',
    'MAIL_HOST',
    'MAIL_PORT',
    'MAIL_USERNAME',
    'MAIL_PASSWORD',
    'MAIL_ENCRYPTION',
];
$previous = [];
foreach ($variables as $variable) {
    $value = getenv($variable);
    $previous[$variable] = is_string($value) ? $value : false;
}

try {
    foreach ($variables as $variable) {
        putenv($variable);
    }
    check_email_test('SMTP is not ready when credentials are missing', !mailtrap_smtp_configured());

    putenv('MAIL_MAILER=smtp');
    putenv('MAIL_HOST=sandbox.smtp.mailtrap.io');
    putenv('MAIL_PORT=2525');
    putenv('MAIL_USERNAME=test-user');
    putenv('MAIL_PASSWORD=test-password');
    putenv('MAIL_ENCRYPTION=tls');
    check_email_test('Mailtrap Sandbox SMTP settings are recognized', mailtrap_smtp_configured());

    putenv('MAIL_ENCRYPTION=plain');
    check_email_test('unsupported SMTP encryption is rejected', !mailtrap_smtp_configured());

    putenv('MAIL_ENCRYPTION=tls');
    putenv('MAIL_PORT=70000');
    check_email_test('invalid SMTP port is rejected', !mailtrap_smtp_configured());

    check_email_test('invalid recipient is rejected without connecting to SMTP', !send_email('invalid', 'Test', '<p>Test</p>'));
} finally {
    foreach ($previous as $variable => $value) {
        if ($value === false) {
            putenv($variable);
        } else {
            putenv($variable . '=' . $value);
        }
    }
}
