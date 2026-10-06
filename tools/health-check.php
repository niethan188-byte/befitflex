<?php
declare(strict_types=1);

require __DIR__ . '/../includes/auth.php';

$checks = [
    'PHP version' => PHP_VERSION,
    'Database' => (string) db()->query('SELECT VERSION()')->fetchColumn(),
    'cURL' => function_exists('curl_init') ? 'enabled' : 'missing',
    'OpenSSL' => extension_loaded('openssl') ? 'enabled' : 'missing',
    'Mailtrap' => getenv('MAILTRAP_API_TOKEN') ? 'configured' : 'not configured',
    'Maya' => maya_configured() ? 'configured' : 'not configured',
    'Mobile secret' => getenv('BEFITFLEX_API_SECRET') ? 'configured' : 'not configured',
    'PII encryption' => encryption_available() ? 'configured' : 'not configured for this process',
];
foreach ($checks as $name => $value) {
    echo str_pad($name, 18) . ': ' . $value . PHP_EOL;
}