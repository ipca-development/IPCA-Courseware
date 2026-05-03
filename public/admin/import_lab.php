<?php
require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
require_once __DIR__ . '/../../src/openai.php';

cw_require_admin();

// --- keep long-running requests alive ---
@set_time_limit(1200);
@ini_set('max_execution_time', '1200');
@ini_set('default_socket_timeout', '30');
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

/**
 * CDN existence probe
 * Use GET + Range (CDNs often mishandle HEAD).
 */
function http_ok(string $url, int $timeoutSeconds = 8, int $retries = 3): bool {
    // Prefer cURL if available
    if (function_exists('curl_init')) {
        for ($attempt = 1; $attempt <= $retries; $attempt++) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
            curl_setopt($ch, CURLOPT_TIMEOUT, $timeoutSeconds);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeoutSeconds);
            curl_setopt($ch, CURLOPT_USERAGENT, 'IPCA-Courseware');
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Range: bytes=0-0']);

            curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);

            if ($code === 200 || $code === 206 || $code === 416) return true;
            if ($code === 404 || $code === 403) return false;

            usleep(250000);
        }
        return false;
    }

    // Fallback: get_headers
    for ($attempt = 1; $attempt <= $retries; $attempt++) {
        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => $timeoutSeconds,
                'ignore_errors' => true,
                'header' =>
                    "User-Agent: IPCA-Courseware\r\n" .
                    "Range: bytes=0-0\r\n"
            ]
        ]);

        $headers = @get_headers($url, 1, $ctx);
        if ($headers) {
            $first = is_array($headers) ? ($headers[0] ?? '') : '';
            if (is_string($first)) {
                if (strpos($first, '200') !== false || strpos($first, '206') !== false || strpos($first, '416') !== false) return true;
                if (strpos($first, '404') !== false || strpos($first, '403') !== false) return false;
            }
        }
        usleep(250000);
    }
    return false;
}

