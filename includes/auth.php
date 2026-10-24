<?php
/**
 * Session bootstrap + role guard. Every page starts by requiring this file.
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/turnstile.php';
require_once __DIR__ . '/email.php';
require_once __DIR__ . '/maya.php';
require_once __DIR__ . '/crypto.php';
require_once __DIR__ . '/Analytics.php';
require_once __DIR__ . '/AIInsights.php';
require_once __DIR__ . '/Chart.php';
require_once __DIR__ . '/ui.php';

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('BEFITSESS');
    session_start();
}

/** Path back to the application root from wherever the current page lives. */
function base_url(): string
{
    return defined('IN_SUBDIR') && IN_SUBDIR ? '../' : '';
}

const REMEMBER_COOKIE = 'BEFITREMEMBER';
const REMEMBER_DAYS = 30;

function remember_cookie_options(int $expires): array
{
    return [
        'expires'  => $expires,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ];
}

function clear_remember_cookie(): void
{
    $cookie = (string) ($_COOKIE[REMEMBER_COOKIE] ?? '');
    if (preg_match('/^([a-f0-9]{32}):[a-f0-9]{64}$/', $cookie, $matches)) {
        db()->prepare('DELETE FROM remember_tokens WHERE selector = ?')->execute([$matches[1]]);
    }

    if (!setcookie(REMEMBER_COOKIE, '', remember_cookie_options(time() - 3600))) {
        throw new RuntimeException('Unable to clear the remember-me cookie.');
    }
    unset($_COOKIE[REMEMBER_COOKIE]);
}

function issue_remember_token(int $userId): void
{
    $selector = bin2hex(random_bytes(16));
    $validator = bin2hex(random_bytes(32));
    $expiresAt = date('Y-m-d H:i:s', time() + REMEMBER_DAYS * 86400);

    db()->prepare('DELETE FROM remember_tokens WHERE expires_at <= NOW()')->execute();
    db()->prepare(
        'INSERT INTO remember_tokens (selector, token_hash, user_id, expires_at)
         VALUES (?, ?, ?, ?)'
    )->execute([$selector, hash('sha256', $validator), $userId, $expiresAt]);

    if (!setcookie(
        REMEMBER_COOKIE,
        $selector . ':' . $validator,
        remember_cookie_options(strtotime($expiresAt))
    )) {
        db()->prepare('DELETE FROM remember_tokens WHERE selector = ?')->execute([$selector]);
        throw new RuntimeException('Unable to set the remember-me cookie.');
    }
    $_COOKIE[REMEMBER_COOKIE] = $selector . ':' . $validator;
}

function restore_remembered_session(): void
{
    if (current_user()) {
        return;
    }

    $cookie = (string) ($_COOKIE[REMEMBER_COOKIE] ?? '');
    if (!preg_match('/^([a-f0-9]{32}):([a-f0-9]{64})$/', $cookie, $matches)) {
        if ($cookie !== '') {
            clear_remember_cookie();
        }
        return;
    }

    $statement = db()->prepare(
        'SELECT rt.token_hash, u.*
           FROM remember_tokens rt
           JOIN users u ON u.user_id = rt.user_id
          WHERE rt.selector = ? AND rt.expires_at > NOW()
          LIMIT 1'
    );
    $statement->execute([$matches[1]]);
    $record = $statement->fetch();
    if (!$record || !hash_equals((string) $record['token_hash'], hash('sha256', $matches[2]))
        || !in_array($record['user_type'], ['admin', 'member'], true)
        || $record['email_verified_at'] === null) {
        clear_remember_cookie();
        return;
    }

    db()->prepare('DELETE FROM remember_tokens WHERE selector = ?')->execute([$matches[1]]);
    db()->prepare('UPDATE users SET last_login = NOW() WHERE user_id = ?')->execute([$record['user_id']]);
    start_session_for($record);
    issue_remember_token((int) $record['user_id']);
}

/**
 * Demo rows store plain-text passwords so you can sign in right after import.
 * On the first successful login the password is silently re-saved as a bcrypt
 * hash, so the database hardens itself as people use it.
 */
function attempt_login(string $email, string $password): ?array
{
    $user = row('SELECT * FROM users WHERE email = ?', [$email]);
    if (!$user) {
        return null;
    }

    if (!in_array($user['user_type'], ['admin', 'member'], true)) {
        return null;
    }

    if ($user['email_verified_at'] === null) {
        return null;
    }

    $stored = (string) $user['password'];
    $isHash = str_starts_with($stored, '$2y$') || str_starts_with($stored, '$argon2');

    if ($isHash) {
        if (!password_verify($password, $stored)) {
            return null;
        }
    } else {
        if (!hash_equals($stored, $password)) {
            return null;
        }
        // Upgrade the plain-text demo password in place.
        db()->prepare('UPDATE users SET password = ? WHERE user_id = ?')
            ->execute([password_hash($password, PASSWORD_DEFAULT), $user['user_id']]);
    }

    db()->prepare('UPDATE users SET last_login = NOW() WHERE user_id = ?')->execute([$user['user_id']]);

    return $user;
}

function start_session_for(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['user_id']   = (int) $user['user_id'];
    $_SESSION['email']     = $user['email'];
    $_SESSION['user_type'] = $user['user_type'];

    if ($user['user_type'] === 'member') {
        $m = row('SELECT member_id, member_name FROM members WHERE user_id = ?', [$user['user_id']]);
        $_SESSION['member_id'] = $m['member_id'] ?? null;
        $_SESSION['name']      = $m['member_name'] ?? $user['email'];
    } else {
        $_SESSION['name'] = 'Administrator';
    }
}

function home_for(string $type): string
{
    return match ($type) {
        'admin' => 'admin/dashboard.php',
        default => 'member/dashboard.php',
    };
}

function current_user(): ?array
{
    $type = $_SESSION['user_type'] ?? '';
    if (empty($_SESSION['user_id']) || !in_array($type, ['admin', 'member'], true)) {
        return null;
    }
    return [
        'id'    => (int) $_SESSION['user_id'],
        'name'  => $_SESSION['name'] ?? '',
        'email' => $_SESSION['email'] ?? '',
        'type'  => $type,
    ];
}

function require_role(string ...$allowed): array
{
    $u = current_user();
    if (!$u) {
        redirect(base_url() . 'index.php');
    }
    if ($allowed && !in_array($u['type'], $allowed, true)) {
        redirect(base_url() . home_for($u['type']));
    }
    return $u;
}

restore_remembered_session();
