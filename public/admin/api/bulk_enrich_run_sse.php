<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/openai.php';
require_once __DIR__ . '/../../../src/bulk_enrich_video_manifest.php';
require_once __DIR__ . '/../../../src/bulk_enrich_run_core.php';

cw_require_admin();

session_write_close();

ignore_user_abort(true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Content-Type: text/plain; charset=utf-8', true, 405);
    echo 'POST required';
    exit;
}

@set_time_limit(1800);
@ini_set('max_execution_time', '1800');
@ini_set('default_socket_timeout', '600');
@ini_set('output_buffering', 'off');
@ini_set('zlib.output_compression', '0');

header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
header('X-Accel-Buffering: no');

$post = $_POST;
$programKey = trim((string)($post['program_key'] ?? 'private'));
$videoManifestFile = trim((string)($post['video_manifest'] ?? 'kings_videos_manifest.json'));
$videoManifestPath = bec_resolve_video_manifest_file($videoManifestFile) ?? '';

$flags = bulk_enrich_core_parse_flags($post);
$skipExisting = isset($post['skip_existing']);
$limit = (int)($post['limit'] ?? 0);

$rawTargets = $post['target_slide_ids'] ?? null;
if (is_array($rawTargets) && count(array_filter($rawTargets, 'strlen')) > 0) {
    $skipExisting = false;
}

function bulk_enrich_sse_send(string $event, array $data): void
{
    $event = preg_replace("/[\r\n]+/", '', $event);
    echo 'event: ' . $event . "\n";
    echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
    if (function_exists('ob_flush')) {
        @ob_flush();
    }
    @flush();
}

// Initial padding so some proxies flush headers
echo ':' . str_repeat(' ', 2048) . "\n\n";
@flush();

bulk_enrich_sse_send('run_start', [
    'course_id' => (int)($post['course_id'] ?? 0),
    'flags' => $flags,
]);

[$slides, $err, $totalInScope] = bulk_enrich_core_resolve_slide_batch($pdo, $post);
if ($err !== null) {
    bulk_enrich_sse_send('run_error', ['message' => $err]);
    bulk_enrich_sse_send('run_done', ['processed' => 0, 'error' => $err]);
    exit;
}

bulk_enrich_sse_send('batch_info', [
    'slides_in_batch' => count($slides),
    'full_scope_slides' => $totalInScope,
]);

$emit = static function (string $event, array $data): void {
    bulk_enrich_sse_send($event, $data);
};

try {
    bulk_enrich_core_run(
        $pdo,
        $CDN_BASE,
        $flags,
        $slides,
        $limit,
        $skipExisting,
        $programKey,
        $videoManifestPath,
        $emit
    );
} catch (Throwable $e) {
    bulk_enrich_sse_send('run_error', ['message' => $e->getMessage()]);
    bulk_enrich_sse_send('run_done', ['processed' => 0, 'fatal' => true]);
}
