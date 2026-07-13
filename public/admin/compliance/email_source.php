<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceAccess.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceCommsCenterEngine.php';

compliance_require_access($pdo);

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$format = (string)($_GET['format'] ?? 'source');
if ($id <= 0) {
    http_response_code(404);
    echo 'Email not found.';
    exit;
}

$st = $pdo->prepare('SELECT * FROM ipca_compliance_emails WHERE id = ? LIMIT 1');
$st->execute(array($id));
$email = $st->fetch(PDO::FETCH_ASSOC);
if (!is_array($email)) {
    http_response_code(404);
    echo 'Email not found.';
    exit;
}

$subject = (string)($email['subject'] ?? '(no subject)');
$filename = ComplianceCommsCenterEngine::sanitizeFilename('email-' . $id . '-' . substr($subject, 0, 60));

if ($format === 'eml') {
    $headers = array();
    $headers[] = 'Subject: ' . $subject;
    $headers[] = 'From: ' . (string)($email['from_email'] ?? '');
    $headers[] = 'Date: ' . (string)($email['received_at'] ?? $email['sent_at'] ?? $email['created_at'] ?? '');
    $headers[] = 'Message-ID: ' . (string)($email['message_id_header'] ?? '');
    $headers[] = 'Content-Type: ' . ((string)($email['html_body'] ?? '') !== '' ? 'text/html; charset=utf-8' : 'text/plain; charset=utf-8');
    $body = (string)($email['html_body'] ?? '');
    if (trim($body) === '') {
        $body = (string)($email['text_body'] ?? '');
    }
    header('Content-Type: message/rfc822; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.eml"');
    echo implode("\r\n", $headers) . "\r\n\r\n" . $body;
    exit;
}

header('Content-Type: text/plain; charset=utf-8');
header('Content-Disposition: inline; filename="' . $filename . '-source.txt"');
$raw = (string)($email['raw_payload_json'] ?? '');
if ($raw !== '') {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        echo json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
}
print_r($email);
