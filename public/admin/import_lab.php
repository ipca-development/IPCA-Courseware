<?php
require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
require_once __DIR__ . '/../../src/openai.php';

cw_require_admin();

// --- keep long-running requests alive ---
@set_time_limit(600);
@ini_set('max_execution_time', '600');
@ini_set('default_socket_timeout', '20');
@ini_set('output_buffering', 'off');
@ini_set('zlib.output_compression', '0');

$templateKeys = template_keys();

/**
 * Print progress to browser (keeps connection alive)
 */
function progress(string $msg): void {
    echo "<div style='font-family:system-ui; font-size:14px; padding:4px 0;'>" . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . "</div>";
    @ob_flush();
    @flush();
}

function http_head_ok(string $url, int $timeoutSeconds = 8, int $retries = 3): bool {
    for ($attempt = 1; $attempt <= $retries; $attempt++) {
        $ctx = stream_context_create([
            'http' => [
                'method' => 'HEAD',
                'timeout' => $timeoutSeconds,
                'ignore_errors' => true,
                'header' => "User-Agent: IPCA-Courseware\r\n"
            ]
        ]);

        $headers = @get_headers($url, 1, $ctx);

        if ($headers) {
            $first = is_array($headers) ? ($headers[0] ?? '') : '';
            if (is_string($first) && strpos($first, '200') !== false) return true;

            // definitive negatives
            if (is_string($first) && (strpos($first, '404') !== false || strpos($first, '403') !== false)) return false;
        }

        usleep(250000); // 250ms
    }
    return false;
}

function detect_page_count(string $cdnBase, string $programKey, int $externalLessonId, int $maxCap = 300): int {
    $p1 = image_path_for($programKey, $externalLessonId, 1);
    if (!http_head_ok(cdn_url($cdnBase, $p1))) {
        return 0;
    }

    $lo = 1;
    $hi = 2;

    while ($hi <= $maxCap) {
        $path = image_path_for($programKey, $externalLessonId, $hi);
        if (http_head_ok(cdn_url($cdnBase, $path))) {
            $lo = $hi;
            $hi *= 2;
        } else {
            break;
        }
    }

    if ($hi > $maxCap) $hi = $maxCap;

    $left = $lo;
    $right = $hi;

    while ($left < $right) {
        $mid = intdiv($left + $right + 1, 2);
        $path = image_path_for($programKey, $externalLessonId, $mid);
        if (http_head_ok(cdn_url($cdnBase, $path))) {
            $left = $mid;
        } else {
            $right = $mid - 1;
        }
    }

    return $left;
}

function parse_lesson_ids(string $raw): array {
    $raw = trim($raw);
    if ($raw === '') return [];

    $data = json_decode($raw, true);
    if (!is_array($data)) return [];

    if (isset($data['lessonIds']) && is_array($data['lessonIds'])) {
        return array_values(array_map('intval', array_filter($data['lessonIds'], 'is_numeric')));
    }

    if (isset($data[0]) && is_numeric($data[0])) {
        return array_values(array_map('intval', array_filter($data, 'is_numeric')));
    }

    return [];
}

/**
 * Parse bulk labs JSON:
 * [
 *   {"labId":10001,"courseTitle":"...","courseSlug":"...","courseOrder":0,"lessonIds":[10002,10003]},
 *   ...
 * ]
 */
function parse_labs_json(string $raw): array {
    $raw = trim($raw);
    if ($raw === '') return [];

    $data = json_decode($raw, true);
    if (!is_array($data)) return [];

    // allow wrapper {labs:[...]}
    if (isset($data['labs']) && is_array($data['labs'])) $data = $data['labs'];

    $out = [];
    foreach ($data as $row) {
        if (!is_array($row)) continue;
        $labId = (int)($row['labId'] ?? 0);
        $title = trim((string)($row['courseTitle'] ?? $row['course_title'] ?? ''));
        $slug  = trim((string)($row['courseSlug'] ?? $row['course_slug'] ?? ''));
        $order = (int)($row['courseOrder'] ?? $row['course_order'] ?? 0);
        $lessonIds = $row['lessonIds'] ?? $row['lesson_ids'] ?? [];
        if (!is_array($lessonIds)) $lessonIds = [];

        $lessonIds = array_values(array_map('intval', array_filter($lessonIds, 'is_numeric')));

        if ($labId <= 0 || $title === '' || !$lessonIds) continue;

        $out[] = [
            'lab_id' => $labId,
            'course_title' => $title,
            'course_slug' => $slug,
            'course_order' => $order,
            'lesson_ids' => $lessonIds,
        ];
    }
    return $out;
}

/**
 * AI: extract a short lesson title from page 001 screenshot
 */
