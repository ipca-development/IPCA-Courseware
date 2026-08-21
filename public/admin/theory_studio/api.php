<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../../src/spaces.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

/**
 * @param array<string,mixed> $payload
 */
function theory_studio_json(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function theory_studio_require_csrf(array $input): void
{
    $provided = (string)($input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if ($provided === '' || !hash_equals(theory_studio_csrf_token(), $provided)) {
        theory_studio_json(403, array(
            'ok' => false,
            'error_code' => 'CSRF',
            'message' => 'Your session could not be verified. Reload and try again.',
        ));
    }
}

$raw = file_get_contents('php://input') ?: '';
$contentType = (string)($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '');
$input = array();
if (str_contains(strtolower($contentType), 'application/json')) {
    $decoded = json_decode($raw, true);
    $input = is_array($decoded) ? $decoded : array();
} else {
    $input = $_POST;
}
$action = trim((string)($input['action'] ?? $_POST['action'] ?? ''));
$svc = new TheoryContentStudioService($pdo);

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        theory_studio_json(405, array('ok' => false, 'error_code' => 'METHOD', 'message' => 'POST required.'));
    }
    theory_studio_require_csrf($input);

    if ($action === 'create_program') {
        $cover = theory_studio_store_cover($_FILES['cover'] ?? null);
        if ($cover !== '') {
            $input['cover_image_path'] = $cover;
        }
        $program = $svc->createProgram($input);
        theory_studio_json(200, array(
            'ok' => true,
            'program' => $program,
            'redirect' => '/admin/theory_studio/courses.php?program_id=' . (int)$program['id'],
        ));
    }

    if ($action === 'create_course') {
        $programId = (int)($input['program_id'] ?? $input['parent_id'] ?? 0);
        $cover = theory_studio_store_cover($_FILES['cover'] ?? null);
        if ($cover !== '') {
            $input['cover_image_path'] = $cover;
        }
        $course = $svc->createCourse($programId, $input);
        theory_studio_json(200, array('ok' => true, 'course' => $course));
    }

    if ($action === 'reorder_courses') {
        $programId = (int)($input['program_id'] ?? $input['parent_id'] ?? 0);
        $ids = $input['ordered_ids'] ?? array();
        if (!is_array($ids)) {
            $ids = array();
        }
        $svc->reorderCourses($programId, array_map('intval', $ids));
        theory_studio_json(200, array('ok' => true));
    }

    if ($action === 'create_lessons') {
        $courseId = (int)($input['course_id'] ?? $input['parent_id'] ?? 0);
        $lessons = $svc->createLessons($courseId, $input);
        theory_studio_json(200, array('ok' => true, 'lessons' => $lessons));
    }

    if ($action === 'reorder_lessons') {
        $courseId = (int)($input['course_id'] ?? $input['parent_id'] ?? 0);
        $ids = $input['ordered_ids'] ?? array();
        if (!is_array($ids)) {
            $ids = array();
        }
        $svc->reorderLessons($courseId, array_map('intval', $ids));
        theory_studio_json(200, array('ok' => true));
    }

    if ($action === 'add_slide') {
        $svc->addSlide((int)($input['lesson_id'] ?? 0));
    }

    if ($action === 'publish' || $action === 'create_draft_from_live' || $action === 'retire') {
        $svc->publish((int)($input['program_id'] ?? 0));
    }

    theory_studio_json(400, array('ok' => false, 'error_code' => 'UNKNOWN_ACTION', 'message' => 'Unknown action.'));
} catch (TheoryStudioException $e) {
    theory_studio_json($e->httpStatus, array(
        'ok' => false,
        'error_code' => $e->errorCode,
        'message' => $e->getMessage(),
    ));
} catch (Throwable $e) {
    theory_studio_json(500, array(
        'ok' => false,
        'error_code' => 'SERVER',
        'message' => 'The request could not be completed.',
    ));
}

function theory_studio_store_cover(mixed $file): string
{
    if (!is_array($file) || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return '';
    }
    if ((int)($file['error'] ?? 1) !== UPLOAD_ERR_OK) {
        throw new TheoryStudioException('VALIDATION', 'Cover image could not be uploaded.', 400);
    }
    $tmp = (string)($file['tmp_name'] ?? '');
    $name = (string)($file['name'] ?? 'cover.jpg');
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!in_array($ext, array('jpg', 'jpeg', 'png', 'webp'), true)) {
        throw new TheoryStudioException('VALIDATION', 'Cover images must be JPG, PNG, or WebP.', 400);
    }
    $bytes = is_file($tmp) ? (string)file_get_contents($tmp) : '';
    if ($bytes === '') {
        throw new TheoryStudioException('VALIDATION', 'Cover image was empty.', 400);
    }
    $key = 'theory_studio/covers/' . date('Ymd') . '-' . bin2hex(random_bytes(8)) . '.' . ($ext === 'jpeg' ? 'jpg' : $ext);
    try {
        cw_spaces_put_object($key, $bytes, $ext === 'png' ? 'image/png' : ($ext === 'webp' ? 'image/webp' : 'image/jpeg'));
    } catch (Throwable $e) {
        throw new TheoryStudioException('MEDIA', 'Cover storage is not configured in this environment.', 503);
    }
    return $key;
}
