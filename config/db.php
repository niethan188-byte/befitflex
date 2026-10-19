<?php
declare(strict_types=1);

define('DB', [
    'host' => getenv('MYSQLHOST') ?: getenv('DB_HOST') ?: '127.0.0.1',
    'port' => getenv('MYSQLPORT') ?: getenv('DB_PORT') ?: '3306',
    'name' => getenv('MYSQLDATABASE') ?: getenv('DB_NAME') ?: getenv('DB_DATABASE') ?: 'befitflex_gym',
    'user' => getenv('MYSQLUSER') ?: getenv('DB_USER') ?: getenv('DB_USERNAME') ?: 'root',
    'pass' => getenv('MYSQLPASSWORD') ?: getenv('DB_PASSWORD') ?: '',
]);

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', DB['host'], DB['port'], DB['name']);

    try {
        $pdo = new PDO($dsn, DB['user'], DB['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        // Keep SQL NOW()/CURDATE() aligned with the app's Asia/Manila timezone.
        $pdo->exec("SET time_zone = '+08:00'");
    } catch (PDOException $e) {
        http_response_code(500);
        error_log('Database connection failed: ' . $e->getMessage());
        echo '<!doctype html><meta charset="utf-8">'
           . '<div style="font:16px system-ui;background:#0a0a0a;color:#eee;padding:40px;height:100vh">'
           . '<h1 style="color:#E10600">Database connection failed</h1>'
           . '<p>MySQL is not reachable, the database has not been imported, or its environment variables are incorrect.</p>'
           . '<p>Check the database service and application logs, then verify the MYSQLHOST, MYSQLPORT, MYSQLDATABASE, MYSQLUSER, and MYSQLPASSWORD settings.</p></div>';
        exit;
    }

    return $pdo;
}
