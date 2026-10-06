<?php
declare(strict_types=1);

/** Escape for HTML output. Every echoed value goes through this. */
function e(mixed $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

function member_contact(array $member): string
{
    try {
        return decrypt_pii($member['contact_number_encrypted'] ?? null)
            ?? decrypt_pii($member['contact_number'] ?? null)
            ?? (string) ($member['contact_number'] ?? '');
    } catch (Throwable) {
        return 'Protected contact';
    }
}

function money(float|string|null $v): string
{
    return '&#8369;' . number_format((float) $v, 2);
}

function dt(?string $v, string $fmt = 'M j, Y'): string
{
    if (!$v || str_starts_with($v, '0000')) {
        return '—';
    }
    $ts = strtotime($v);
    return $ts ? date($fmt, $ts) : '—';
}

function timeh(?string $v): string
{
    return $v ? date('g:i A', strtotime($v)) : '—';
}

/** Coloured status chip. */
function badge(?string $status): string
{
    $map = [
        'Active' => 'ok', 'Paid' => 'ok', 'Completed' => 'ok', 'Present' => 'ok', 'Confirmed' => 'ok',
        'Pending' => 'warn', 'Scheduled' => 'warn', 'Late' => 'warn', 'Ongoing' => 'warn',
        'Inactive' => 'mute', 'Absent' => 'mute',
        'Overdue' => 'bad', 'Expired' => 'bad', 'Cancelled' => 'bad',
    ];
    $tone = $map[$status] ?? 'mute';
    return '<span class="chip ' . $tone . '">' . e($status ?? '—') . '</span>';
}

function redirect(string $to): never
{
    header('Location: ' . $to);
    exit;
}

/* ---------- flash messages ---------- */

function flash(string $msg, string $type = 'ok'): void
{
    $_SESSION['flash'][] = ['msg' => $msg, 'type' => $type];
}

function take_flash(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

/* ---------- CSRF ---------- */

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
}

function csrf_check(): void
{
    $sent = $_POST['csrf'] ?? '';
    if (!is_string($sent) || !hash_equals($_SESSION['csrf'] ?? '', $sent)) {
        http_response_code(419);
        exit('Session expired. Go back, reload the page, and try again.');
    }
}

/**
 * Next id in the schema's own format, e.g. MEM0005, TRN0004, PAY00006.
 * Reads the current maximum and increments, keeping the zero padding.
 */
function next_id(string $table, string $col, string $prefix, int $digits): string
{
    $sql  = "SELECT MAX(CAST(SUBSTRING($col, ?) AS UNSIGNED)) FROM $table WHERE $col LIKE ?";
    $stmt = db()->prepare($sql);
    $stmt->execute([strlen($prefix) + 1, $prefix . '%']);
    $max = (int) $stmt->fetchColumn();

    return $prefix . str_pad((string) ($max + 1), $digits, '0', STR_PAD_LEFT);
}

/* ---------- notifications + audit ---------- */

function notify(int $userId, string $type, string $title, string $message,
                string $icon = 'bell', string $color = 'primary',
                ?string $url = null, string $priority = 'normal'): void
{
    $pdo = db();
    $pdo->prepare(
        'INSERT INTO notifications
           (user_id, notification_type, notification_title, notification_message,
            notification_icon, icon_color, action_url, priority)
         VALUES (?,?,?,?,?,?,?,?)'
    )->execute([$userId, $type, $title, $message, $icon, $color, $url, $priority]);

    $notificationId = (int) $pdo->lastInsertId();
    $preference = match ($type) {
        'payment' => 'email_payments',
        'reservation' => 'email_reservations',
        'account' => 'email_account',
        default => 'email_system',
    };
    $recipient = row(
        "SELECT u.email, COALESCE(np.$preference, 1) AS email_enabled
           FROM users u
           LEFT JOIN notification_preferences np ON np.user_id = u.user_id
          WHERE u.user_id = ?",
        [$userId]
    );

    if ($recipient && (int) $recipient['email_enabled'] === 1) {
        $html = '<h2>' . e($title) . '</h2><p>' . nl2br(e($message)) . '</p>';
        if (send_email((string) $recipient['email'], $title, $html)) {
            $pdo->prepare(
                'UPDATE notifications SET email_sent = 1, email_sent_at = NOW() WHERE notification_id = ?'
            )->execute([$notificationId]);
        }
    }
}

function log_activity(string $action, string $module, string $details = ''): void
{
    /* Any audited write invalidates the cached analytics, so the numbers
       never lag behind what the operator just did. */
    if (class_exists('Analytics')) {
        Analytics::flush();
    }

    if (empty($_SESSION['user_id'])) {
        return;
    }
    db()->prepare('INSERT INTO activity_log (user_id, action, module, details) VALUES (?,?,?,?)')
        ->execute([(int) $_SESSION['user_id'], $action, $module, $details]);
}

function unread_count(int $userId): int
{
    $s = db()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
    $s->execute([$userId]);
    return (int) $s->fetchColumn();
}

/**
 * Prepared statements are reused across calls in the same request.
 * A dashboard that runs the same shaped query per row of a list stops
 * re-parsing the SQL every time.
 */
function stmt(string $sql): PDOStatement
{
    static $cache = [];
    return $cache[$sql] ??= db()->prepare($sql);
}

/** One scalar from a query — used all over the dashboards. */
function scalar(string $sql, array $args = []): mixed
{
    $s = stmt($sql);
    $s->execute($args);
    $v = $s->fetchColumn();
    $s->closeCursor();
    return $v;
}

function rows(string $sql, array $args = []): array
{
    $s = stmt($sql);
    $s->execute($args);
    $r = $s->fetchAll();
    $s->closeCursor();
    return $r;
}

function row(string $sql, array $args = []): ?array
{
    $s = stmt($sql);
    $s->execute($args);
    $r = $s->fetch();
    $s->closeCursor();
    return $r ?: null;
}
