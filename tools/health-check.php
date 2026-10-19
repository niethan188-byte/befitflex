<?php
declare(strict_types=1);

require __DIR__ . '/../includes/auth.php';

$checks = [
    'PHP version' => PHP_VERSION,
    'Database' => (string) db()->query('SELECT VERSION()')->fetchColumn(),
    'cURL' => function_exists('curl_init') ? 'enabled' : 'missing',
    'Turnstile' => turnstile_is_configured() ? 'configured' : 'missing keys or cURL',
    'OpenSSL' => extension_loaded('openssl') ? 'enabled' : 'missing',
    'Mailtrap SMTP' => mailtrap_smtp_configured() ? 'configured' : 'not configured',
    'Maya checkout' => maya_payment_flow_configured() ? 'ready' : 'missing keys or cURL',
    'Maya status lookup' => getenv('MAYA_SECRET_KEY') ? 'configured' : 'not configured',
    'Maya webhook' => getenv('MAYA_WEBHOOK_SECRET') ? 'configured' : 'not configured',
    'Mobile secret' => getenv('BEFITFLEX_API_SECRET') ? 'configured' : 'not configured',
    'PII encryption' => encryption_available() ? 'configured' : 'not configured for this process',
];
foreach ($checks as $name => $value) {
    echo str_pad($name, 18) . ': ' . $value . PHP_EOL;
}