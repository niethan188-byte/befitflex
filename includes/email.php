<?php
declare(strict_types=1);

use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;

require_once __DIR__ . '/../vendor/autoload.php';

function mailtrap_smtp_configured(): bool
{
    $mailer = getenv('MAIL_MAILER') ?: 'smtp';
    $host = getenv('MAIL_HOST');
    $username = getenv('MAIL_USERNAME');
    $password = getenv('MAIL_PASSWORD');
    $port = getenv('MAIL_PORT') ?: '2525';
    $encryption = strtolower((string) (getenv('MAIL_ENCRYPTION') ?: 'tls'));

    return strtolower($mailer) === 'smtp'
        && is_string($host) && trim($host) !== ''
        && is_string($username) && trim($username) !== ''
        && is_string($password) && $password !== ''
        && ctype_digit((string) $port) && (int) $port >= 1 && (int) $port <= 65535
        && in_array($encryption, ['tls', 'ssl'], true)
        && class_exists(PHPMailer::class);
}

/** Send an email through Mailtrap Sandbox SMTP. Returns false when unavailable or failed. */
function send_email(string $to, string $subject, string $html, ?string $text = null): bool
{
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        error_log('Mailtrap SMTP email skipped: invalid recipient.');
        return false;
    }
    if (!mailtrap_smtp_configured()) {
        error_log('Mailtrap SMTP email skipped: SMTP configuration or PHPMailer is unavailable.');
        return false;
    }

    $fromEmail = getenv('MAIL_FROM_ADDRESS') ?: 'notifications@example.com';
    $fromName = getenv('MAIL_FROM_NAME') ?: 'Be Fit Flex Gym';
    $replyTo = getenv('MAIL_REPLY_TO');
    if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
        error_log('Mailtrap SMTP email skipped: invalid sender address.');
        return false;
    }

    try {
        $mailer = new PHPMailer(true);
        $mailer->isSMTP();
        $mailer->Host = trim((string) getenv('MAIL_HOST'));
        $mailer->SMTPAuth = true;
        $mailer->Username = (string) getenv('MAIL_USERNAME');
        $mailer->Password = (string) getenv('MAIL_PASSWORD');
        $mailer->Port = (int) (getenv('MAIL_PORT') ?: '2525');
        $mailer->SMTPSecure = strtolower((string) (getenv('MAIL_ENCRYPTION') ?: 'tls')) === 'ssl'
            ? PHPMailer::ENCRYPTION_SMTPS
            : PHPMailer::ENCRYPTION_STARTTLS;
        $mailer->Timeout = 15;
        $mailer->CharSet = PHPMailer::CHARSET_UTF8;
        $mailer->setFrom($fromEmail, $fromName);
        $mailer->addAddress($to);
        if (is_string($replyTo) && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
            $mailer->addReplyTo($replyTo);
        }
        $mailer->Subject = $subject;
        $mailer->isHTML(true);
        $mailer->Body = $html;
        $mailer->AltBody = $text ?: trim(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $html)));

        return $mailer->send();
    } catch (Exception $ex) {
        error_log('Mailtrap SMTP email failed: ' . $ex->getMessage());
        return false;
    }
}
