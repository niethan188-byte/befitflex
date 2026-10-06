<?php
/**
 * Database connection. XAMPP defaults are already filled in.
 * Change only if your MySQL root account has a password.
 */
declare(strict_types=1);

const DB = [
    'host' => '127.0.0.1',
    'port' => '3306',
    'name' => 'befitflex_gym',
    'user' => 'root',
    'pass' => '',
];

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
    } catch (PDOException $e) {
        http_response_code(500);
        echo '<!doctype html><meta charset="utf-8">'
           . '<div style="font:16px system-ui;background:#0a0a0a;color:#eee;padding:40px;height:100vh">'
           . '<h1 style="color:#E10600">Database connection failed</h1>'
           . '<p>MySQL is not reachable, or the database has not been imported yet.</p>'
           . '<ol><li>Start <b>MySQL</b> in the XAMPP Control Panel</li>'
           . '<li>Import <code>database/befitflex_gym.sql</code> through phpMyAdmin</li>'
           . '<li>Check the credentials in <code>config/db.php</code></li></ol>'
           . '<p style="color:#888">' . htmlspecialchars($e->getMessage()) . '</p></div>';
        exit;
    }

    return $pdo;
}
