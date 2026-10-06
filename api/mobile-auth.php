<?php
declare(strict_types=1);

function mobile_b64(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function mobile_unb64(string $value): string|false
{
    return base64_decode(strtr($value, '-_', '+/') . str_repeat('=', (4 - strlen($value) % 4) % 4), true);
}

function mobile_token(array $user): string
{
    $secret = getenv('BEFITFLEX_API_SECRET') ?: MOBILE_API_SECRET;
    if ($secret === '') throw new RuntimeException('BEFITFLEX_API_SECRET is not configured.');
    $payload = mobile_b64(json_encode([
        'uid' => (int) $user['id'],
        'type' => $user['type'],
        'exp' => time() + 60 * 60 * 24 * 30,
    ], JSON_THROW_ON_ERROR));
    return $payload . '.' . mobile_b64(hash_hmac('sha256', $payload, $secret, true));
}

function mobile_bearer(): ?string
{
    $mobileToken = $_SERVER['HTTP_X_MOBILE_TOKEN'] ?? '';
    if ($mobileToken !== '') {
        return trim($mobileToken);
    }

    $header = $_SERVER['HTTP_AUTHORIZATION']
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        ?? '';

    if ($header === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $name => $value) {
            if (strcasecmp($name, 'Authorization') === 0 || strcasecmp($name, 'X-Mobile-Token') === 0) {
                $header = (string) $value;
                break;
            }
        }
    }

    return preg_match('/^Bearer\s+(.+)$/i', $header, $m) ? $m[1] : null;
}

function mobile_user(): ?array
{
    $secret = getenv('BEFITFLEX_API_SECRET') ?: MOBILE_API_SECRET;
    if ($secret === '') return null;
    $token = mobile_bearer();
    if (!$token || substr_count($token, '.') !== 1) return null;
    [$payload, $signature] = explode('.', $token, 2);
    $expected = mobile_b64(hash_hmac('sha256', $payload, $secret, true));
    if (!hash_equals($expected, $signature)) return null;
    $data = json_decode((string) mobile_unb64($payload), true);
    if (!is_array($data) || (int) ($data['exp'] ?? 0) < time()) return null;
    return row('SELECT user_id, email, user_type FROM users WHERE user_id = ?', [(int) $data['uid']]);
}