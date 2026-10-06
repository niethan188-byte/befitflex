<?php
declare(strict_types=1);

/** Send an email through Mailtrap. Returns false when email is not configured or fails. */
function send_email(string $to, string $subject, string $html, ?string $text = null): bool
{
    $apiKey = getenv('MAILTRAP_API_TOKEN');
    $endpoint = getenv('MAILTRAP_API_URL') ?: 'https://send.api.mailtrap.io/api/send';
    $fromEmail = getenv('MAILTRAP_FROM_EMAIL') ?: 'notifications@example.com';
    $fromName = getenv('MAILTRAP_FROM_NAME') ?: 'Be Fit Flex Gym';
    $replyTo = getenv('MAILTRAP_REPLY_TO');

    if (!$apiKey || !filter_var($to, FILTER_VALIDATE_EMAIL) || !function_exists('curl_init')) {
        error_log('Mailtrap email skipped: missing API token, invalid recipient, or cURL is disabled.');
        return false;
    }

    $payloadData = [
        'from' => ['email' => $fromEmail, 'name' => $fromName],
        'to' => [['email' => $to]],
        'category' => 'Be Fit Flex notification',
        'subject' => $subject,
        'text' => $text ?: trim(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $html))),
        'html' => $html,
    ];
    if ($replyTo && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
        $payloadData['reply_to'] = ['email' => $replyTo];
    }
    $payload = json_encode($payloadData, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);

    for ($attempt = 1; $attempt <= 3; $attempt++) {
        $curl = curl_init($endpoint);
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
        ]);

        $response = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if ($response !== false && $status >= 200 && $status < 300) {
            return true;
        }

        $temporary = $status === 429 || $status >= 500;
        if (!$temporary || $attempt === 3) {
            error_log('Mailtrap email failed: HTTP ' . $status . ($error ? ' ' . $error : ' ' . (string) $response));
            return false;
        }

        usleep($attempt * 250000);
    }

    return false;
}