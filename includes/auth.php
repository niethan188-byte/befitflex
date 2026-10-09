<?php
/**
 * Session bootstrap + role guard. Every page starts by requiring this file.
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/helpers.php';
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
