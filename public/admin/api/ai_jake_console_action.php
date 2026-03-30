<?php
declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/../../../src/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$raw = file_get_contents('php://input');

echo json_encode([
    'ok' => true,
    'debug' => 'api reached',
    'raw' => $raw
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
exit;