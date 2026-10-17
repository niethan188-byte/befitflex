<?php
declare(strict_types=1);

function maya_configured(): bool
{
    $publicKey = getenv('MAYA_PUBLIC_KEY');
    return is_string($publicKey) && trim($publicKey) !== '';
}

function maya_payment_flow_configured(): bool
{
    $secretKey = getenv('MAYA_SECRET_KEY');
    return maya_configured()
        && is_string($secretKey)
        && trim($secretKey) !== ''
        && function_exists('curl_init');
}

function maya_manual_payment_creation_allowed(string $method, string $status): bool
{
    return $method !== 'Maya' || $status !== 'Paid';
}

function maya_create_checkout(array $payment, string $successUrl, string $failureUrl): array
{
    $publicKey = getenv('MAYA_PUBLIC_KEY');
    $endpoint = getenv('MAYA_CHECKOUT_URL') ?: MAYA_CHECKOUT_URL;
    if (!maya_payment_flow_configured() || !is_string($publicKey)) {
        return ['ok' => false, 'error' => 'Maya checkout requires public and secret API keys and PHP cURL so payment status can be verified.'];
    }
    $publicKey = trim($publicKey);

    $payload = json_encode([
        'totalAmount' => ['value' => (float) $payment['amount'], 'currency' => 'PHP'],
        'buyer' => ['firstName' => (string) ($payment['member_name'] ?? 'Member'), 'contact' => ['email' => (string) ($payment['email'] ?? '')]],
        'items' => [['name' => (string) ($payment['notes'] ?: 'Gym membership payment'), 'amount' => ['value' => (float) $payment['amount'], 'currency' => 'PHP'], 'totalAmount' => ['value' => (float) $payment['amount'], 'currency' => 'PHP']]],
        'redirectUrl' => ['success' => $successUrl, 'failure' => $failureUrl, 'cancel' => $failureUrl],
        'requestReferenceNumber' => (string) $payment['payment_id'],
    ], JSON_THROW_ON_ERROR);

    $curl = curl_init($endpoint);
    if ($curl === false || !curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD => $publicKey . ':',
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_TIMEOUT => 20,
    ])) {
        if ($curl !== false) {
            curl_close($curl);
        }
        error_log('Maya checkout request could not be initialized.');
        return ['ok' => false, 'error' => 'Maya checkout could not be started. Please try again later.'];
    }
    $body = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $curlError = curl_errno($curl);
    curl_close($curl);
    $data = json_decode((string) $body, true);

    if ($body === false || $status < 200 || $status >= 300 || !is_array($data)) {
        error_log('Maya checkout failed: cURL ' . $curlError . ', HTTP ' . $status);
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
    $secret = trim($secret);

    $curl = curl_init(rtrim($endpoint, '/') . '/' . rawurlencode($checkoutId));
    if ($curl === false || !curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD => $secret . ':',
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
        CURLOPT_TIMEOUT => 20,
    ])) {
        if ($curl !== false) {
            curl_close($curl);
        }
        error_log('Maya status lookup request could not be initialized.');
        return ['ok' => false, 'error' => 'Maya could not verify this payment right now.'];
    }
    $body = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $curlError = curl_errno($curl);
    curl_close($curl);
    $data = json_decode((string) $body, true);

    if ($body === false || $status < 200 || $status >= 300 || !is_array($data)) {
        error_log('Maya status lookup failed: cURL ' . $curlError . ', HTTP ' . $status);
        return ['ok' => false, 'error' => 'Maya could not verify this payment right now.'];
    }
    return ['ok' => true, 'data' => $data];
}

function maya_checkout_confirms_payment(array $checkout, array $payment, string $requestedCheckoutId): bool
{
    return maya_checkout_id_matches($checkout, $payment, $requestedCheckoutId)
        && maya_checkout_matches_payment($checkout, $payment, $requestedCheckoutId)
        && maya_checkout_status($checkout) === 'PAYMENT_SUCCESS';
}

