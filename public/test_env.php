<?php
require_once __DIR__ . '/../src/bootstrap.php';

header('Content-Type: text/plain; charset=utf-8');

var_dump(
    getenv('MAIL_SMTP_HOST'),
    getenv('MAIL_SMTP_USERNAME'),
    getenv('MAIL_FROM_EMAIL'),
    getenv('CW_VIDEOS_BASE')
);
