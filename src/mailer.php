<?php
declare(strict_types=1);

use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\PHPMailer;

/**
 * Courseware Mailer
 *
 * Recommended provider:
 * - Postmark SMTP
 *
 * Requirements:
 * - Composer package: phpmailer/phpmailer
 * - SMTP credentials in environment
 *
 * Return format from cw_send_mail():
 * [
 *   'ok' => bool,
 *   'provider' => 'smtp',
 *   'message_id' => string|null,
 *   'error' => string|null,
 * ]
 */

if (!class_exists(PHPMailer::class)) {
    $autoloadPaths = [
        __DIR__ . '/../vendor/autoload.php',
        __DIR__ . '/../../vendor/autoload.php',
        dirname(__DIR__) . '/vendor/autoload.php',
    ];

    foreach ($autoloadPaths as $autoload) {
        if (is_file($autoload)) {
            require_once $autoload;
            break;
        }
    }
}

if (!class_exists(PHPMailer::class)) {
    throw new RuntimeException(
        'PHPMailer not found. Run: composer require phpmailer/phpmailer'
    );
}

function cw_env(string $key, ?string $default = null): ?string
{
    $value = getenv($key);
    if ($value === false || $value === '') {
        return $default;
    }
    return (string)$value;
}

function cw_env_required(string $key): string
{
    $value = getenv($key);
    if ($value === false || $value === '') {
        throw new RuntimeException('Missing required env var: ' . $key);
    }
    return (string)$value;
}

function cw_mail_bool_env(string $key, bool $default = false): bool
{
    $value = cw_env($key);
    if ($value === null) {
        return $default;
    }

    $v = strtolower(trim($value));
    return in_array($v, ['1', 'true', 'yes', 'on'], true);
}

function cw_mail_normalize_addresses(string|array $input): array
{
    if (is_string($input)) {
        $input = trim($input);
        if ($input === '') {
            return [];
        }

        $decoded = json_decode($input, true);
        if (is_array($decoded)) {
            $input = $decoded;
        } else {
            $parts = preg_split('/[;,]+/', $input);
            $input = is_array($parts) ? $parts : [$input];
        }
    }

    if (!is_array($input)) {
        return [];
    }

    $result = [];

    foreach ($input as $entry) {
        if (is_string($entry)) {
            $email = trim($entry);
            if ($email !== '') {
                $result[] = [
                    'email' => $email,
                    'name' => ''
                ];
            }
            continue;
        }

        if (is_array($entry)) {
            $email = trim((string)($entry['email'] ?? ''));
            $name  = trim((string)($entry['name'] ?? ''));
            if ($email !== '') {
                $result[] = [
                    'email' => $email,
                    'name' => $name
                ];
            }
        }
    }

    return $result;
}

function cw_mail_html_to_text(string $html): string
{
    $text = preg_replace('/<br\s*\/?>/i', "\n", $html);
    $text = preg_replace('/<\/p>/i', "\n\n", (string)$text);
    $text = strip_tags((string)$text);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace("/[ \t]+\n/", "\n", $text);
    $text = preg_replace("/\n{3,}/", "\n\n", $text);
    return trim((string)$text);
}

function cw_mailer_build(): PHPMailer
{
    $mail = new PHPMailer(true);

    $host = cw_env_required('MAIL_SMTP_HOST');
    $port = (int)cw_env('MAIL_SMTP_PORT', '587');
    $username = cw_env_required('MAIL_SMTP_USERNAME');
    $password = cw_env_required('MAIL_SMTP_PASSWORD');
    $fromEmail = cw_env_required('MAIL_FROM_EMAIL');
    $fromName = cw_env('MAIL_FROM_NAME', 'IPCA Courseware');
    $replyToEmail = cw_env('MAIL_REPLY_TO_EMAIL', $fromEmail);
    $replyToName = cw_env('MAIL_REPLY_TO_NAME', $fromName);
    $security = strtolower((string)cw_env('MAIL_SMTP_SECURITY', 'tls'));
    $allowSelfSigned = cw_mail_bool_env('MAIL_SMTP_ALLOW_SELF_SIGNED', false);
    $timeout = (int)cw_env('MAIL_SMTP_TIMEOUT', '30');
    $debug = (int)cw_env('MAIL_SMTP_DEBUG', '0');

    $mail->isSMTP();
    $mail->Host = $host;
    $mail->Port = $port;
    $mail->SMTPAuth = true;
    $mail->Username = $username;
    $mail->Password = $password;
    $mail->Timeout = $timeout;
    $mail->CharSet = 'UTF-8';
    $mail->Encoding = 'base64';
    $mail->isHTML(true);

    if ($debug > 0) {
        $mail->SMTPDebug = $debug;
    }

    if ($security === 'tls') {
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    } elseif ($security === 'ssl') {
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    } else {
        $mail->SMTPSecure = false;
        $mail->SMTPAutoTLS = false;
    }

    if ($allowSelfSigned) {
        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
            ],
        ];
    }

    $mail->setFrom($fromEmail, (string)$fromName);
    if ($replyToEmail !== '') {
        $mail->addReplyTo($replyToEmail, (string)$replyToName);
    }

    return $mail;
}

