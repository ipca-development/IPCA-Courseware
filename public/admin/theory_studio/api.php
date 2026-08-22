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
$structured = new TheoryStructuredSlideService($pdo);

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

    if ($action === 'create_template') {
        $programId = (int)($input['program_id'] ?? 0);
        $template = $structured->createTemplate($programId, $input);
        theory_studio_json(200, array(
            'ok' => true,
            'template' => $template,
            'redirect' => '/admin/theory_studio/template_editor.php?template_id='
                . (int)$template['id'] . '&program_id=' . $programId,
        ));
    }

    if ($action === 'save_template_version') {
        $templateId = (int)($input['template_id'] ?? 0);
        $programId = (int)($input['program_id'] ?? 0);
        $rows = is_array($input['placeholders'] ?? null) ? $input['placeholders'] : array();
        $placeholders = array_map('theory_studio_template_placeholder_input', $rows);
        $version = $structured->createTemplateVersion($templateId, $programId, array(
            'placeholders' => $placeholders,
            'guides' => is_array($input['guides'] ?? null) ? $input['guides'] : array(),
            'created_by_user_id' => theory_studio_current_user_id($pdo),
        ));
        $template = $structured->activateTemplateVersion($templateId, (int)$version['id'], $programId);
        theory_studio_json(200, array('ok' => true, 'template' => $template, 'version' => $version));
    }

    if ($action === 'create_structured_slide') {
        $slide = $structured->createStructuredSlide(
            (int)($input['lesson_id'] ?? 0),
            (int)($input['template_version_id'] ?? 0),
            isset($input['outline_node_id']) && (int)$input['outline_node_id'] > 0
                ? (int)$input['outline_node_id']
                : null
        );
        theory_studio_json(200, array(
            'ok' => true,
            'slide' => $slide,
            'redirect' => '/admin/theory_studio/lesson_editor.php?lesson_id='
                . (int)$slide['lesson_id'] . '&slide_id=' . (int)$slide['id'],
        ));
    }

    if ($action === 'save_structured_slide') {
        [$textValues, $mediaValues] = theory_studio_slide_values_input(
            is_array($input['values'] ?? null) ? $input['values'] : array()
        );
        $slide = $structured->saveStructuredSlide(
            (int)($input['slide_id'] ?? 0),
            (int)($input['content_revision'] ?? 0),
            array(
                'text_values' => $textValues,
                'media_values' => $mediaValues,
                'outline_node_id' => isset($input['outline_node_id']) && (int)$input['outline_node_id'] > 0
                    ? (int)$input['outline_node_id']
                    : null,
            )
        );
        theory_studio_json(200, array('ok' => true, 'slide' => $slide));
    }

    if ($action === 'reorder_structured_slides') {
        $ids = is_array($input['ordered_ids'] ?? null) ? array_map('intval', $input['ordered_ids']) : array();
        $structured->reorderStructuredSlides((int)($input['lesson_id'] ?? $input['parent_id'] ?? 0), $ids);
        theory_studio_json(200, array('ok' => true));
    }

    if ($action === 'delete_structured_slide') {
        $structured->softDeleteStructuredSlide(
            (int)($input['slide_id'] ?? 0),
            (int)($input['content_revision'] ?? 0)
        );
        theory_studio_json(200, array('ok' => true));
    }

    if ($action === 'upload_structured_media') {
        $slideId = (int)($input['slide_id'] ?? $_POST['slide_id'] ?? 0);
        theory_studio_assert_structured_slide_mutable($pdo, $svc, $slideId);
        $user = cw_current_user($pdo) ?: array();
        $asset = theory_studio_store_structured_media(
            $pdo,
            $_FILES['media'] ?? null,
            (int)($user['id'] ?? 0)
        );
        theory_studio_json(200, array('ok' => true, 'asset' => $asset));
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

function theory_studio_assert_structured_slide_mutable(
    PDO $pdo,
    TheoryContentStudioService $svc,
    int $slideId
): void {
    $stmt = $pdo->prepare(
        'SELECT c.program_id, s.source_category
         FROM slides s
         JOIN lessons l ON l.id = s.lesson_id
         JOIN courses c ON c.id = l.course_id
         WHERE s.id = ? LIMIT 1'
    );
    $stmt->execute(array($slideId));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        throw new TheoryStudioException('NOT_FOUND', 'Slide not found.', 404);
    }
    $svc->assertProgramMutable((int)$row['program_id']);
    if (strtolower((string)$row['source_category']) !== 'structured') {
        throw new TheoryStudioException(
            'LIVE_CONTENT_PROTECTED',
            'Only native structured Slides can receive Theory Studio media.',
            409
        );
    }
}

