<?php
declare(strict_types=1);

function maya_configured(): bool
{
    return (bool) (getenv('MAYA_PUBLIC_KEY') && getenv('MAYA_SECRET_KEY') && getenv('MAYA_WEBHOOK_SECRET'));
}

function maya_create_checkout(array $payment, string $successUrl, string $failureUrl): array
{
    $secret = getenv('MAYA_SECRET_KEY');
    $endpoint = getenv('MAYA_CHECKOUT_URL') ?: MAYA_CHECKOUT_URL;
    if (!maya_configured() || !$secret || !function_exists('curl_init')) {
        return ['ok' => false, 'error' => 'GCash checkout is not configured.'];
    }

    $payload = json_encode([
        'totalAmount' => ['value' => (float) $payment['amount'], 'currency' => 'PHP'],
        'buyer' => ['firstName' => (string) ($payment['member_name'] ?? 'Member'), 'contact' => ['email' => (string) ($payment['email'] ?? '')]],
        'items' => [['name' => (string) ($payment['notes'] ?: 'Gym membership payment'), 'amount' => ['value' => (float) $payment['amount'], 'currency' => 'PHP'], 'totalAmount' => ['value' => (float) $payment['amount'], 'currency' => 'PHP']]],
        'redirectUrl' => ['success' => $successUrl, 'failure' => $failureUrl, 'cancel' => $failureUrl],
        'requestReferenceNumber' => (string) $payment['payment_id'],
    ], JSON_THROW_ON_ERROR);

    $curl = curl_init($endpoint);
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD => $secret . ':',
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_TIMEOUT => 20,
    ]);
    $body = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $error = curl_error($curl);
    curl_close($curl);
    $data = json_decode((string) $body, true);

    if ($body === false || $status < 200 || $status >= 300 || !is_array($data)) {
        error_log('Maya checkout failed: HTTP ' . $status . ' ' . $error);
        return ['ok' => false, 'error' => 'Payment service unavailable.'];
    }
    return ['ok' => true, 'data' => $data];
}

function maya_webhook_valid(string $body, string $signature): bool
{
    $secret = getenv('MAYA_WEBHOOK_SECRET');
    return is_string($secret) && $secret !== '' && $signature !== ''
        && hash_equals(hash_hmac('sha256', $body, $secret), $signature);
}