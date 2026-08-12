<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/CvrLiveCockpitMonitorService.php';

cw_require_flight_schedule_editor();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Content-Type: audio/mp4');
header('Content-Disposition: inline; filename="live-cockpit-chunk.m4a"');

try {
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
        http_response_code(405);
        exit;
    }
    $user = cw_current_user($pdo);
    if (!is_array($user) || (int)($user['id'] ?? 0) <= 0) {
        http_response_code(401);
        exit;
    }
    $path = (new CvrLiveCockpitMonitorService($pdo))->audioPath(
        (string)($_GET['lease_uuid'] ?? ''),
        (string)($_GET['chunk_uuid'] ?? ''),
        (int)$user['id']
    );
    $size = filesize($path);
    if ($size !== false) {
        header('Content-Length: ' . $size);
    }
    readfile($path);
} catch (Throwable $e) {
    error_log('[cvr live cockpit monitor audio] ' . $e->getMessage());
    http_response_code(404);
}
