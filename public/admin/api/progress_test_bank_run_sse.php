<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/progress_test_bank_run_core.php';

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
@ini_set('output_buffering', 'off');
@ini_set('zlib.output_compression', '0');

header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
header('X-Accel-Buffering: no');

$post = $_POST;
$forceRebuild = !empty($post['force_rebuild']);

function pt_bank_sse_send(string $event, array $data): void
{
    $event = preg_replace("/[\r\n]+/", '', $event);
    echo 'event: ' . $event . "\n";
    echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
    if (function_exists('ob_flush')) @ob_flush();
    @flush();
}

echo ':' . str_repeat(' ', 2048) . "\n\n";
@flush();

pt_bank_sse_send('run_start', [
    'program_id' => (int)($post['program_id'] ?? 0),
    'course_id' => (int)($post['course_id'] ?? 0),
    'lesson_id' => (int)($post['lesson_id'] ?? 0),
]);

[$lessons, $err, $totalInScope] = pt_bank_resolve_lesson_batch($pdo, $post);
if ($err !== null) {
    pt_bank_sse_send('run_error', ['message' => $err]);
    pt_bank_sse_send('run_done', ['processed' => 0, 'error' => $err]);
    exit;
}

pt_bank_sse_send('batch_info', [
    'lessons_in_batch' => count($lessons),
    'full_scope_lessons' => $totalInScope,
]);

$processed = 0;
$totalAdded = 0;

foreach ($lessons as $lessonRow) {
    $lessonId = (int)$lessonRow['lesson_id'];
    try {
        $result = pt_bank_run_build($pdo, $lessonId, $forceRebuild, static function (string $event, array $data) use ($lessonId): void {
            $data['lesson_id'] = $lessonId;
            pt_bank_sse_send($event, $data);
        });
        $processed++;
        $totalAdded += (int)($result['added'] ?? 0);
    } catch (Throwable $e) {
        pt_bank_sse_send('lesson_error', ['lesson_id' => $lessonId, 'message' => $e->getMessage()]);
    }
}

pt_bank_sse_send('run_done', [
    'processed' => $processed,
    'questions_added' => $totalAdded,
    'lessons_in_batch' => count($lessons),
]);
