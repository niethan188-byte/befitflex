<?php
declare(strict_types=1);

function maya_configured(): bool
{
    $publicKey = getenv('MAYA_PUBLIC_KEY');
    return is_string($publicKey) && trim($publicKey) !== '';
}

function maya_create_checkout(array $payment, string $successUrl, string $failureUrl): array
{
    $publicKey = getenv('MAYA_PUBLIC_KEY');
    $endpoint = getenv('MAYA_CHECKOUT_URL') ?: MAYA_CHECKOUT_URL;
    if (!maya_configured() || !is_string($publicKey) || !function_exists('curl_init')) {
        return ['ok' => false, 'error' => 'Maya checkout is not configured.'];
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
        CURLOPT_USERPWD => $publicKey . ':',
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_TIMEOUT => 20,
    ]);
    $body = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    $data = json_decode((string) $body, true);

    if ($body === false || $status < 200 || $status >= 300 || !is_array($data)) {
        error_log('Maya checkout failed: HTTP ' . $status);
        return ['ok' => false, 'error' => 'Payment service unavailable.'];
    }
    return ['ok' => true, 'data' => $data];
}

function maya_get_checkout(string $checkoutId): array
{
    $secret = getenv('MAYA_SECRET_KEY');
    $endpoint = getenv('MAYA_CHECKOUT_URL') ?: MAYA_CHECKOUT_URL;
    if (!is_string($secret) || trim($secret) === '' || !function_exists('curl_init')) {
        return ['ok' => false, 'error' => 'Maya status verification is not configured.'];
    }

    $curl = curl_init(rtrim($endpoint, '/') . '/' . rawurlencode($checkoutId));
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD => $secret . ':',
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
        CURLOPT_TIMEOUT => 20,
    ]);
    $body = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    $data = json_decode((string) $body, true);

    if ($body === false || $status < 200 || $status >= 300 || !is_array($data)) {
        error_log('Maya status lookup failed: HTTP ' . $status);
        return ['ok' => false, 'error' => 'Maya could not verify this payment right now.'];
    }
    return ['ok' => true, 'data' => $data];
}

function maya_checkout_confirms_payment(array $checkout, array $payment, string $requestedCheckoutId): bool
{
    return maya_checkout_id_matches($checkout, $payment, $requestedCheckoutId)
        && maya_payment_details_match($checkout, $payment);
}

function maya_payment_details_match(array $checkout, array $payment): bool
{
    $amount = $checkout['totalAmount']['value'] ?? null;
    return ($checkout['paymentStatus'] ?? null) === 'PAYMENT_SUCCESS'
        && (string) ($checkout['requestReferenceNumber'] ?? '') === (string) ($payment['payment_id'] ?? '')
        && maya_amount_in_cents($amount) !== null
        && maya_amount_in_cents($amount) === maya_amount_in_cents($payment['amount'] ?? null)
        && strtoupper((string) ($checkout['totalAmount']['currency'] ?? '')) === 'PHP';
}

function maya_webhook_confirms_payment(array $event, array $payment): bool
{
    $checkoutId = $event['checkoutId'] ?? $event['id'] ?? null;
    $status = strtoupper((string) ($event['paymentStatus'] ?? $event['status'] ?? ''));
    if (!is_string($checkoutId) || $status !== 'PAYMENT_SUCCESS') {
        return false;
    }

    $savedCheckoutId = (string) ($payment['maya_checkout_id'] ?? '');
    return ($savedCheckoutId === '' || hash_equals($savedCheckoutId, $checkoutId))
        && maya_payment_details_match([
            'paymentStatus' => $status,
            'requestReferenceNumber' => $event['requestReferenceNumber'] ?? $event['referenceNumber'] ?? '',
            'totalAmount' => $event['totalAmount'] ?? [],
        ], $payment);
}

function maya_checkout_id_matches(array $checkout, array $payment, string $requestedCheckoutId): bool
{
    $savedCheckoutId = (string) ($payment['maya_checkout_id'] ?? '');
    return $savedCheckoutId !== ''
        && $requestedCheckoutId !== ''
        && hash_equals($savedCheckoutId, $requestedCheckoutId)
        && (!isset($checkout['checkoutId']) || (
            is_string($checkout['checkoutId'])
            && hash_equals($savedCheckoutId, $checkout['checkoutId'])
        ));
}

function maya_manual_mark_allowed(array $payment): bool
{
    return ($payment['payment_status'] ?? null) !== 'Paid'
        && ($payment['payment_method'] ?? null) !== 'Maya'
        && (string) ($payment['maya_checkout_id'] ?? '') === '';
}

function maya_amount_in_cents(mixed $amount): ?int
{
    if (!is_string($amount) && !is_int($amount) && !is_float($amount)) {
        return null;
    }
    $value = (string) $amount;
    if (!preg_match('/^(0|[1-9]\d*)(?:\.(\d{1,2}))?$/', $value, $matches)) {
        return null;
    }

    $whole = (int) $matches[1];
    $fraction = str_pad($matches[2] ?? '', 2, '0');
    $fractionCents = (int) $fraction;
    if ($whole > intdiv(PHP_INT_MAX - $fractionCents, 100)) {
        return null;
    }
    return $whole * 100 + $fractionCents;
}

function maya_webhook_valid(string $body, string $signature): bool
{
    $secret = getenv('MAYA_WEBHOOK_SECRET');
    return is_string($secret) && $secret !== '' && $signature !== ''
        && hash_equals(hash_hmac('sha256', $body, $secret), $signature);
}