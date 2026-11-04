<?php
declare(strict_types=1);

function philsms_configured(): bool
{
    $apiKey = getenv('PHILSMS_API_KEY');
    return is_string($apiKey) && trim($apiKey) !== '' && function_exists('curl_init');
}

function normalize_philippine_mobile(string $number): ?string
{
    $number = trim($number);
    if ($number === '' || preg_match('/[^0-9+\s().-]/', $number)) {
        return null;
    }

    $digits = preg_replace('/\D/', '', $number);
    if (!is_string($digits)) {
        return null;
    }
    if (preg_match('/^09\d{9}$/', $digits)) {
        return '63' . substr($digits, 1);
    }
    if (preg_match('/^639\d{9}$/', $digits)) {
        return $digits;
    }
    return null;
}

function send_sms(string $number, string $message): bool
{
    $recipient = normalize_philippine_mobile($number);
    if ($recipient === null) {
        error_log('PhilSMS message skipped: invalid Philippine mobile number.');
        return false;
    }
    if (trim($message) === '') {
        error_log('PhilSMS message skipped: message is empty.');
        return false;
    }
    if (!philsms_configured()) {
        error_log('PhilSMS message skipped: API key or PHP cURL is unavailable.');
        return false;
    }

    $payload = [
        'recipient' => $recipient,
        'sender_id' => trim((string) (getenv('PHILSMS_SENDER_ID') ?: 'PhilSMS')),
        'type' => 'plain',
        'message' => $message,
    ];
    if (strlen($payload['sender_id']) > 11) {
        error_log('PhilSMS message skipped: sender ID must not exceed 11 characters.');
        return false;
    }

    $requestBody = json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);
    if (!is_string($requestBody)) {
        error_log('PhilSMS message skipped: request could not be encoded.');
        return false;
    }

    $curl = curl_init('https://dashboard.philsms.com/api/v3/sms/send');
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $requestBody,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Content-Type: application/json',
            'Authorization: Bearer ' . trim((string) getenv('PHILSMS_API_KEY')),
        ],
    ]);
    $response = curl_exec($curl);
    $httpStatus = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $curlError = curl_error($curl);
    curl_close($curl);

    if (!is_string($response) || $curlError !== '' || $httpStatus < 200 || $httpStatus >= 300) {
        error_log('PhilSMS request failed: HTTP ' . $httpStatus . ($curlError !== '' ? ', cURL error.' : '.'));
        return false;
    }

    $decoded = json_decode($response, true);
    if (!is_array($decoded) || strtolower((string) ($decoded['status'] ?? '')) !== 'success') {
        error_log('PhilSMS rejected the request or returned an invalid response.');
        return false;
    }
    return true;
}
