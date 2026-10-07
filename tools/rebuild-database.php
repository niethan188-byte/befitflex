<?php

declare(strict_types=1);

require __DIR__ . '/../config/db.php';

function usage(): void
{
    echo "Usage: php tools/rebuild-database.php [--host=127.0.0.1] [--port=3306] [--user=root] [--pass=] [--database=befitflex_gym] [--sql=database/befitflex_gym.sql]" . PHP_EOL;
}

$options = getopt('', ['host:', 'port:', 'user:', 'pass:', 'database:', 'sql:', 'help']);

if (isset($options['help'])) {
    usage();
    exit(0);
}

$host = (string) ($options['host'] ?? DB['host']);
$port = (int) ($options['port'] ?? DB['port']);
$user = (string) ($options['user'] ?? DB['user']);
$pass = array_key_exists('pass', $options) ? (string) $options['pass'] : (string) DB['pass'];
$database = (string) ($options['database'] ?? DB['name']);
$sqlPath = (string) ($options['sql'] ?? __DIR__ . '/../database/befitflex_gym.sql');
$sqlPath = is_file($sqlPath) ? realpath($sqlPath) : $sqlPath;

if (!is_file($sqlPath)) {
    fwrite(STDERR, "SQL dump not found: {$sqlPath}" . PHP_EOL);
    exit(1);
}

$sql = file_get_contents($sqlPath);
if ($sql === false) {
    fwrite(STDERR, "Unable to read SQL dump: {$sqlPath}" . PHP_EOL);
    exit(1);
}

$connection = @new mysqli($host, $user, $pass, '', $port);
if ($connection->connect_errno) {
    fwrite(STDERR, "Database connection failed: {$connection->connect_error}" . PHP_EOL);
    exit(1);
}

$connection->set_charset('utf8mb4');

if (!$connection->multi_query($sql)) {
    fwrite(STDERR, "Failed to execute SQL dump: {$connection->error}" . PHP_EOL);
    $connection->close();
    exit(1);
}

while ($connection->more_results()) {
    $result = $connection->store_result();
    if ($result instanceof mysqli_result) {
        $result->free();
    }

    if (!$connection->next_result()) {
        break;
    }
}

if ($connection->errno) {
    fwrite(STDERR, "SQL error while rebuilding database: {$connection->error}" . PHP_EOL);
    $connection->close();
    exit(1);
}

$connection->close();

echo "Database '{$database}' rebuilt successfully from {$sqlPath}." . PHP_EOL;
