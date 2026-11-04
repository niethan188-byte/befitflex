<?php
declare(strict_types=1);

require __DIR__ . '/../includes/sms.php';

function check_sms_test(string $name, bool $passed): void
{
    echo ($passed ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    if (!$passed) {
        exit(1);
    }
}

$previous = getenv('PHILSMS_API_KEY');
try {
    putenv('PHILSMS_API_KEY');
    check_sms_test('PhilSMS is not configured without an API key', !philsms_configured());

    putenv('PHILSMS_API_KEY=test-key');
    check_sms_test(
        'PhilSMS configuration requires cURL and recognizes an API key',
        philsms_configured() === function_exists('curl_init')
    );

    check_sms_test(
        'local Philippine mobile numbers are converted to international format',
        normalize_philippine_mobile('09171234567') === '639171234567'
    );
    check_sms_test(
        'international Philippine mobile numbers are normalized',
        normalize_philippine_mobile('+63 917-123-4567') === '639171234567'
    );
    check_sms_test(
        'non-Philippine numbers are rejected',
        normalize_philippine_mobile('+1 202 555 0100') === null
    );
    check_sms_test(
        'invalid recipients are rejected before making a provider request',
        !send_sms('invalid', 'Test message')
    );
} finally {
    if ($previous === false) {
        putenv('PHILSMS_API_KEY');
    } else {
        putenv('PHILSMS_API_KEY=' . $previous);
    }
}
