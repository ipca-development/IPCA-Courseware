<?php
require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/mailer.php';

header('Content-Type: application/json; charset=utf-8');

$result = cw_send_mail([
    'to' => [
        [
            'email' => 'YOUR_REAL_EMAIL@example.com',
            'name'  => 'Test Recipient'
        ]
    ],
    'subject' => 'IPCA Courseware Mail Test',
    'html' => '<p>This is a test email from the IPCA Courseware system.</p>',
    'text' => 'This is a test email from the IPCA Courseware system.'
]);

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);