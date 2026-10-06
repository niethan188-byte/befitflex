<?php
declare(strict_types=1);

function encryption_key(): string
{
    $encoded = getenv('BEFITFLEX_ENCRYPTION_KEY')
        ?: ($_ENV['BEFITFLEX_ENCRYPTION_KEY'] ?? '')
        ?: ($_SERVER['BEFITFLEX_ENCRYPTION_KEY'] ?? '');
    if (!$encoded) throw new RuntimeException('BEFITFLEX_ENCRYPTION_KEY is not configured.');
    $key = base64_decode($encoded, true);
    if ($key === false || strlen($key) !== 32) {
        throw new RuntimeException('BEFITFLEX_ENCRYPTION_KEY must be base64-encoded 32-byte key.');
    }
    return $key;
}

function encrypt_pii(string $value): string
{
    $iv = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt($value, 'aes-256-gcm', encryption_key(), OPENSSL_RAW_DATA, $iv, $tag);
    if ($ciphertext === false) throw new RuntimeException('Unable to encrypt PII.');
    return base64_encode($iv . $tag . $ciphertext);
}

function decrypt_pii(?string $encoded): ?string
{
    if (!$encoded) return null;
    $payload = base64_decode($encoded, true);
    if ($payload === false || strlen($payload) < 28) return null;
    $value = openssl_decrypt(substr($payload, 28), 'aes-256-gcm', encryption_key(), OPENSSL_RAW_DATA,
        substr($payload, 0, 12), substr($payload, 12, 16));
    return $value === false ? null : $value;
}

function encryption_available(): bool
{
    try {
        encryption_key();
        return true;
    } catch (Throwable) {
        return false;
    }
}