function maya_checkout_matches_payment(array $checkout, array $payment, string $requestedCheckoutId): bool
{
    return maya_checkout_payment_mismatch($checkout, $payment, $requestedCheckoutId) === null;
}

function maya_checkout_payment_mismatch(array $checkout, array $payment, string $requestedCheckoutId): ?string
{
    if ($requestedCheckoutId === '') {
        return 'checkout reference';
    }
    if (isset($checkout['checkoutId']) && (
        !is_string($checkout['checkoutId'])
        || !hash_equals($requestedCheckoutId, $checkout['checkoutId'])
    )) {
        return 'checkout reference';
    }
    if ((string) ($checkout['requestReferenceNumber'] ?? '') !== (string) ($payment['payment_id'] ?? '')) {
        return 'payment reference';
    }

    $checkoutAmountCents = maya_checkout_amount_in_cents($checkout);
    if ($checkoutAmountCents === null
        || $checkoutAmountCents !== maya_amount_in_cents($payment['amount'] ?? null)) {
        return 'amount';
    }
    if (strtoupper((string) ($checkout['totalAmount']['currency'] ?? '')) !== 'PHP') {
        return 'currency';
    }
    return null;
}

function maya_checkout_is_retryable_failure(array $checkout, array $payment, string $requestedCheckoutId): bool
{
    if (!maya_checkout_id_matches($checkout, $payment, $requestedCheckoutId)
        || !in_array(maya_checkout_status($checkout), [
            'FAILED',
            'CANCELLED',
            'EXPIRED',
            'PAYMENT_FAILED',
            'PAYMENT_CANCELLED',
            'PAYMENT_EXPIRED',
        ], true)) {
        return false;
    }

    if (array_key_exists('requestReferenceNumber', $checkout)
        && (string) $checkout['requestReferenceNumber'] !== (string) ($payment['payment_id'] ?? '')) {
        return false;
    }

    if (array_key_exists('totalAmount', $checkout)) {
        if (!is_array($checkout['totalAmount'])) {
            return false;
        }
        $hasAmount = array_key_exists('value', $checkout['totalAmount'])
            || array_key_exists('amount', $checkout['totalAmount']);
        if ($hasAmount
            && maya_checkout_amount_in_cents($checkout) !== maya_amount_in_cents($payment['amount'] ?? null)) {
            return false;
        }
        if (array_key_exists('currency', $checkout['totalAmount'])
            && strtoupper((string) $checkout['totalAmount']['currency']) !== 'PHP') {
            return false;
        }
    }

    return true;
}

function maya_checkout_status(array $checkout): ?string
{
    $status = $checkout['paymentStatus'] ?? $checkout['status'] ?? null;
    return is_string($status) ? strtoupper($status) : null;
}

function maya_checkout_amount_in_cents(array $checkout): ?int
{
    $totalAmount = $checkout['totalAmount'] ?? null;
    if (!is_array($totalAmount)) {
        return null;
    }

    $values = [];
    foreach (['value', 'amount'] as $key) {
        if (!array_key_exists($key, $totalAmount)) {
            continue;
        }
        $cents = maya_amount_in_cents($totalAmount[$key]);
        if ($cents === null) {
            return null;
        }
        $values[] = $cents;
    }

    if ($values === []) {
        return null;
    }
    if (count(array_unique($values)) !== 1) {
        return null;
    }

    return $values[0];
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
        && maya_checkout_matches_payment([
            'paymentStatus' => $status,
            'requestReferenceNumber' => $event['requestReferenceNumber'] ?? $event['referenceNumber'] ?? '',
            'totalAmount' => $event['totalAmount'] ?? [],
        ], $payment, $checkoutId);
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
    if (!preg_match('/^(0|[1-9]\d*)(?:\.(\d+))?$/', $value, $matches)) {
        return null;
    }

    $whole = (int) $matches[1];
    $fraction = $matches[2] ?? '';
    if (strlen($fraction) > 2 && trim(substr($fraction, 2), '0') !== '') {
        return null;
    }
    $fraction = str_pad(substr($fraction, 0, 2), 2, '0');
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