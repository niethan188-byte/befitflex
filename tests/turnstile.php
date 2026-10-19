<?php
declare(strict_types=1);

require __DIR__ . '/../includes/turnstile.php';

function check_turnstile_test(string $name, bool $passed): void
{
    if (!$passed) {
        throw new RuntimeException('Failed: ' . $name);
    }
    echo 'PASS  ' . $name . PHP_EOL;
}

$validResponse = [
    'success' => true,
    'hostname' => 'gym.example.com',
    'action' => 'register',
];

check_turnstile_test(
    'accepts successful verification for the expected action and hostname',
    turnstile_response_is_valid($validResponse, 'register', 'gym.example.com')
);
check_turnstile_test(
    'hostname comparison is case-insensitive and ignores a trailing dot',
    turnstile_response_is_valid(array_replace($validResponse, ['hostname' => 'GYM.EXAMPLE.COM.']), 'register', 'gym.example.com')
);
check_turnstile_test(
    'rejects an unsuccessful verification',
    !turnstile_response_is_valid(array_replace($validResponse, ['success' => false]), 'register', 'gym.example.com')
);
check_turnstile_test(
    'rejects a token issued for another form action',
    !turnstile_response_is_valid($validResponse, 'login', 'gym.example.com')
);
check_turnstile_test(
    'rejects a token issued for another hostname',
    !turnstile_response_is_valid(array_replace($validResponse, ['hostname' => 'other.example.com']), 'register', 'gym.example.com')
);
check_turnstile_test(
    'rejects missing action or hostname fields',
    !turnstile_response_is_valid(['success' => true], 'register', 'gym.example.com')
);

putenv('BEFITFLEX_APP_URL=https://gym.example.com/');
check_turnstile_test(
    'uses the configured HTTPS application hostname',
    turnstile_expected_hostname() === 'gym.example.com'
);
putenv('BEFITFLEX_APP_URL=http://gym.example.com');
check_turnstile_test(
    'rejects a non-HTTPS configured application URL',
    turnstile_expected_hostname() === null
);
putenv('BEFITFLEX_APP_URL');
$_SERVER['HTTP_HOST'] = 'localhost:8080';
check_turnstile_test(
    'supports a local development hostname when no app URL is configured',
    turnstile_expected_hostname() === 'localhost'
);