/**
 * Send one email immediately.
 *
 * Accepted payload:
 * [
 *   'to' => 'user@example.com' OR ['user@example.com', ...] OR [['email'=>'','name'=>''], ...],
 *   'cc' => ... same format optional,
 *   'bcc' => ... same format optional,
 *   'subject' => 'Subject',
 *   'html' => '<p>Hello</p>',
 *   'text' => 'Hello' optional,
 *   'attachments' => [
 *      ['path' => '/tmp/file.pdf', 'name' => 'Report.pdf'],
 *   ] optional,
 *   'headers' => [
 *      'X-Custom-Header' => 'Value'
 *   ] optional
 * ]
 */
function cw_send_mail(array $message): array
{
    try {
        $to = cw_mail_normalize_addresses($message['to'] ?? []);
        $cc = cw_mail_normalize_addresses($message['cc'] ?? []);
        $bcc = cw_mail_normalize_addresses($message['bcc'] ?? []);

        if (!$to) {
            throw new InvalidArgumentException('No recipient specified');
        }

        $subject = trim((string)($message['subject'] ?? ''));
        if ($subject === '') {
            throw new InvalidArgumentException('Missing subject');
        }

        $html = (string)($message['html'] ?? '');
        $text = (string)($message['text'] ?? '');

        if ($html === '' && $text === '') {
            throw new InvalidArgumentException('Missing html/text body');
        }

        if ($html === '' && $text !== '') {
            $html = nl2br(htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        if ($text === '' && $html !== '') {
            $text = cw_mail_html_to_text($html);
        }

        $mail = cw_mailer_build();

        foreach ($to as $r) {
            $mail->addAddress($r['email'], $r['name']);
        }

        foreach ($cc as $r) {
            $mail->addCC($r['email'], $r['name']);
        }

        foreach ($bcc as $r) {
            $mail->addBCC($r['email'], $r['name']);
        }

        $mail->Subject = $subject;
        $mail->Body = $html;
        $mail->AltBody = $text;

        $attachments = $message['attachments'] ?? [];
        if (is_array($attachments)) {
            foreach ($attachments as $attachment) {
                if (!is_array($attachment)) {
                    continue;
                }

                $path = trim((string)($attachment['path'] ?? ''));
                $name = trim((string)($attachment['name'] ?? ''));

                if ($path === '' || !is_file($path)) {
                    continue;
                }

                if ($name !== '') {
                    $mail->addAttachment($path, $name);
                } else {
                    $mail->addAttachment($path);
                }
            }
        }

        $headers = $message['headers'] ?? [];
        if (is_array($headers)) {
            foreach ($headers as $headerName => $headerValue) {
                $headerName = trim((string)$headerName);
                $headerValue = trim((string)$headerValue);
                if ($headerName !== '' && $headerValue !== '') {
                    $mail->addCustomHeader($headerName, $headerValue);
                }
            }
        }

        $mail->send();

        $messageId = null;
        if (property_exists($mail, 'MessageID') && is_string($mail->MessageID) && $mail->MessageID !== '') {
            $messageId = $mail->MessageID;
        }

        return [
            'ok' => true,
            'provider' => 'smtp',
            'message_id' => $messageId,
            'error' => null,
        ];
    } catch (PHPMailerException $e) {
        return [
            'ok' => false,
            'provider' => 'smtp',
            'message_id' => null,
            'error' => $e->getMessage(),
        ];
    } catch (Throwable $e) {
        return [
            'ok' => false,
            'provider' => 'smtp',
            'message_id' => null,
            'error' => $e->getMessage(),
        ];
    }
}