function ai_detect_lesson_title(string $imageUrl): string {
    $schema = [
        "type" => "object",
        "additionalProperties" => false,
        "properties" => [
            "title" => ["type" => "string"]
        ],
        "required" => ["title"]
    ];

    $instructions = <<<TXT
Extract the lesson title from this training slide screenshot.
Return ONLY the real instructional lesson title.
Ignore UI chrome like:
- MAIN MENU / HOW TO USE THIS COURSE / SAVE & EXIT
- breadcrumb paths with "/"
- footer controls / page numbers
Return a short title (max ~80 chars).
TXT;

    $payload = [
        "model" => cw_openai_model(),
        "input" => [
            ["role"=>"system","content"=>[["type"=>"input_text","text"=>$instructions]]],
            ["role"=>"user","content"=>[
                ["type"=>"input_text","text"=>"Extract lesson title."],
                ["type"=>"input_image","image_url"=>$imageUrl]
            ]]
        ],
        "text" => [
            "format" => [
                "type" => "json_schema",
                "name" => "lesson_title_v1",
                "schema" => $schema,
                "strict" => true
            ]
        ],
        "temperature" => 0.2
    ];

    $resp = cw_openai_responses($payload);
    $json = cw_openai_extract_json_text($resp);
    $t = trim((string)($json['title'] ?? ''));
    return $t;
}

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // IMPORTANT: render streaming output page
    echo "<!doctype html><html><head><meta charset='utf-8'><title>Importing…</title></head><body style='padding:16px'>";
    progress("Starting import…");

    $programKey = trim($_POST['program_key'] ?? 'private');
    $defaultTpl = trim($_POST['default_template_key'] ?? 'MEDIA_LEFT_TEXT_RIGHT');
    $aiTitles   = isset($_POST['ai_titles']) ? 1 : 0;

    // Bulk mode
    $bulkRaw = (string)($_POST['labs_json'] ?? '');
    $labs = parse_labs_json($bulkRaw);

    // Fallback: single lab mode (existing fields)
    if (!$labs) {
        $labId = (int)($_POST['lab_id'] ?? 0);
        $courseTitle = trim($_POST['course_title'] ?? '');
        $courseSlug = trim($_POST['course_slug'] ?? '');
        $courseOrder = (int)($_POST['course_order'] ?? 0);
        $lessonIds = parse_lesson_ids((string)($_POST['lesson_ids_json'] ?? ''));

        if ($labId <= 0 || $courseTitle === '' || !$lessonIds) {
            progress("ERROR: Provide either Bulk Labs JSON OR Lab ID + Course Title + Lesson IDs JSON.");
            echo "<p><a href='/admin/import_lab.php'>Back</a></p></body></html>";
            exit;
        }

        if ($courseSlug === '') $courseSlug = slugify($courseTitle);

        $labs = [[
            'lab_id' => $labId,
            'course_title' => $courseTitle,
            'course_slug' => $courseSlug,
            'course_order' => $courseOrder,
            'lesson_ids' => $lessonIds,
        ]];
    }

    // program id
    $stmt = $pdo->prepare("SELECT id FROM programs WHERE program_key=? LIMIT 1");
    $stmt->execute([$programKey]);
    $programId = (int)$stmt->fetchColumn();

    if ($programId <= 0) {
        progress("ERROR: program_key not found in DB: {$programKey}");
        echo "<p><a href='/admin/import_lab.php'>Back</a></p></body></html>";
        exit;
    }

    // prepared statements
    $insCourse = $pdo->prepare("
        INSERT INTO courses (program_id, title, slug, revision, sort_order, is_published, external_lab_id)
        VALUES (?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE
          title=VALUES(title),
          sort_order=VALUES(sort_order),
          external_lab_id=VALUES(external_lab_id)
    ");
    $getCourseId = $pdo->prepare("SELECT id FROM courses WHERE program_id=? AND slug=? LIMIT 1");

    $insLesson = $pdo->prepare("
        INSERT INTO lessons (course_id, external_lesson_id, title, sort_order, page_count, default_template_key)
        VALUES (?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE
          title=VALUES(title),
          sort_order=VALUES(sort_order),
          page_count=VALUES(page_count),
          default_template_key=VALUES(default_template_key)
    ");
    $getLessonId = $pdo->prepare("SELECT id FROM lessons WHERE course_id=? AND external_lesson_id=? LIMIT 1");

    $insSlide = $pdo->prepare("
        INSERT INTO slides (lesson_id, page_number, template_key, image_path)
        VALUES (?,?,?,?)
        ON DUPLICATE KEY UPDATE
          template_key=VALUES(template_key),
          image_path=VALUES(image_path)
    ");

    $totalSlides = 0;
    $totalLessons = 0;
    $totalCourses = 0;

    foreach ($labs as $lab) {
        $labId = (int)$lab['lab_id'];
        $courseTitle = (string)$lab['course_title'];
        $courseSlug  = trim((string)$lab['course_slug']);
        $courseOrder = (int)$lab['course_order'];
        $lessonIds   = (array)$lab['lesson_ids'];

        if ($courseSlug === '') $courseSlug = slugify($courseTitle);

        progress("----");
        progress("Lab {$labId}: creating/updating course '{$courseTitle}' …");

        $insCourse->execute([$programId, $courseTitle, $courseSlug, '1.0', $courseOrder, 0, $labId]);
        $getCourseId->execute([$programId, $courseSlug]);
        $courseId = (int)$getCourseId->fetchColumn();
        $totalCourses++;

        progress("Course ready: ID={$courseId}, slug={$courseSlug}");

        $sort = 10;

        foreach ($lessonIds as $extLessonId) {
            $totalLessons++;
            progress("Lesson {$extLessonId}: probing page_count…");
            $pageCount = detect_page_count($CDN_BASE, $programKey, $extLessonId, 300);

            // default title (fallback)
            $title = "Lesson {$extLessonId}";

            // AI title detection on page 001 screenshot
            if ($aiTitles && $pageCount > 0) {
                $imgPath = image_path_for($programKey, $extLessonId, 1);
                $imgUrl = cdn_url($CDN_BASE, $imgPath);

                try {
                    progress("Lesson {$extLessonId}: AI detecting title from page 001…");
                    $aiTitle = ai_detect_lesson_title($imgUrl);
                    if ($aiTitle !== '') {
                        $title = $aiTitle;
                        progress("Lesson {$extLessonId}: AI title = {$title}");
                    } else {
                        progress("Lesson {$extLessonId}: AI title empty, keeping default.");
                    }
                } catch (Throwable $e) {
                    progress("Lesson {$extLessonId}: AI title failed, keeping default. (" . $e->getMessage() . ")");
                }
            }

            $insLesson->execute([$courseId, $extLessonId, $title, $sort, $pageCount, $defaultTpl]);
            $getLessonId->execute([$courseId, $extLessonId]);
            $lessonRowId = (int)$getLessonId->fetchColumn();

            progress("Lesson {$extLessonId}: page_count={$pageCount} (lesson_row_id={$lessonRowId})");

            if ($pageCount > 0) {
                for ($p = 1; $p <= $pageCount; $p++) {
                    $imgPath = image_path_for($programKey, $extLessonId, $p);
                    $insSlide->execute([$lessonRowId, $p, $defaultTpl, $imgPath]);
                    $totalSlides++;
                }
            }

            $sort += 10;
        }

        progress("Lab {$labId} complete. Slides so far: {$totalSlides}");
    }

    progress("----");
    progress("DONE. Courses={$totalCourses}, Lessons={$totalLessons}, Slides={$totalSlides}");
    echo "<p>
      <a href='/admin/courses.php'>Courses</a> |
      <a href='/admin/lessons.php'>Lessons</a> |
      <a href='/admin/slides.php'>Slides</a> |
      <a href='/admin/import_lab.php'>Import another</a>
    </p>";
    echo "</body></html>";
    exit;
}

cw_header('Import Lab');
?>
<div class="card">
  <p class="muted">
    Import Labs. This tool will create Course → Lessons → Slides and auto-detect page_count by probing your CDN.
    Optionally, it can AI-detect lesson titles from the page 001 screenshot.
  </p>

  <form method="post" class="form-grid">
    <label>Program key</label>
    <select name="program_key">
      <option value="private">private</option>
      <option value="instrument">instrument</option>
      <option value="commercial">commercial</option>
    </select>

    <label>Default template</label>
    <select name="default_template_key">
      <?php foreach ($templateKeys as $k): ?>
        <option value="<?= h($k) ?>"><?= h($k) ?></option>
      <?php endforeach; ?>
    </select>

    <label>AI detect lesson titles</label>
    <input type="checkbox" name="ai_titles" value="1">

    <label>Bulk Labs JSON</label>
    <textarea name="labs_json" rows="10" style="width:100%; grid-column: 2 / 3;"
      placeholder='Paste array: [{"labId":10001,"courseTitle":"Getting to Know your Airplane","courseOrder":10,"lessonIds":[10002,10003,...]}, {...}]'></textarea>

    <div class="muted" style="grid-column:1/-1; padding:6px 0;">
      If Bulk Labs JSON is provided, the single-lab fields below are ignored.
    </div>

    <label>Lab ID</label>
    <input name="lab_id" type="number" placeholder="Single lab mode">

    <label>Course title</label>
    <input name="course_title" placeholder="Single lab mode: e.g. Airspace">

    <label>Course slug</label>
    <input name="course_slug" placeholder="auto from title">

    <label>Course order</label>
    <input name="course_order" type="number" value="0">

    <label>Lesson IDs JSON</label>
    <textarea name="lesson_ids_json" rows="8" style="width:100%; grid-column: 2 / 3;"
      placeholder='Single lab mode: {"labId":10013,"lessonIds":[10152,10153,...]} OR just [10152,10153,...]'></textarea>

    <div></div>
    <button class="btn" type="submit">Import</button>
  </form>
</div>
<?php cw_footer(); ?>