<?php
require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/openai.php';

echo "START<br>";
flush();

cw_require_admin();

session_write_close();

ignore_user_abort(true);

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
header('X-Accel-Buffering: no');

// streaming output
@set_time_limit(1800);
@ini_set('max_execution_time', '1800');
// Vision + structured output can exceed 60s per slide; proxies still may cut the connection earlier.
@ini_set('default_socket_timeout', '600');
@ini_set('output_buffering', 'off');
@ini_set('zlib.output_compression', '0');

function progress(string $msg): void {
    echo "<div style='font-family:system-ui;font-size:14px;padding:4px 0;'>" . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . "</div>\n";
    echo str_repeat(' ', 4096) . "\n";
    if (function_exists('ob_flush')) {
        @ob_flush();
    }
    @flush();
}

// --- helpers
function sc_upsert(PDO $pdo, int $slideId, string $lang, array $contentJson, string $plainText): void {
    $stmt = $pdo->prepare("
      INSERT INTO slide_content (slide_id, lang, content_json, plain_text)
      VALUES (?,?,?,?)
      ON DUPLICATE KEY UPDATE content_json=VALUES(content_json), plain_text=VALUES(plain_text)
    ");
    $stmt->execute([
        $slideId,
        $lang,
        json_encode($contentJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        $plainText
    ]);
}

function set_narration(PDO $pdo, int $slideId, string $narrationEn, string $narrationEs): void {
    $stmt = $pdo->prepare("
      INSERT INTO slide_enrichment (slide_id, narration_en, narration_es)
      VALUES (?,?,?)
      ON DUPLICATE KEY UPDATE
        narration_en=VALUES(narration_en),
        narration_es=VALUES(narration_es)
    ");
    $stmt->execute([$slideId, $narrationEn, $narrationEs]);
}

function replace_refs(PDO $pdo, int $slideId, array $phak, array $acs): void {
    $pdo->prepare("DELETE FROM slide_references WHERE slide_id=? AND ref_type IN ('PHAK','ACS')")->execute([$slideId]);

    $ins = $pdo->prepare("
      INSERT INTO slide_references (slide_id, ref_type, ref_code, ref_title, confidence, notes)
      VALUES (?,?,?,?,?,?)
    ");

    foreach ($phak as $r) {
        $code = trim((string)($r['chapter'] ?? '')) . (trim((string)($r['section'] ?? '')) !== '' ? (' ' . trim((string)$r['section'])) : '');
        $code = trim($code);
        if ($code === '') {
            $code = 'PHAK';
        }

        $ins->execute([
            $slideId,
            'PHAK',
            $code,
            (string)($r['title'] ?? ''),
            (float)($r['confidence'] ?? 0.6),
            (string)($r['notes'] ?? '')
        ]);
    }

    foreach ($acs as $r) {
        $code = trim((string)($r['code'] ?? ''));
        if ($code === '') {
            continue;
        }

        $ins->execute([
            $slideId,
            'ACS',
            $code,
            (string)($r['task'] ?? ''),
            (float)($r['confidence'] ?? 0.6),
            (string)($r['notes'] ?? '')
        ]);
    }
}

function read_manifest_match(int $extLessonId, int $pageNum, string $programKey): string {
    $manifestPath = __DIR__ . '/../../assets/kings_videos_manifest.json';
    if (!file_exists($manifestPath)) {
        return '';
    }

    $raw = file_get_contents($manifestPath);
    $arr = json_decode($raw, true);
    if (!is_array($arr)) {
        return '';
    }

    foreach ($arr as $item) {
        $lid = (int)($item['lessonId'] ?? 0);
        $pg  = (int)($item['page'] ?? 0);

        if ($lid === $extLessonId && $pg === $pageNum) {
            $urls = $item['videoUrls'] ?? [];
            if (!is_array($urls) || count($urls) === 0) {
                return '';
            }

            $u = (string)$urls[0];
            $base = basename(parse_url($u, PHP_URL_PATH) ?: $u);

            $videosBase = getenv('CW_VIDEOS_BASE') ?: ('ks_videos/' . $programKey);
            $pagePrefix = 'page_' . str_pad((string)$pageNum, 3, '0', STR_PAD_LEFT) . '__';

            return rtrim($videosBase, '/') . '/lesson_' . $extLessonId . '/' . $pagePrefix . $base;
        }
    }

    return '';
}

function ensure_hotspot(PDO $pdo, int $slideId, string $src): void {
    if ($src === '') {
        return;
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM slide_hotspots WHERE slide_id=? AND is_deleted=0");
    $stmt->execute([$slideId]);
    $cnt = (int)$stmt->fetchColumn();
    if ($cnt > 0) {
        return;
    }

    // default box inside content region
    $x = 900;
    $y = 280;
    $w = 520;
    $h = 320;

    $ins = $pdo->prepare("
      INSERT INTO slide_hotspots (slide_id, kind, label, src, x, y, w, h, is_deleted)
      VALUES (?,?,?,?,?,?,?,?,0)
    ");
    $ins->execute([$slideId, 'video', 'Video', $src, $x, $y, $w, $h]);
}

/**
 * Translate a text block to Spanish via strict json_schema.
 */
function ai_translate_es(string $text): string {
    $text = trim($text);
    if ($text === '') {
        return '';
    }

    $schemaT = [
        'type' => 'object',
        'additionalProperties' => false,
        'properties' => [
            'spanish_text' => ['type' => 'string']
        ],
        'required' => ['spanish_text']
    ];

    $payloadT = [
        'model' => cw_openai_model(),
        'input' => [
            [
                'role' => 'system',
                'content' => [
                    ['type' => 'input_text', 'text' => 'Translate to Spanish. Preserve line breaks. Keep aviation terms clear and natural.']
                ]
            ],
            [
                'role' => 'user',
                'content' => [
                    ['type' => 'input_text', 'text' => $text]
                ]
            ]
        ],
        'text' => [
            'format' => [
                'type' => 'json_schema',
                'name' => 'translate_es_v1',
                'schema' => $schemaT,
                'strict' => true
            ]
        ],
        'temperature' => 0.2
    ];

    $respT = cw_openai_responses($payloadT);
    $jsonT = cw_openai_extract_json_text($respT);

    return trim((string)($jsonT['spanish_text'] ?? ''));
}

// --- Input
$scope = (string)($_POST['scope'] ?? 'course');
$courseId = (int)($_POST['course_id'] ?? 0);
$lessonId = (int)($_POST['lesson_id'] ?? 0);
$programKey = trim((string)($_POST['program_key'] ?? 'private'));

$doEN = isset($_POST['do_en']);
$doES = isset($_POST['do_es']);
$doNarr = isset($_POST['do_narration']);
$doRefs = isset($_POST['do_refs']);
$doHotspots = isset($_POST['do_hotspots']);
$skipExisting = isset($_POST['skip_existing']);
$limit = (int)($_POST['limit'] ?? 0);
$batchSize = max(0, (int)($_POST['batch_size'] ?? 0));
$batchOffset = max(0, (int)($_POST['batch_offset'] ?? 0));

$targetSlideIds = [];
$rawTargets = $_POST['target_slide_ids'] ?? null;
if (is_array($rawTargets)) {
    $targetSlideIds = array_values(array_unique(array_map('intval', $rawTargets)));
} elseif (is_string($rawTargets) && trim($rawTargets) !== '') {
    $targetSlideIds = array_values(array_unique(array_map('intval', preg_split('/\s*,\s*/', $rawTargets))));
}
$targetSlideIds = array_values(array_filter($targetSlideIds, function ($x) {
    return (int)$x > 0;
}));

if ($targetSlideIds !== []) {
    // Targeted re-run: only selected slides; do not skip slides that already have EN.
    $skipExisting = false;
}

echo "<!doctype html><html><head><meta charset='utf-8'><title>Bulk Canonical Builder</title></head><body style='padding:16px'>";
echo str_repeat(' ', 4096);
@flush();

progress("Bulk run started…");

if ($courseId <= 0) {
    progress("ERROR: course_id required.");
    echo "</body></html>";
    exit;
}

if ($scope === 'lesson' && $lessonId <= 0) {
    progress("ERROR: lesson_id required for scope=lesson.");
    echo "</body></html>";
    exit;
}

// --- Fetch slides
$sql = "
  SELECT s.id AS slide_id, s.page_number, s.image_path,
         l.external_lesson_id, l.id AS lesson_row_id
  FROM slides s
  JOIN lessons l ON l.id = s.lesson_id
  WHERE COALESCE(s.is_deleted, 0) = 0
";
$params = [];

if ($scope === 'course') {
    $sql .= " AND l.course_id=? ";
    $params[] = $courseId;
} else {
    $sql .= " AND l.id=? ";
    $params[] = $lessonId;
}

$sql .= " ORDER BY l.sort_order, l.external_lesson_id, l.id, s.page_number ";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$slides = $stmt->fetchAll(PDO::FETCH_ASSOC);

if ($targetSlideIds !== []) {
    $allowed = array_flip($targetSlideIds);
    $slides = array_values(array_filter($slides, function ($row) use ($allowed) {
        return isset($allowed[(int)$row['slide_id']]);
    }));
    progress('Targeted slide ids: ' . count($targetSlideIds) . ' — matched in scope: ' . count($slides));
    if ($slides === []) {
        progress('ERROR: No slides matched (wrong course/lesson or invalid ids).');
        echo "</body></html>";
        exit;
    }
}

$totalInScope = count($slides);
// Server-side batching (main form): preserves skip_existing; ignored when targeting specific slide IDs.
if ($targetSlideIds === [] && $batchSize > 0) {
    $slides = array_slice($slides, $batchOffset, $batchSize);
}

if ($slides === []) {
    progress('ERROR: No slides in this batch or scope.');
    echo "</body></html>";
    exit;
}

if ($targetSlideIds === [] && $batchSize > 0) {
    progress('Batch offset ' . $batchOffset . ', size ' . $batchSize . ' — running ' . count($slides) . ' slide(s) (full scope: ' . $totalInScope . ').');
} else {
    progress('Slides in scope: ' . count($slides));
}

$processed = 0;

foreach ($slides as $row) {
    $slideId = (int)$row['slide_id'];
    $extLessonId = (int)$row['external_lesson_id'];
    $pageNum = (int)$row['page_number'];
    $imgUrl = cdn_url($CDN_BASE, (string)$row['image_path']);

    if ($limit > 0 && $processed >= $limit) {
        progress("Limit reached ({$limit}). Stopping.");
        break;
    }

    if ($skipExisting && $doEN) {
        $chk = $pdo->prepare("SELECT 1 FROM slide_content WHERE slide_id=? AND lang='en' LIMIT 1");
        $chk->execute([$slideId]);
        if ($chk->fetchColumn()) {
            progress("SKIP slide {$slideId} (lesson {$extLessonId} page {$pageNum}) — already has EN.");
            continue;
        }
    }

    progress("Slide {$slideId} (lesson {$extLessonId} page {$pageNum})…");

    // Hotspot auto-create if video exists
    if ($doHotspots) {
        $src = read_manifest_match($extLessonId, $pageNum, $programKey);
        if ($src !== '') {
            ensure_hotspot($pdo, $slideId, $src);
            progress("  + hotspot video: {$src}");
        } else {
            progress("  - no video manifest match for lesson {$extLessonId} page {$pageNum}");
        }
    }

    // AI extract canonical (EN + narration EN + refs)
    $englishText = '';
    $narrationEn = '';
    $narrationEs = '';
    $phak = [];
    $acs = [];

    if ($doEN || $doNarr || $doRefs) {
        $schema = [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'english_text' => ['type' => 'string'],
                'narration_script_en' => ['type' => 'string'],
                'phak' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'chapter' => ['type' => 'string'],
                            'section' => ['type' => 'string'],
                            'title' => ['type' => 'string'],
                            'confidence' => ['type' => 'number'],
                            'notes' => ['type' => 'string']
                        ],
                        'required' => ['chapter', 'section', 'title', 'confidence', 'notes']
                    ]
                ],
                'acs' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'code' => ['type' => 'string'],
                            'task' => ['type' => 'string'],
                            'confidence' => ['type' => 'number'],
                            'notes' => ['type' => 'string']
                        ],
                        'required' => ['code', 'task', 'confidence', 'notes']
                    ]
                ]
            ],
            'required' => ['english_text', 'narration_script_en', 'phak', 'acs']
        ];

        $instructions = <<<TXT
You are extracting canonical training content from a flight training slide screenshot.

RULES:
- Extract ONLY instructional content. Ignore UI chrome (menus, breadcrumbs, footers, buttons, page controls).
- english_text should be clean, readable, preserving bullets as "• " and line breaks.
- narration_script_en should be slightly more explanatory than english_text, suitable for instructor/voiceover reading aloud.
- Provide references:
  * PHAK references: Chapter and Section titles where this content is taught (best effort).
  * ACS references: ACS codes that map to this content (best effort).
- Always include notes fields (use empty string if none).
Return JSON that matches the schema.
TXT;

        $payload = [
            'model' => cw_openai_model(),
            'input' => [
                [
                    'role' => 'system',
                    'content' => [
                        ['type' => 'input_text', 'text' => $instructions]
                    ]
                ],
                [
                    'role' => 'user',
                    'content' => [
                        ['type' => 'input_text', 'text' => 'Extract canonical content + narration + PHAK + ACS.'],
                        ['type' => 'input_image', 'image_url' => $imgUrl]
                    ]
                ]
            ],
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => 'bulk_canonical_v1',
                    'schema' => $schema,
                    'strict' => true
                ]
            ],
            'temperature' => 0.2
        ];

        progress('  → calling model (may take 1–3 min for vision)…');

        try {
            $resp = cw_openai_responses($payload);
            $json = cw_openai_extract_json_text($resp);

            $englishText = trim((string)($json['english_text'] ?? ''));
            $narrationEn = trim((string)($json['narration_script_en'] ?? ''));
            $phak = is_array($json['phak'] ?? null) ? $json['phak'] : [];
            $acs = is_array($json['acs'] ?? null) ? $json['acs'] : [];
        } catch (Throwable $e) {
            progress("  ERROR AI extract: " . $e->getMessage());
            continue;
        }

        if ($doEN) {
            sc_upsert($pdo, $slideId, 'en', ['blocks' => [['type' => 'raw', 'text' => $englishText]]], $englishText);
            progress("  + saved EN text");
        }

        // Narration EN + Narration ES (translated from narration EN)
        if ($doNarr && $narrationEn !== '') {
            if ($doES) {
                try {
                    $narrationEs = ai_translate_es($narrationEn);
                } catch (Throwable $e) {
                    $narrationEs = '';
                }
            }

            set_narration($pdo, $slideId, $narrationEn, $narrationEs);
            progress("  + saved narration (EN" . ($narrationEs !== '' ? "+ES" : "") . ")");
        }

        if ($doRefs) {
            replace_refs($pdo, $slideId, $phak, $acs);
            progress("  + saved refs (PHAK/ACS)");
        }
    }

    // Spanish translation (from EN slide text)
    if ($doES) {
        if ($englishText === '') {
            $stmt = $pdo->prepare("SELECT plain_text FROM slide_content WHERE slide_id=? AND lang='en' LIMIT 1");
            $stmt->execute([$slideId]);
            $englishText = trim((string)$stmt->fetchColumn());
        }

        if ($englishText !== '') {
            try {
                $spanishText = ai_translate_es($englishText);
                sc_upsert($pdo, $slideId, 'es', ['blocks' => [['type' => 'raw', 'text' => $spanishText]]], $spanishText);
                progress("  + saved ES translation");
            } catch (Throwable $e) {
                progress("  ERROR AI translate: " . $e->getMessage());
            }
        } else {
            progress("  skip ES (no EN text)");
        }
    }

    $processed++;
}

progress("DONE. Slides processed: {$processed}");
echo "<p><a href='/admin/bulk_enrich.php'>Back</a> | <a href='/admin/slides.php?course_id={$courseId}'>Slides</a></p>";
echo "</body></html>";