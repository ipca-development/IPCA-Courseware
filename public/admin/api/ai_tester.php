<?php

ini_set('display_errors', '1');
error_reporting(E_ALL);

declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$raw = file_get_contents('php://input');

echo json_encode([
    'ok' => true,
    'debug' => 'api reached',
    'raw' => $raw
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
exit;

function json_out(array $x): void {
    echo json_encode($x, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}