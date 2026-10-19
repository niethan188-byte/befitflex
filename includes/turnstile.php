<?php
declare(strict_types=1);

function turnstile_site_key(): string
{
    $siteKey = getenv('TURNSTILE_SITE_KEY');
    return is_string($siteKey) ? trim($siteKey) : '';
}

function turnstile_is_configured(): bool
{
    $secretKey = getenv('TURNSTILE_SECRET_KEY');
    return turnstile_site_key() !== ''
        && is_string($secretKey)
        && trim($secretKey) !== ''
        && function_exists('curl_init')
        && turnstile_expected_hostname() !== null;
}

function turnstile_response_is_valid(array $response, string $expectedAction, string $expectedHostname): bool
{
    $hostname = $response['hostname'] ?? null;
    $action = $response['action'] ?? null;
    return ($response['success'] ?? false) === true
        && is_string($hostname)
        && strcasecmp(rtrim($hostname, '.'), rtrim($expectedHostname, '.')) === 0
        && is_string($action)
        && hash_equals($expectedAction, $action);
}

function turnstile_expected_hostname(): ?string
{
    $appUrl = getenv('BEFITFLEX_APP_URL');
    if (is_string($appUrl) && trim($appUrl) !== '') {
        $url = trim($appUrl);
        $host = filter_var($url, FILTER_VALIDATE_URL) !== false
            && strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https'
            ? parse_url($url, PHP_URL_HOST)
            : false;
    } else {
        $requestHost = $_SERVER['HTTP_HOST'] ?? '';
        $host = is_string($requestHost) && $requestHost !== ''
            ? parse_url('https://' . $requestHost, PHP_URL_HOST)
            : false;
    }

    return is_string($host) && $host !== '' ? strtolower(rtrim($host, '.')) : null;
}

function turnstile_verify_token(string $token, string $expectedAction): bool
{
    if (!turnstile_is_configured()) {
        error_log('Turnstile verification is not configured: set both Turnstile keys, enable PHP cURL, and provide a valid HTTPS BEFITFLEX_APP_URL or request host.');
        return false;
    }
    if ($token === '' || strlen($token) > 2048) {
        return false;
    }

    $hostname = (string) turnstile_expected_hostname();

    $secretKey = trim((string) getenv('TURNSTILE_SECRET_KEY'));
    $curl = curl_init('https://challenges.cloudflare.com/turnstile/v0/siteverify');
    if ($curl === false || !curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'secret' => $secretKey,
            'response' => $token,
        ]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
    ])) {
        if ($curl !== false) {
            curl_close($curl);
        }
        error_log('Turnstile verification request could not be initialized.');
        return false;
    }

    $body = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $curlError = curl_errno($curl);
    curl_close($curl);

    if ($body === false || $status < 200 || $status >= 300) {
        error_log('Turnstile verification request failed: cURL ' . $curlError . ', HTTP ' . $status);
        return false;
    }

    $response = json_decode($body, true);
    return is_array($response)
        && turnstile_response_is_valid($response, $expectedAction, $hostname);
}
