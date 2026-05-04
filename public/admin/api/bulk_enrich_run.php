<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/openai.php';
require_once __DIR__ . '/../../../src/bulk_enrich_video_manifest.php';
require_once __DIR__ . '/../../../src/bulk_enrich_run_core.php';

cw_require_admin();

session_write_close();

ignore_user_abort(true);

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
header('X-Accel-Buffering: no');

@set_time_limit(1800);
@ini_set('max_execution_time', '1800');
@ini_set('default_socket_timeout', '600');
@ini_set('output_buffering', 'off');
@ini_set('zlib.output_compression', '0');

function bulk_enrich_html_progress(string $msg): void
{
    echo "<div style='font-family:system-ui;font-size:14px;padding:4px 0;'>" . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . "</div>\n";
    echo str_repeat(' ', 4096) . "\n";
    if (function_exists('ob_flush')) {
        @ob_flush();
    }
    @flush();
}

$post = $_POST;
$programKey = trim((string)($post['program_key'] ?? 'private'));
$videoManifestFile = trim((string)($post['video_manifest'] ?? 'kings_videos_manifest.json'));
$videoManifestPath = bec_resolve_video_manifest_file($videoManifestFile) ?? '';

$flags = bulk_enrich_core_parse_flags($post);
$skipExisting = isset($post['skip_existing']);
$limit = (int)($post['limit'] ?? 0);
$courseId = (int)($post['course_id'] ?? 0);

$targetSlideIds = [];
$rawTargets = $post['target_slide_ids'] ?? null;
if (is_array($rawTargets)) {
    $targetSlideIds = array_values(array_unique(array_map('intval', $rawTargets)));
}
if ($targetSlideIds !== []) {
    $skipExisting = false;
}

echo "<!doctype html><html><head><meta charset='utf-8'><title>Bulk Canonical Builder</title></head><body style='padding:16px'>";
echo str_repeat(' ', 4096);
@flush();

bulk_enrich_html_progress('Bulk run started…');

[$slides, $err, $totalInScope] = bulk_enrich_core_resolve_slide_batch($pdo, $post);
if ($err !== null) {
    bulk_enrich_html_progress('ERROR: ' . $err);
    echo "<p><a href='/admin/bulk_enrich.php'>Back</a></p></body></html>";
    exit;
}

$batchSize = max(0, (int)($post['batch_size'] ?? 0));
if ($targetSlideIds === [] && $batchSize > 0) {
    bulk_enrich_html_progress('Batch offset ' . (int)($post['batch_offset'] ?? 0) . ', size ' . $batchSize . ' — running ' . count($slides) . ' slide(s) (full scope: ' . $totalInScope . ').');
} else {
    bulk_enrich_html_progress('Slides in scope: ' . count($slides));
}

$emit = static function (string $event, array $data) use (&$courseId): void {
    if ($event === 'step' && isset($data['message'])) {
        bulk_enrich_html_progress((string)$data['message']);
        return;
    }
    if ($event === 'slide_start') {
        bulk_enrich_html_progress('Slide ' . ($data['slide_id'] ?? '?') . ' (lesson ' . ($data['external_lesson_id'] ?? '?') . ' page ' . ($data['page_number'] ?? '?') . ')…');
        return;
    }
    if ($event === 'slide_skipped') {
        bulk_enrich_html_progress('SKIP slide ' . ($data['slide_id'] ?? '') . ' — ' . ($data['reason'] ?? ''));
        return;
    }
    if ($event === 'slide_error') {
        bulk_enrich_html_progress('ERROR slide ' . ($data['slide_id'] ?? '') . ': ' . ($data['error'] ?? ''));
        return;
    }
    if ($event === 'limit_reached') {
        bulk_enrich_html_progress('Limit reached.');
        return;
    }
    if ($event === 'run_done') {
        bulk_enrich_html_progress('DONE. Slides processed: ' . (int)($data['processed'] ?? 0));
        return;
    }
    if ($event === 'slide_done') {
        if (isset($data['ok']) && $data['ok'] === false) {
            bulk_enrich_html_progress('Slide ' . ($data['slide_id'] ?? '?') . ' finished with errors.');
        }
        return;
    }
};

$n = bulk_enrich_core_run(
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

echo "<p><a href='/admin/bulk_enrich.php'>Back</a> | <a href='/admin/slides.php?course_id={$courseId}'>Slides</a></p>";
echo '</body></html>';