function detect_page_count(string $cdnBase, string $programKey, int $externalLessonId, int $maxCap = 300): int {
    $p1 = image_path_for($programKey, $externalLessonId, 1);
    if (!http_ok(cdn_url($cdnBase, $p1))) {
        return 0;
    }

    // Exponential search
    $lo = 1;
    $hi = 2;

    while ($hi <= $maxCap) {
        $path = image_path_for($programKey, $externalLessonId, $hi);
        if (http_ok(cdn_url($cdnBase, $path))) {
            $lo = $hi;
            $hi *= 2;
        } else {
            break;
        }
    }
    if ($hi > $maxCap) $hi = $maxCap;

    // Binary search
    $left = $lo;
    $right = $hi;
    while ($left < $right) {
        $mid = intdiv($left + $right + 1, 2);
        $path = image_path_for($programKey, $externalLessonId, $mid);
        if (http_ok(cdn_url($cdnBase, $path))) $left = $mid;
        else $right = $mid - 1;
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
 * Parse your manifest as-is:
 * { "labs": [ { "labId":10009, "lessonIds":[...] }, ... ], ... }
 * courseTitle is NOT required (we AI-generate it).
 * Labs with empty lessonIds still become courses (Kings private manifest includes shell labs).
 */
function parse_labs_json(string $raw): array {
    $raw = trim($raw);
    if ($raw === '') return [];

    $data = json_decode($raw, true);
    if (!is_array($data)) return [];

    if (isset($data['labs']) && is_array($data['labs'])) $data = $data['labs'];

    $out = [];
    foreach ($data as $row) {
        if (!is_array($row)) continue;
        $labId = (int)($row['labId'] ?? 0);
        $lessonIds = $row['lessonIds'] ?? $row['lesson_ids'] ?? [];
        if (!is_array($lessonIds)) $lessonIds = [];

        $lessonIds = array_values(array_map('intval', array_filter($lessonIds, 'is_numeric')));

        if ($labId <= 0) continue;

        $out[] = [
            'lab_id' => $labId,
            'lesson_ids' => $lessonIds
        ];
    }
    return $out;
}

function import_lab_json_is_list(array $a): bool
{
    if ($a === []) {
        return false;
    }
    if (function_exists('array_is_list')) {
        return array_is_list($a);
    }
    $i = 0;
    foreach ($a as $k => $_) {
        if ($k !== $i) {
            return false;
        }
        $i++;
    }
    return true;
}

/**
 * Kings IR / export pipeline: top-level JSON array of
 * { "lesson_id"|"lessonId", "title"?, "pages": [ { "page", "screenshot"? }, ... ] }.
 * Produces one synthetic "lab" (course) containing all lessons; use Synthetic lab ID from the form.
 *
 * @param list<array<string, mixed>> $rows
 * @return list<array<string, mixed>>
 */
function parse_kings_lesson_pages_manifest_array(array $rows, int $fallbackLabId): array
{
    $lessonIds = [];
    $pageCounts = [];
    $lessonTitles = [];

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $lid = (int) ($row['lesson_id'] ?? $row['lessonId'] ?? 0);
        if ($lid <= 0) {
            continue;
        }
        $pages = $row['pages'] ?? [];
        if (!is_array($pages)) {
            $pages = [];
        }
        $maxP = 0;
        foreach ($pages as $p) {
            if (!is_array($p)) {
                continue;
            }
            $maxP = max($maxP, (int) ($p['page'] ?? 0));
        }
        if ($maxP === 0 && $pages !== []) {
            $maxP = count($pages);
        }
        $lessonIds[] = $lid;
        $pageCounts[$lid] = $maxP;
        $ttl = trim((string) ($row['title'] ?? ''));
        if ($ttl !== '') {
            $lessonTitles[$lid] = $ttl;
        }
    }

    if ($lessonIds === []) {
        return [];
    }

    $lessonIds = array_values(array_unique($lessonIds));
    sort($lessonIds, SORT_NUMERIC);

    return [[
        'lab_id' => max(1, $fallbackLabId),
        'lesson_ids' => $lessonIds,
        'page_counts' => $pageCounts,
        'lesson_roots' => [],
        'lesson_titles' => $lessonTitles,
        'course_title' => '',
        'course_slug' => '',
    ]];
}

/**
 * Accepts:
 * - Legacy / bulk: { "labs": [ { "labId", "lessonIds" } ] } (same as parse_labs_json).
 * - Pipeline export: { "labs": [...], "lessons": [ { "lessonId", "pages", "status" }, ... ] } — merges per-lesson page counts into each lab.
 * - Lesson-only array: [ { "lesson_id", "title", "pages": [...] }, ... ] — one course using $fallbackLabId.
 */
function parse_labs_json_enhanced(string $raw, int $fallbackLabId): array
{
    $raw = trim($raw);
    if ($raw === '') {
        return [];
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return [];
    }

    if (import_lab_json_is_list($data)) {
        $first = $data[0] ?? null;
        if (is_array($first) && isset($first['pages']) && is_array($first['pages'])) {
            $hasLesson = ((int) ($first['lesson_id'] ?? $first['lessonId'] ?? 0)) > 0;
            if ($hasLesson) {
                return parse_kings_lesson_pages_manifest_array($data, $fallbackLabId);
            }
        }
    }

    if (isset($data['labs']) && is_array($data['labs']) && $data['labs'] !== []) {
        $labsOut = [];
        foreach ($data['labs'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $labId = (int) ($row['labId'] ?? $row['lab_id'] ?? 0);
            $lessonIds = $row['lessonIds'] ?? $row['lesson_ids'] ?? [];
            if (!is_array($lessonIds)) {
                $lessonIds = [];
            }
            $lessonIds = array_values(array_map('intval', array_filter($lessonIds, 'is_numeric')));
            if ($labId <= 0) {
                continue;
            }
            $labsOut[] = [
                'lab_id' => $labId,
                'lesson_ids' => $lessonIds,
            ];
        }
        if ($labsOut === []) {
            return [];
        }

        $byLesson = [];
        if (isset($data['lessons']) && is_array($data['lessons'])) {
            foreach ($data['lessons'] as $lr) {
                if (!is_array($lr)) {
                    continue;
                }
                $lid = (int) ($lr['lessonId'] ?? $lr['lesson_id'] ?? 0);
                if ($lid <= 0) {
                    continue;
                }
                $pc = (int) ($lr['pages'] ?? 0);
                if ($pc > 0) {
                    $byLesson[$lid] = $pc;
                }
            }
        }
        if ($byLesson !== []) {
            foreach ($labsOut as $i => $lab) {
                $m = [];
                foreach ($lab['lesson_ids'] as $lid) {
                    if (isset($byLesson[$lid])) {
                        $m[$lid] = $byLesson[$lid];
                    }
                }
                if ($m !== []) {
                    $labsOut[$i]['page_counts'] = $m;
                }
            }
        }

        return $labsOut;
    }

    return parse_labs_json($raw);
}

/**
 * Resolve server directory for ks_images/{program}/…
 * $localRoot may be the parent of ks_images, or ks_images itself, or the program folder.
 */
function import_lab_resolve_program_folder(string $localRoot, string $programKey): ?string {
    $trim = trim($localRoot);
    if ($trim === '') {
        return null;
    }
    $r = realpath($trim);
    if ($r === false || !is_dir($r)) {
        return null;
    }
    $folderKey = program_folder_for_images($programKey);
    $candidates = [
        $r . DIRECTORY_SEPARATOR . 'ks_images' . DIRECTORY_SEPARATOR . $programKey,
        $r . DIRECTORY_SEPARATOR . 'ks_images' . DIRECTORY_SEPARATOR . $folderKey,
    ];
    $base = strtolower(basename($r));
    if ($base === 'ks_images') {
        $candidates[] = $r . DIRECTORY_SEPARATOR . $programKey;
        $candidates[] = $r . DIRECTORY_SEPARATOR . $folderKey;
    }
    foreach ($candidates as $c) {
        $rp = realpath($c);
        if ($rp !== false && is_dir($rp)) {
            return $rp;
        }
    }
    return null;
}

function import_lab_count_pages_in_lesson_dir(string $lessonDir, int $lessonId): int {
    $max = 0;
    $patterns = [
        $lessonDir . sprintf('/lesson_%d_page_*.*', $lessonId),
    ];
    foreach (glob($patterns[0], GLOB_NOSORT) ?: [] as $f) {
        if (!is_file($f)) {
            continue;
        }
        if (preg_match('#_page_(\d+)\.#i', $f, $m)) {
            $max = max($max, (int)$m[1]);
        }
    }
    return $max;
}

/**
 * @return array<int, array{lesson_id:int, dir:string, pages:int}>
 */
function import_lab_collect_lesson_dirs(string $parentDir): array {
    $out = [];
    foreach (scandir($parentDir) ?: [] as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        if (!preg_match('#^lesson_(\d+)$#', $name, $m)) {
            continue;
        }
        $lessonDir = $parentDir . DIRECTORY_SEPARATOR . $name;
        if (!is_dir($lessonDir)) {
            continue;
        }
        $lid = (int)$m[1];
        $pages = import_lab_count_pages_in_lesson_dir($lessonDir, $lid);
        $out[] = ['lesson_id' => $lid, 'dir' => $lessonDir, 'pages' => $pages];
    }
    usort($out, static fn (array $a, array $b): int => $a['lesson_id'] <=> $b['lesson_id']);
    return $out;
}

/**
 * Human-readable title from a folder name (course bucket).
 */
function import_lab_title_from_dirname(string $name): string {
    $s = str_replace(['_', '-'], ' ', $name);
    $s = preg_replace('/\s+/', ' ', $s);
    return trim((string)$s) !== '' ? trim((string)$s) : $name;
}

/**
 * Build lab rows from disk: either flat lesson_* under program, or one course per subfolder that contains lesson_*.
 *
 * @return list<array{lab_id:int, lesson_ids:list<int>, page_counts:array<int,int>, lesson_roots:array<int,string>, course_title:string, course_slug:string}>
 */
function import_lab_discover_bundles_from_disk(string $programRoot, int $syntheticLabStart): array {
    if ($syntheticLabStart <= 0) {
        $syntheticLabStart = 900000;
    }
    $bundles = [];
    $labSeq = 0;

    $nestedCourses = [];
    foreach (scandir($programRoot) ?: [] as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        $path = $programRoot . DIRECTORY_SEPARATOR . $name;
        if (!is_dir($path)) {
            continue;
        }
        if (preg_match('#^lesson_\d+$#', $name)) {
            continue;
        }
        $lessons = import_lab_collect_lesson_dirs($path);
        if ($lessons === []) {
            continue;
        }
        $lessonIds = array_map(static fn (array $x): int => $x['lesson_id'], $lessons);
        $pageCounts = [];
        $roots = [];
        foreach ($lessons as $L) {
            $pageCounts[$L['lesson_id']] = $L['pages'];
            $roots[$L['lesson_id']] = $L['dir'];
        }
        $title = import_lab_title_from_dirname($name);
        $nestedCourses[] = [
            'lab_id' => $syntheticLabStart + $labSeq,
            'lesson_ids' => $lessonIds,
            'page_counts' => $pageCounts,
            'lesson_roots' => $roots,
            'course_title' => $title,
            'course_slug' => slugify($title),
        ];
        $labSeq++;
    }

    $flatLessons = import_lab_collect_lesson_dirs($programRoot);
    $flatIds = array_map(static fn (array $x): int => $x['lesson_id'], $flatLessons);

    if ($nestedCourses !== []) {
        foreach ($nestedCourses as $nc) {
            $bundles[] = $nc;
        }
        if ($flatLessons !== []) {
            $pageCounts = [];
            $roots = [];
            foreach ($flatLessons as $L) {
                $pageCounts[$L['lesson_id']] = $L['pages'];
                $roots[$L['lesson_id']] = $L['dir'];
            }
            $bundles[] = [
                'lab_id' => $syntheticLabStart + $labSeq,
                'lesson_ids' => $flatIds,
                'page_counts' => $pageCounts,
                'lesson_roots' => $roots,
                'course_title' => '',
                'course_slug' => '',
            ];
            $labSeq++;
        }
        return $bundles;
    }

    if ($flatLessons === []) {
        return [];
    }
    $pageCounts = [];
    $roots = [];
    foreach ($flatLessons as $L) {
        $pageCounts[$L['lesson_id']] = $L['pages'];
        $roots[$L['lesson_id']] = $L['dir'];
    }
    return [[
        'lab_id' => $syntheticLabStart,
        'lesson_ids' => $flatIds,
        'page_counts' => $pageCounts,
        'lesson_roots' => $roots,
        'course_title' => '',
        'course_slug' => '',
    ]];
}

/**
 * Build import bundles from aggregated lesson page counts (folder name → lessonId → max page).
 * lesson_roots left empty (CDN / Spaces URLs used for AI).
 *
 * @param array<string, array<int, int>> $buckets
 * @return list<array{lab_id:int, lesson_ids:list<int>, page_counts:array<int,int>, lesson_roots:array<int,string>, course_title:string, course_slug:string}>
 */
function import_lab_course_buckets_to_bundles(array $buckets, int $syntheticLabStart): array
{
    if ($syntheticLabStart <= 0) {
        $syntheticLabStart = 900000;
    }
    $flat = $buckets[''] ?? [];
    unset($buckets['']);

    $bundles = [];
    $labSeq = 0;

    foreach ($buckets as $folderName => $lessonsMap) {
        if ($lessonsMap === []) {
            continue;
        }
        ksort($lessonsMap, SORT_NUMERIC);
        $lessonIds = array_map('intval', array_keys($lessonsMap));
        $roots = [];
        $title = import_lab_title_from_dirname((string) $folderName);
        $bundles[] = [
            'lab_id' => $syntheticLabStart + $labSeq,
            'lesson_ids' => $lessonIds,
            'page_counts' => $lessonsMap,
            'lesson_roots' => $roots,
            'course_title' => $title,
            'course_slug' => slugify((string) $folderName),
        ];
        $labSeq++;
    }

    if ($bundles !== []) {
        if ($flat !== []) {
            ksort($flat, SORT_NUMERIC);
            $bundles[] = [
                'lab_id' => $syntheticLabStart + $labSeq,
                'lesson_ids' => array_map('intval', array_keys($flat)),
                'page_counts' => $flat,
                'lesson_roots' => [],
                'course_title' => '',
                'course_slug' => '',
            ];
        }
        return $bundles;
    }

    if ($flat !== []) {
        ksort($flat, SORT_NUMERIC);
        return [[
            'lab_id' => $syntheticLabStart,
            'lesson_ids' => array_map('intval', array_keys($flat)),
            'page_counts' => $flat,
            'lesson_roots' => [],
            'course_title' => '',
            'course_slug' => '',
        ]];
    }

    return [];
}

function import_lab_spaces_gather_keys(string $programKey): array
{
    require_once __DIR__ . '/../../src/spaces.php';
    $prefixes = ['ks_images/' . $programKey . '/'];
    $alt = program_folder_for_images($programKey);
    if ($alt !== $programKey) {
        $prefixes[] = 'ks_images/' . $alt . '/';
    }
    $seen = [];
    foreach ($prefixes as $pref) {
        foreach (cw_spaces_list_all_keys_under_prefix($pref) as $k) {
            $seen[$k] = true;
        }
    }

    return array_keys($seen);
}

/**
 * @param list<string> $keys
 */
function import_lab_bundles_from_spaces_keys(array $keys, string $programKey, int $syntheticLabStart): array
{
    $alt = program_folder_for_images($programKey);
    $pkQ = preg_quote($programKey, '#');
    $altQ = preg_quote($alt, '#');
    $progSeg = '(?:' . $pkQ . '|' . $altQ . ')';
    $pat = '#^ks_images/' . $progSeg . '/(?:([^/]+)/)?lesson_(\d+)/lesson_\2_page_(\d+)\.(png|jpe?g|webp)$#i';

    /** @var array<string, array<int, int>> $buckets */
    $buckets = [];
    foreach ($keys as $key) {
        if (!preg_match($pat, $key, $m)) {
            continue;
        }
        $folder = trim((string) ($m[1] ?? ''));
        if (preg_match('#^lesson_\d+$#i', $folder)) {
            continue;
        }
        $lid = (int) $m[2];
        $pg = (int) $m[3];
        if (!isset($buckets[$folder])) {
            $buckets[$folder] = [];
        }
        $buckets[$folder][$lid] = max($buckets[$folder][$lid] ?? 0, $pg);
    }

    return import_lab_course_buckets_to_bundles($buckets, $syntheticLabStart);
}

/**
 * First slide file on disk (page 001) for OpenAI image input.
 */
function import_lab_find_page_file(string $lessonDir, int $lessonId, int $pageNum): ?string {
    $pad = str_pad((string)$pageNum, 3, '0', STR_PAD_LEFT);
    foreach (['png', 'jpg', 'jpeg', 'webp'] as $ext) {
        $f = $lessonDir . sprintf('/lesson_%d_page_%s.%s', $lessonId, $pad, $ext);
        if (is_file($f) && is_readable($f)) {
            return $f;
        }
    }
    return null;
}

/**
 * data: URL for OpenAI (fallback when CDN not populated yet).
 */
function import_lab_local_file_as_data_url(string $absPath): ?string {
    $raw = @file_get_contents($absPath);
    if ($raw === false || $raw === '') {
        return null;
    }
    if (strlen($raw) > 4500000) {
        return null;
    }
    $mime = 'image/png';
    if (preg_match('#\.jpe?g$#i', $absPath)) {
        $mime = 'image/jpeg';
    } elseif (preg_match('#\.webp$#i', $absPath)) {
        $mime = 'image/webp';
    }
    return 'data:' . $mime . ';base64,' . base64_encode($raw);
}

/**
 * AI: extract course title from the seed lesson page 001 screenshot.
 * We want the big centered course title (e.g. "Getting To Know Your Airplane").
 * Ignore phase titles and UI chrome.
 */
function ai_detect_course_title(string $imageUrl): string {
    $schema = [
        "type" => "object",
        "additionalProperties" => false,
        "properties" => [
            "course_title" => ["type" => "string"]
        ],
        "required" => ["course_title"]
    ];

    $instructions = <<<TXT
Extract the COURSE TITLE from this screenshot.

The COURSE TITLE is the big centered title of the module (example: "Getting To Know Your Airplane").
Ignore:
- "Learning Your Airplane" (phase/group title)
- "MAIN MENU / HOW TO USE THIS COURSE / SAVE & EXIT"
- breadcrumb paths with "/"
- footer controls / page numbers
Return only the course title text.
TXT;

    $payload = [
        "model" => cw_openai_model(),
        "input" => [
            ["role"=>"system","content"=>[["type"=>"input_text","text"=>$instructions]]],
            ["role"=>"user","content"=>[
                ["type"=>"input_text","text"=>"Extract the course title."],
                ["type"=>"input_image","image_url"=>$imageUrl]
            ]]
        ],
        "text" => [
            "format" => [
                "type" => "json_schema",
                "name" => "course_title_v1",
                "schema" => $schema,
                "strict" => true
            ]
        ],
        "temperature" => 0.2
    ];

    $resp = cw_openai_responses($payload);
    $json = cw_openai_extract_json_text($resp);
    return trim((string)($json['course_title'] ?? ''));
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
    return trim((string)($json['title'] ?? ''));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    echo "<!doctype html><html><head><meta charset='utf-8'><title>Importing…</title></head><body style='padding:16px'>";
    progress("Starting import…");

    $programKey = trim($_POST['program_key'] ?? 'private');
    $defaultTpl = trim($_POST['default_template_key'] ?? 'MEDIA_LEFT_TEXT_RIGHT');

    $aiCourseTitles = isset($_POST['ai_course_titles']) ? 1 : 0;
    $aiLessonTitles = isset($_POST['ai_titles']) ? 1 : 0;

    $scanFromSpaces = isset($_POST['scan_from_spaces']);
    $scanFromDisk = isset($_POST['scan_from_disk']);
    $localKsRoot = trim((string)($_POST['local_ks_root'] ?? ''));
    if ($localKsRoot === '') {
        $envRoot = getenv('CW_KS_IMAGES_ROOT');
        if (is_string($envRoot) && trim($envRoot) !== '') {
            $localKsRoot = trim($envRoot);
        }
    }
    $syntheticLabBase = (int)($_POST['synthetic_lab_base'] ?? 900000);
    if ($syntheticLabBase <= 0) {
        $syntheticLabBase = 900000;
    }

    $labs = [];
    if ($scanFromSpaces) {
        require_once __DIR__ . '/../../src/spaces.php';
        try {
            progress('Listing objects from DigitalOcean Spaces (same bucket as the player CDN — no server folder path needed)…');
            $keys = import_lab_spaces_gather_keys($programKey);
            progress('Found ' . count($keys) . ' object key(s) matching ks_images/' . $programKey . '/…');
        } catch (Throwable $e) {
            progress('ERROR: Spaces listing failed: ' . $e->getMessage());
            progress('Set CW_SPACES_KEY, CW_SPACES_SECRET, CW_SPACES_BUCKET, CW_SPACES_ENDPOINT, and CW_SPACES_CDN_BASE (same as media uploads).');
            echo "<p><a href='/admin/import_lab.php'>Back</a></p></body></html>";
            exit;
        }
        if ($keys === []) {
            progress('ERROR: No objects under that prefix — check Program key matches the folder name in Spaces (e.g. instrument).');
            echo "<p><a href='/admin/import_lab.php'>Back</a></p></body></html>";
            exit;
        }
        $labs = import_lab_bundles_from_spaces_keys($keys, $programKey, $syntheticLabBase);
        if ($labs === []) {
            progress('ERROR: No lesson_* paths matched the expected pattern (ks_images/{program}/lesson_ID/lesson_ID_page_NNN.png).');
            echo "<p><a href='/admin/import_lab.php'>Back</a></p></body></html>";
            exit;
        }
        progress('Discovered ' . count($labs) . ' course bundle(s) from Spaces keys.');
    } elseif ($scanFromDisk && $localKsRoot !== '') {
        progress('Discovering lessons from local disk (no manifest)…');
        $programFolder = import_lab_resolve_program_folder($localKsRoot, $programKey);
        if ($programFolder === null) {
            progress('ERROR: Could not find program folder for key "' . $programKey . '" under: ' . $localKsRoot);
            progress('Expected something like …/ks_images/' . $programKey . '/lesson_12345/ or …/ks_images/ with that program inside.');
            echo "<p><a href='/admin/import_lab.php'>Back</a></p></body></html>";
            exit;
        }
        progress('Program image root: ' . $programFolder);
        $labs = import_lab_discover_bundles_from_disk($programFolder, $syntheticLabBase);
        if ($labs === []) {
            progress('ERROR: No lesson_* folders with slide files found under ' . $programFolder);
            echo "<p><a href='/admin/import_lab.php'>Back</a></p></body></html>";
            exit;
        }
        progress('Discovered ' . count($labs) . ' course bundle(s) from folder layout.');
    } else {
        $bulkRaw = (string)($_POST['labs_json'] ?? '');
        $labs = parse_labs_json_enhanced($bulkRaw, $syntheticLabBase);
    }

    // Single-lab mode fallback
    if (!$labs) {
        $labId = (int)($_POST['lab_id'] ?? 0);
        $courseTitle = trim($_POST['course_title'] ?? '');
        $courseSlug = trim($_POST['course_slug'] ?? '');
        $courseOrder = (int)($_POST['course_order'] ?? 0);
        $lessonIds = parse_lesson_ids((string)($_POST['lesson_ids_json'] ?? ''));

        if ($labId <= 0 || $courseTitle === '' || !$lessonIds) {
            progress('ERROR: Enable “Scan from Spaces”, “Scan local folders”, paste Bulk Labs JSON, OR use Lab ID + Course Title + Lesson IDs JSON.');
            echo "<p><a href='/admin/import_lab.php'>Back</a></p></body></html>";
            exit;
        }
        if ($courseSlug === '') {
            $courseSlug = slugify($courseTitle);
        }

        $labs = [[
            'lab_id' => $labId,
            'lesson_ids' => $lessonIds,
            'course_title' => $courseTitle,
            'course_slug' => $courseSlug,
            'course_order' => $courseOrder,
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

    $labOrder = 10;

    foreach ($labs as $lab) {
        $labId = (int)$lab['lab_id'];
        $lessonIds = (array)$lab['lesson_ids'];

        $lessonRoots = (array)($lab['lesson_roots'] ?? []);
        $pageCountsLocal = (array)($lab['page_counts'] ?? []);
        $lessonTitlesFromManifest = (array)($lab['lesson_titles'] ?? []);

        // Determine course title
        $courseTitle = trim((string)($lab['course_title'] ?? ''));
        $courseSlug  = trim((string)($lab['course_slug'] ?? ''));
        $courseOrder = (int)($lab['course_order'] ?? $labOrder);

        if ($courseTitle === '' && $aiCourseTitles && $lessonIds !== []) {
            $seedLessonId = (int)$lessonIds[0];
            $seedPath = image_path_for($programKey, $seedLessonId, 1);
            $seedUrl = cdn_url($CDN_BASE, $seedPath);
            $seedRoot = (string)($lessonRoots[$seedLessonId] ?? '');
            if ($seedRoot !== '' && is_dir($seedRoot)) {
                $seedFile = import_lab_find_page_file($seedRoot, $seedLessonId, 1);
                if ($seedFile !== null) {
                    $localSeed = import_lab_local_file_as_data_url($seedFile);
                    if ($localSeed !== null) {
                        $seedUrl = $localSeed;
                    }
                }
            }

            progress("Lab {$labId}: AI detecting COURSE title from lesson {$seedLessonId} page 001…");

            try {
                $t = ai_detect_course_title($seedUrl);
                if ($t !== '') {
                    $courseTitle = $t;
                    progress("Lab {$labId}: Course title = {$courseTitle}");
                } else {
                    $courseTitle = "Lab {$labId}";
                    progress("Lab {$labId}: AI returned empty, using {$courseTitle}");
                }
            } catch (Throwable $e) {
                $courseTitle = "Lab {$labId}";
                progress("Lab {$labId}: AI course title failed, using {$courseTitle} (" . $e->getMessage() . ")");
            }
        }

        if ($courseTitle === '') {
            $courseTitle = "Lab {$labId}";
        }
        if ($courseSlug === '') {
            // Slug is unique per (program_id, slug). Same synthetic lab id for different programs
            // must not both become "lab-900000" — include program_key in the slug.
            $courseSlug = slugify($courseTitle . ' ' . $programKey);
        }

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
            $extLessonId = (int)$extLessonId;
            $pageCount = (int)($pageCountsLocal[$extLessonId] ?? 0);
            if ($pageCount <= 0) {
                progress("Lesson {$extLessonId}: probing page_count via CDN…");
                $pageCount = detect_page_count($CDN_BASE, $programKey, $extLessonId, 300);
            } else {
                progress("Lesson {$extLessonId}: page_count={$pageCount} (from folder or Spaces listing)");
            }

            $title = "Lesson {$extLessonId}";
            if (isset($lessonTitlesFromManifest[$extLessonId])) {
                $mt = trim((string) $lessonTitlesFromManifest[$extLessonId]);
                if ($mt !== '') {
                    $title = $mt;
                }
            }

            if ($aiLessonTitles && $pageCount > 0) {
                $imgPath = image_path_for($programKey, $extLessonId, 1);
                $imgUrl = cdn_url($CDN_BASE, $imgPath);
                $lr = (string)($lessonRoots[$extLessonId] ?? '');
                if ($lr !== '' && is_dir($lr)) {
                    $p1 = import_lab_find_page_file($lr, $extLessonId, 1);
                    if ($p1 !== null) {
                        $dataImg = import_lab_local_file_as_data_url($p1);
                        if ($dataImg !== null) {
                            $imgUrl = $dataImg;
                        }
                    }
                }

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

        if ($lessonIds === []) {
            progress("Lab {$labId} complete (course shell — no lessons in manifest). Slides so far: {$totalSlides}");
        } else {
            progress("Lab {$labId} complete. Slides so far: {$totalSlides}");
        }
        $labOrder += 10;
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
$playerImgExample = cdn_url($CDN_BASE, 'ks_images/instrument/lesson_10101/lesson_10101_page_001.png');
?>
<div class="card">
  <p class="muted">
    Bulk import: create Course → Lessons → Slides. Paste a <strong>manifest JSON</strong> in Kings form: <code>{"labs":[{"labId":10001,"lessonIds":[10002,…]},…]}</code> — each lab is one course (<code>external_lab_id</code> = Kings lab id); labs with empty <code>lessonIds</code> still create a course shell. Optional <code>lessons</code> array supplies page counts; or use <strong>scan Spaces</strong> / <strong>scan local folder</strong>. Slide paths look like
    <code>ks_images/{program}/lesson_{id}/lesson_{id}_page_001.png</code> (see <code>public/player/slide.php</code>: <code>cdn_url($CDN_BASE, $slide['image_path'])</code>).
  </p>
  <p class="muted" style="font-size:13px;">
    Example image URL for program <code>instrument</code> (after you pick program in the form):<br>
    <a href="<?= h($playerImgExample) ?>" target="_blank" rel="noopener"><?= h($playerImgExample) ?></a><br>
    CDN base: <code><?= h($CDN_BASE) ?></code>
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

    <label>AI detect COURSE titles (per lab)</label>
    <input type="checkbox" name="ai_course_titles" value="1" checked>

    <label>AI detect LESSON titles (per lesson)</label>
    <input type="checkbox" name="ai_titles" value="1" checked>

    <label>Scan from Spaces (no manifest, no local path)</label>
    <div>
      <label><input type="checkbox" name="scan_from_spaces" value="1"> List objects in your Spaces bucket under <code>ks_images/{program_key}/</code> and import (uses <code>CW_SPACES_*</code> credentials)</label>
      <p class="muted" style="margin:8px 0 0 0; font-size:13px;">
        Use this when images live only on DigitalOcean Spaces. The importer calls the S3 ListObjects API (same bucket as uploads). Course/lesson layout rules match local scan: optional subfolders under the program become separate courses.
      </p>
    </div>

    <label>Scan local folders (no manifest)</label>
    <div>
      <label><input type="checkbox" name="scan_from_disk" value="1"> Discover <code>lesson_*</code> under the program folder and import (ignores Bulk Labs JSON)</label>
      <p class="muted" style="margin:8px 0 0 0; font-size:13px;">
        <strong>Flat layout:</strong> <code>…/ks_images/instrument/lesson_10101/…</code> → one course; subfolder name is ignored.<br>
        <strong>Course folders:</strong> <code>…/ks_images/instrument/My_Course_Name/lesson_10101/…</code> → one course per subfolder; course title defaults from folder name (spaces for underscores). Top-level <code>lesson_*</code> alongside those folders becomes an extra course “import” bucket.
      </p>
    </div>

    <label>Local path to media root</label>
    <div>
      <input name="local_ks_root" type="text" style="width:100%; max-width:42rem;"
        placeholder="Only when scanning this server’s disk — e.g. /var/www/media"
        value="<?= h(trim((string)(getenv('CW_KS_IMAGES_ROOT') ?: ''))) ?>">
      <p class="muted" style="margin:6px 0 0 0; font-size:13px;">
        <strong>Not used for Spaces.</strong> Only when “Scan local folders” is checked. Pre-filled from <code>CW_KS_IMAGES_ROOT</code> if set. Looks for
        <code>{path}/ks_images/{program_key}/</code> or <code>{path}/{program_key}/</code> when <code>path</code> ends with <code>ks_images</code>.
      </p>
    </div>

    <label>Synthetic lab IDs (folder / Spaces scan)</label>
    <div>
      <input name="synthetic_lab_base" type="number" value="900000" min="1" style="width:8rem;">
      <p class="muted" style="margin:6px 0 0 0; font-size:13px;">
        Stored as <code>courses.external_lab_id</code>. Use a range that does not collide with real Kings lab IDs (e.g. 900000+).
      </p>
    </div>

    <label>Bulk Labs JSON (your manifest)</label>
    <textarea name="labs_json" rows="12" style="width:100%; grid-column: 2 / 3;"
      placeholder='Supported: (1) {"labs":[{"labId":20001,"lessonIds":[20002,...]}]} — optionally with "lessons":[{"lessonId":20002,"pages":7},...] from kings_ir_export_pipeline; (2) a JSON array of {lesson_id, title, pages:[{page,screenshot}]} lesson exports (one course — set Synthetic lab ID).'></textarea>

    <div class="muted" style="grid-column:1/-1; padding:6px 0;">
      If “Scan from Spaces” or “Scan local folders” is checked, Bulk Labs JSON is ignored. Otherwise, if JSON is provided, the single-lab fields below are ignored.
    </div>

    <label>Lab ID</label>
    <input name="lab_id" type="number" placeholder="Single lab mode">

    <label>Course title</label>
    <input name="course_title" placeholder="Single lab mode">

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