/**
 * @return array{id:int,asset_uuid:string,storage_key:string,mime_type:string,cdn_url:string}
 */
function theory_studio_store_structured_media(PDO $pdo, mixed $file, int $adminUserId): array
{
    if (!is_array($file) || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new TheoryStudioException('VALIDATION', 'Choose an image or video to upload.', 400);
    }
    $tmp = (string)($file['tmp_name'] ?? '');
    $size = (int)($file['size'] ?? 0);
    if (!is_file($tmp) || $size < 1 || $size > 250 * 1024 * 1024) {
        throw new TheoryStudioException('VALIDATION', 'Media must be between 1 byte and 250 MB.', 400);
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = strtolower((string)$finfo->file($tmp));
    $allowed = array(
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'video/mp4' => 'mp4',
        'video/quicktime' => 'mov',
    );
    if (!isset($allowed[$mime])) {
        throw new TheoryStudioException('VALIDATION', 'Media must be JPG, PNG, WebP, MP4, or MOV.', 400);
    }
    $uuid = theory_studio_uuid();
    $key = 'theory_studio/media/' . date('Y/m') . '/' . $uuid . '.' . $allowed[$mime];
    $stream = fopen($tmp, 'rb');
    if ($stream === false) {
        throw new TheoryStudioException('MEDIA', 'The uploaded media could not be read.', 500);
    }
    try {
        cw_spaces_put_private_stream($key, $stream, $size, $mime);
        if (!cw_spaces_put_acl($key, 'public-read')) {
            throw new RuntimeException('Could not publish uploaded media.');
        }
    } catch (Throwable $e) {
        throw new TheoryStudioException('MEDIA', 'Media storage is unavailable.', 503);
    } finally {
        fclose($stream);
    }
    $width = 0;
    $height = 0;
    if (str_starts_with($mime, 'image/')) {
        $dimensions = @getimagesize($tmp);
        $width = (int)($dimensions[0] ?? 0);
        $height = (int)($dimensions[1] ?? 0);
    }
    $orientation = $width > 0 && $height > 0
        ? ($width === $height ? 'square' : ($width > $height ? 'landscape' : 'portrait'))
        : 'landscape';
    $insert = $pdo->prepare(
        'INSERT INTO ipca_training_media_library
            (asset_uuid, storage_key, original_filename, mime_type, byte_size,
             width, height, orientation, analysis_status, created_by_user_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $insert->execute(array(
        $uuid,
        $key,
        mb_substr((string)($file['name'] ?? ''), 0, 255),
        $mime,
        $size,
        $width,
        $height,
        $orientation,
        'not_required',
        max(0, $adminUserId),
    ));
    $cfg = cw_spaces_config();
    return array(
        'id' => (int)$pdo->lastInsertId(),
        'asset_uuid' => $uuid,
        'storage_key' => $key,
        'mime_type' => $mime,
        'cdn_url' => rtrim((string)$cfg['cdnBase'], '/') . '/' . ltrim($key, '/'),
    );
}

function theory_studio_uuid(): string
{
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    $hex = bin2hex($data);
    return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
        . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
}

/** @param array<string,mixed> $row @return array<string,mixed> */
function theory_studio_template_placeholder_input(array $row): array
{
    return array(
        'placeholder_key' => (string)($row['placeholder_key'] ?? ''),
        'content_type' => ucfirst(strtolower((string)($row['type'] ?? ''))),
        'semantic_role' => (string)($row['semantic_role'] ?? ''),
        'x' => (int)($row['x'] ?? 0),
        'y' => (int)($row['y'] ?? 0),
        'w' => (int)($row['width'] ?? 0),
        'h' => (int)($row['height'] ?? 0),
        'reading_order' => (int)($row['reading_order'] ?? 0),
        'is_required' => !empty($row['required']),
        'allowed_content' => array(
            'rich_text' => strtolower((string)($row['type'] ?? '')) === 'text',
        ),
        'allowed_style' => array(
            'font_family' => (string)($row['font_family'] ?? 'Arial, Helvetica, sans-serif'),
            'font_size' => (int)($row['font_size'] ?? 18),
            'font_weight' => (int)($row['font_weight'] ?? 400),
            'text_color' => (string)($row['text_color'] ?? '#0f172a'),
            'line_height' => (float)($row['line_height'] ?? 1.35),
            'alignment' => (string)($row['alignment'] ?? 'left'),
            'vertical_alignment' => (string)($row['vertical_alignment'] ?? 'top'),
            'background_color' => (string)($row['background_color'] ?? 'transparent'),
            'border_color' => (string)($row['border_color'] ?? 'transparent'),
            'border_width' => (int)($row['border_width'] ?? 0),
            'padding' => (int)($row['padding'] ?? 8),
        ),
        'allowed_behavior' => array(
            'overflow' => (string)($row['overflow_behavior'] ?? 'auto'),
            'media_fit' => (string)($row['media_fit'] ?? 'contain'),
            'author_can_resize' => !empty($row['author_can_resize']),
            'author_can_reposition' => !empty($row['author_can_reposition']),
            'locked' => !empty($row['locked']),
            'z_index' => (int)($row['z_index'] ?? 1),
        ),
    );
}

/**
 * @param array<string,mixed> $values
 * @return array{0:list<array<string,mixed>>,1:list<array<string,mixed>>}
 */
function theory_studio_slide_values_input(array $values): array
{
    $text = array();
    $media = array();
    foreach ($values as $key => $value) {
        if (!is_array($value)) {
            continue;
        }
        $type = strtolower((string)($value['type'] ?? ''));
        if ($type === 'text') {
            foreach (array('en', 'es') as $lang) {
                $htmlKey = $lang . '_html';
                $html = theory_studio_sanitize_rich_text((string)($value[$htmlKey] ?? ''));
                if ($lang === 'es' && trim(strip_tags($html)) === '') {
                    continue;
                }
                $plain = html_entity_decode(
                    trim((string)preg_replace('/\s+/u', ' ', strip_tags(
                        preg_replace('#<(br|/p|/li|/tr)>#i', "\n", $html) ?? $html
                    ))),
                    ENT_QUOTES | ENT_HTML5,
                    'UTF-8'
                );
                $text[] = array(
                    'placeholder_key' => (string)$key,
                    'lang' => $lang,
                    'plain_text' => $plain,
                    'content_json' => array('html' => $html),
                );
            }
        } elseif (($type === 'image' || $type === 'video') && (int)($value['media_asset_id'] ?? 0) > 0) {
            $media[] = array(
                'placeholder_key' => (string)$key,
                'media_library_id' => (int)$value['media_asset_id'],
                'content_json' => array('fit' => (string)($value['fit'] ?? 'contain')),
            );
        }
    }
    return array($text, $media);
}

function theory_studio_sanitize_rich_text(string $html): string
{
    $allowed = '<p><br><strong><b><em><i><u><ul><ol><li><a><table><thead><tbody><tr><th><td><aside>';
    $html = strip_tags($html, $allowed);
    $html = (string)preg_replace('/\s(?:on\w+|style|class)\s*=\s*(".*?"|\'.*?\'|[^\s>]+)/is', '', $html);
    $html = (string)preg_replace_callback(
        '/\shref\s*=\s*(["\'])(.*?)\1/is',
        static function (array $match): string {
            $url = trim((string)$match[2]);
            return preg_match('#^(https?://|mailto:|#)#i', $url)
                ? ' href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '"'
                : '';
        },
        $html
    );
    return trim($html);
}

function theory_studio_current_user_id(PDO $pdo): int
{
    $user = cw_current_user($pdo) ?: array();
    return (int)($user['id'] ?? 0);
}
