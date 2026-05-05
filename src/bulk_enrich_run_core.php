<?php
declare(strict_types=1);

require_once __DIR__ . '/resource_library_enrich_context.php';

/**
 * Shared bulk canonical enrichment (slides) — used by HTML and SSE entrypoints.
 *
 * @param callable(string $event, array $data):void $emit SSE-style: event name + JSON-serializable data
 */
function bulk_enrich_core_sc_upsert(PDO $pdo, int $slideId, string $lang, array $contentJson, string $plainText): void
{
    $stmt = $pdo->prepare("
      INSERT INTO slide_content (slide_id, lang, content_json, plain_text)
      VALUES (?,?,?,?)
      ON DUPLICATE KEY UPDATE content_json=VALUES(content_json), plain_text=VALUES(plain_text)
    ");
    $stmt->execute([
        $slideId,
        $lang,
        json_encode($contentJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        $plainText,
    ]);
}

function bulk_enrich_core_set_narration(PDO $pdo, int $slideId, string $narrationEn, string $narrationEs): void
{
    $stmt = $pdo->prepare("
      INSERT INTO slide_enrichment (slide_id, narration_en, narration_es)
      VALUES (?,?,?)
      ON DUPLICATE KEY UPDATE
        narration_en=VALUES(narration_en),
        narration_es=VALUES(narration_es)
    ");
    $stmt->execute([$slideId, $narrationEn, $narrationEs]);
}

function bulk_enrich_core_replace_refs(PDO $pdo, int $slideId, array $phak, array $acs): void
{
    $pdo->prepare("DELETE FROM slide_references WHERE slide_id=? AND ref_type IN ('PHAK','ACS')")->execute([$slideId]);

    $ins = $pdo->prepare("
      INSERT INTO slide_references (slide_id, ref_type, ref_code, ref_title, confidence, notes)
      VALUES (?,?,?,?,?,?)
    ");
    $refCodeMax = bulk_enrich_core_col_max_len($pdo, 'slide_references', 'ref_code', 128);
    $refTitleMax = bulk_enrich_core_col_max_len($pdo, 'slide_references', 'ref_title', 255);
    $refNotesMax = bulk_enrich_core_col_max_len($pdo, 'slide_references', 'notes', 2000);

    foreach ($phak as $r) {
        $chapter = trim((string)($r['chapter'] ?? ''));
        $section = trim((string)($r['section'] ?? ''));
        $code = $chapter . ($section !== '' ? (' ' . $section) : '');
        $code = trim($code);
        if ($code === '') {
            $code = 'PHAK';
        }
        $code = bulk_enrich_core_clip($code, $refCodeMax);

        $ins->execute([
            $slideId,
            'PHAK',
            $code,
            bulk_enrich_core_clip((string)($r['title'] ?? ''), $refTitleMax),
            bulk_enrich_core_clamp_conf((float)($r['confidence'] ?? 0.6)),
            bulk_enrich_core_clip((string)($r['notes'] ?? ''), $refNotesMax),
        ]);
    }

    foreach ($acs as $r) {
        $code = trim((string)($r['code'] ?? ''));
        if ($code === '') {
            continue;
        }
        $code = bulk_enrich_core_clip($code, $refCodeMax);

        $ins->execute([
            $slideId,
            'ACS',
            $code,
            bulk_enrich_core_clip((string)($r['task'] ?? ''), $refTitleMax),
            bulk_enrich_core_clamp_conf((float)($r['confidence'] ?? 0.6)),
            bulk_enrich_core_clip((string)($r['notes'] ?? ''), $refNotesMax),
        ]);
    }
}

function bulk_enrich_core_col_max_len(PDO $pdo, string $table, string $column, int $fallback): int
{
    static $cache = [];
    $k = $table . '.' . $column;
    if (isset($cache[$k])) {
        return $cache[$k];
    }
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM {$table} LIKE ?");
        $stmt->execute([$column]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $type = strtolower((string)($row['Type'] ?? ''));
        if (preg_match('/\((\d+)\)/', $type, $m)) {
            $n = (int)$m[1];
            if ($n > 0) {
                $cache[$k] = $n;
                return $n;
            }
        }
    } catch (Throwable $e) {
        // Use fallback
    }
    $cache[$k] = $fallback;
    return $fallback;
}

function bulk_enrich_core_clip(string $value, int $maxLen): string
{
    $value = trim($value);
    if ($maxLen <= 0 || strlen($value) <= $maxLen) {
        return $value;
    }

    return substr($value, 0, $maxLen);
}

function bulk_enrich_core_clamp_conf(float $v): float
{
    if (!is_finite($v)) {
        return 0.6;
    }
    if ($v < 0.0) {
        return 0.0;
    }
    if ($v > 1.0) {
        return 1.0;
    }

    return $v;
}

function bulk_enrich_core_read_manifest_match(string $manifestPath, int $extLessonId, int $pageNum, string $programKey): string
{
    if ($manifestPath === '') {
        return '';
    }

    return bec_read_manifest_video_src($manifestPath, $extLessonId, $pageNum, $programKey);
}

function bulk_enrich_core_ensure_hotspot(PDO $pdo, int $slideId, string $src): void
{
    if ($src === '') {
        return;
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM slide_hotspots WHERE slide_id=? AND is_deleted=0");
    $stmt->execute([$slideId]);
    $cnt = (int)$stmt->fetchColumn();
    if ($cnt > 0) {
        return;
    }

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

function bulk_enrich_core_ai_translate_es(string $text): string
{
    $text = trim($text);
    if ($text === '') {
        return '';
    }

    $schemaT = [
        'type' => 'object',
        'additionalProperties' => false,
        'properties' => [
            'spanish_text' => ['type' => 'string'],
        ],
        'required' => ['spanish_text'],
    ];

    $payloadT = [
        'model' => cw_openai_model(),
        'input' => [
            [
                'role' => 'system',
                'content' => [
                    ['type' => 'input_text', 'text' => 'Translate to Spanish. Preserve line breaks. Keep aviation terms clear and natural.'],
                ],
            ],
            [
                'role' => 'user',
                'content' => [
                    ['type' => 'input_text', 'text' => $text],
                ],
            ],
        ],
        'text' => [
            'format' => [
                'type' => 'json_schema',
                'name' => 'translate_es_v1',
                'schema' => $schemaT,
                'strict' => true,
            ],
        ],
        'temperature' => 0.2,
    ];

    $respT = cw_openai_responses($payloadT);
    $jsonT = cw_openai_extract_json_text($respT);

    return trim((string)($jsonT['spanish_text'] ?? ''));
}

/**
 * Full vision extraction (EN text + narration EN + PHAK + ACS) in one model call.
 *
 * @return array{english_text: string, narration_script_en: string, phak: list<array<string,mixed>>, acs: list<array<string,mixed>>}
 */
function bulk_enrich_core_vision_bundle_slide(PDO $pdo, string $cdnBase, int $slideId, int $resourceLibraryEditionId = 0): array
{
    $stmt = $pdo->prepare('SELECT image_path FROM slides WHERE id=? LIMIT 1');
    $stmt->execute([$slideId]);
    $imgPath = trim((string)$stmt->fetchColumn());
    if ($imgPath === '') {
        throw new RuntimeException('Slide image_path missing');
    }

    $imgUrl = cdn_url($cdnBase, $imgPath);

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
                        'notes' => ['type' => 'string'],
                    ],
                    'required' => ['chapter', 'section', 'title', 'confidence', 'notes'],
                ],
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
                        'notes' => ['type' => 'string'],
                    ],
                    'required' => ['code', 'task', 'confidence', 'notes'],
                ],
            ],
        ],
        'required' => ['english_text', 'narration_script_en', 'phak', 'acs'],
    ];

    $instructions = <<<'TXT'
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

    $rlPack = '';
    if ($resourceLibraryEditionId > 0) {
        $hint = rl_enrich_search_hint_for_slide($pdo, $slideId);
        $rlPack = rl_enrich_context_pack_for_slide($pdo, $resourceLibraryEditionId, $hint, 12000, 14);
        if ($rlPack !== '') {
            $instructions .= "\n\nINDEXED HANDBOOK CONTEXT (when provided below the slide request):\n"
                . "- These excerpts come from the Resource Library database (retrieval). Prefer them to ground PHAK reference rows when they clearly apply to the slide topic.\n"
                . "- Still extract visible instructional content from the IMAGE as the primary source.\n"
                . "- Do not assert facts that are neither visible on the slide nor supported by the excerpts.\n";
        }
    }

    $userLead = 'Extract canonical content + narration + PHAK + ACS from the slide.';
    if ($rlPack !== '') {
        $userLead .= "\n\nRetrieval context (indexed handbook excerpts):\n\n" . $rlPack;
    }

    $payload = [
        'model' => cw_openai_model(),
        'input' => [
            [
                'role' => 'system',
                'content' => [
                    ['type' => 'input_text', 'text' => $instructions],
                ],
            ],
            [
                'role' => 'user',
                'content' => [
                    ['type' => 'input_text', 'text' => $userLead],
                    ['type' => 'input_image', 'image_url' => $imgUrl],
                ],
            ],
        ],
        'text' => [
            'format' => [
                'type' => 'json_schema',
                'name' => 'bulk_canonical_v1',
                'schema' => $schema,
                'strict' => true,
            ],
        ],
        'temperature' => 0.2,
    ];

    $resp = cw_openai_responses($payload);
    $json = cw_openai_extract_json_text($resp);

    return [
        'english_text' => trim((string)($json['english_text'] ?? '')),
        'narration_script_en' => trim((string)($json['narration_script_en'] ?? '')),
        'phak' => is_array($json['phak'] ?? null) ? $json['phak'] : [],
        'acs' => is_array($json['acs'] ?? null) ? $json['acs'] : [],
    ];
}

/**
 * Parse POST-like array into flags (supports legacy do_narration + do_es coupling).
 *
 * @param array<string,mixed> $p
 * @return array<string,mixed>
 */
function bulk_enrich_core_parse_flags(array $p): array
{
    $doEN = isset($p['do_en']);
    $doEsSlide = isset($p['do_es']);
    $doNarrEn = isset($p['do_narration_en']) || isset($p['do_narration']);
    $doNarrEs = isset($p['do_narration_es']);
    // Legacy: old clients sent do_narration + do_es to mean “also translate narration to ES”
    if (!array_key_exists('do_narration_es', $p) && isset($p['do_narration']) && isset($p['do_es'])) {
        $doNarrEs = true;
    }
    $doRefs = isset($p['do_refs']);
    $doHotspots = isset($p['do_hotspots']);

    return [
        'do_en' => $doEN,
        'do_es_slide' => $doEsSlide,
        'do_narration_en' => $doNarrEn,
        'do_narration_es' => $doNarrEs,
        'do_refs' => $doRefs,
        'do_hotspots' => $doHotspots,
        // Optional POST: live edition id; 0 = CW_RESOURCE_LIBRARY_ENRICH_EDITION_ID or first live
        'resource_library_edition_id' => (int)($p['resource_library_edition_id'] ?? 0),
    ];
}

/**
 * @param list<array<string,mixed>> $slides
 * @param callable(string $event, array $data): void $emit
 */
function bulk_enrich_core_run(
    PDO $pdo,
    string $cdnBase,
    array $flags,
    array $slides,
    int $limit,
    bool $skipExisting,
    string $programKey,
    string $videoManifestPath,
    callable $emit
): int {
    $doEN = !empty($flags['do_en']);
    $doEsSlide = !empty($flags['do_es_slide']);
    $doNarrEn = !empty($flags['do_narration_en']);
    $doNarrEs = !empty($flags['do_narration_es']);
    $doRefs = !empty($flags['do_refs']);
    $doHotspots = !empty($flags['do_hotspots']);

    $rlRequested = (int)($flags['resource_library_edition_id'] ?? 0);
    $rlEdition = rl_enrich_resolve_edition_id($pdo, $rlRequested > 0 ? $rlRequested : null);
    if ($rlEdition > 0) {
        try {
            $cst = $pdo->prepare('SELECT COUNT(*) FROM resource_library_blocks WHERE edition_id = ?');
            $cst->execute([$rlEdition]);
            $bc = (int)$cst->fetchColumn();
            if ($bc <= 0) {
                $emit('step', [
                    'phase' => 'resource_library',
                    'message' => 'Resource Library edition ' . $rlEdition . ' (live) has no indexed blocks yet; run Sync JSON → database on Resource Library. Vision runs without excerpts.',
                ]);
            }
        } catch (Throwable $e) {
            // ignore
        }
    }

    $processed = 0;
    $visionNeeded = $doEN || $doNarrEn || $doRefs;

    foreach ($slides as $row) {
        $slideId = (int)$row['slide_id'];
        $extLessonId = (int)$row['external_lesson_id'];
        $pageNum = (int)$row['page_number'];

        if ($limit > 0 && $processed >= $limit) {
            $emit('limit_reached', ['limit' => $limit]);
            break;
        }

        if ($skipExisting && $doEN) {
            $chk = $pdo->prepare("SELECT 1 FROM slide_content WHERE slide_id=? AND lang='en' LIMIT 1");
            $chk->execute([$slideId]);
            if ($chk->fetchColumn()) {
                $emit('slide_skipped', [
                    'slide_id' => $slideId,
                    'external_lesson_id' => $extLessonId,
                    'page_number' => $pageNum,
                    'reason' => 'already_has_en',
                ]);
                continue;
            }
        }

        $emit('slide_start', [
            'slide_id' => $slideId,
            'external_lesson_id' => $extLessonId,
            'page_number' => $pageNum,
        ]);

        $slideOk = true;

        if ($doHotspots) {
            $emit('step', ['slide_id' => $slideId, 'phase' => 'hotspot', 'message' => 'Checking video manifest / hotspots']);
            $src = bulk_enrich_core_read_manifest_match($videoManifestPath, $extLessonId, $pageNum, $programKey);
            if ($src !== '') {
                bulk_enrich_core_ensure_hotspot($pdo, $slideId, $src);
                $emit('step', ['slide_id' => $slideId, 'phase' => 'hotspot', 'message' => 'Hotspot ensured', 'src' => $src]);
            } else {
                $emit('step', ['slide_id' => $slideId, 'phase' => 'hotspot', 'message' => 'No manifest video for this page']);
            }
        }

        $englishText = '';
        $narrationEn = '';
        $phak = [];
        $acs = [];

        if ($visionNeeded) {
            $emit('step', ['slide_id' => $slideId, 'phase' => 'vision', 'message' => 'Calling vision model (may take 1–3 min)…']);

            try {
                $bundle = bulk_enrich_core_vision_bundle_slide($pdo, $cdnBase, $slideId, $rlEdition);
                $englishText = $bundle['english_text'];
                $narrationEn = $bundle['narration_script_en'];
                $phak = $bundle['phak'];
                $acs = $bundle['acs'];
                $emit('step', ['slide_id' => $slideId, 'phase' => 'vision', 'message' => 'Vision response received']);
            } catch (Throwable $e) {
                $emit('slide_error', [
                    'slide_id' => $slideId,
                    'external_lesson_id' => $extLessonId,
                    'page_number' => $pageNum,
                    'error' => $e->getMessage(),
                ]);
                $slideOk = false;
                $emit('slide_done', ['slide_id' => $slideId, 'ok' => false]);
                continue;
            }

            if ($doEN) {
                bulk_enrich_core_sc_upsert($pdo, $slideId, 'en', ['blocks' => [['type' => 'raw', 'text' => $englishText]]], $englishText);
                $emit('step', ['slide_id' => $slideId, 'phase' => 'save_en', 'message' => 'Saved English slide text']);
            }

            if ($doNarrEn && $narrationEn !== '') {
                $narrationEs = '';
                if ($doNarrEs) {
                    try {
                        $emit('step', ['slide_id' => $slideId, 'phase' => 'narration_es', 'message' => 'Translating narration EN → ES']);
                        $narrationEs = bulk_enrich_core_ai_translate_es($narrationEn);
                    } catch (Throwable $e) {
                        $emit('step', ['slide_id' => $slideId, 'phase' => 'narration_es', 'message' => 'Narration ES translate failed: ' . $e->getMessage()]);
                    }
                } else {
                    $stmt = $pdo->prepare("SELECT narration_es FROM slide_enrichment WHERE slide_id=? LIMIT 1");
                    $stmt->execute([$slideId]);
                    $narrationEs = trim((string)$stmt->fetchColumn());
                }

                bulk_enrich_core_set_narration($pdo, $slideId, $narrationEn, $narrationEs);
                $emit('step', ['slide_id' => $slideId, 'phase' => 'narration_en', 'message' => 'Saved narration EN' . ($narrationEs !== '' ? ' + ES' : '')]);
            } elseif ($doNarrEn && $narrationEn === '') {
                $emit('step', ['slide_id' => $slideId, 'phase' => 'narration_en', 'message' => 'Vision returned empty narration EN — not updating']);
            }

            if ($doRefs) {
                bulk_enrich_core_replace_refs($pdo, $slideId, $phak, $acs);
                $emit('step', ['slide_id' => $slideId, 'phase' => 'refs', 'message' => 'Saved PHAK/ACS references']);
            }

            // Narration ES without saving EN narration this run: use DB EN or vision line as source
            if (!$doNarrEn && $doNarrEs) {
                $stmt = $pdo->prepare("SELECT narration_en FROM slide_enrichment WHERE slide_id=? LIMIT 1");
                $stmt->execute([$slideId]);
                $existingNarrEn = trim((string)$stmt->fetchColumn());
                $srcNarr = $existingNarrEn !== '' ? $existingNarrEn : $narrationEn;
                if ($srcNarr !== '') {
                    try {
                        $emit('step', ['slide_id' => $slideId, 'phase' => 'narration_es', 'message' => 'Translating narration EN → ES (without re-saving EN this run)']);
                        $nEs = bulk_enrich_core_ai_translate_es($srcNarr);
                        bulk_enrich_core_set_narration($pdo, $slideId, $srcNarr, $nEs);
                        $emit('step', ['slide_id' => $slideId, 'phase' => 'narration_es', 'message' => 'Saved narration ES']);
                    } catch (Throwable $e) {
                        $emit('step', ['slide_id' => $slideId, 'phase' => 'narration_es', 'message' => 'Narration ES failed: ' . $e->getMessage()]);
                    }
                } else {
                    $emit('step', ['slide_id' => $slideId, 'phase' => 'narration_es', 'message' => 'Skip narration ES: no EN narration source']);
                }
            }
        }

        // Narration ES only (no vision): translate existing EN narration
        if (!$visionNeeded && $doNarrEs) {
            $emit('step', ['slide_id' => $slideId, 'phase' => 'narration_es', 'message' => 'Narration ES only — loading EN from DB']);
            $stmt = $pdo->prepare("SELECT narration_en, narration_es FROM slide_enrichment WHERE slide_id=? LIMIT 1");
            $stmt->execute([$slideId]);
            $rowN = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $nEn = trim((string)($rowN['narration_en'] ?? ''));
            if ($nEn === '') {
                $emit('step', ['slide_id' => $slideId, 'phase' => 'narration_es', 'message' => 'Skip: no narration_en in DB']);
            } else {
                try {
                    $nEs = bulk_enrich_core_ai_translate_es($nEn);
                    bulk_enrich_core_set_narration($pdo, $slideId, $nEn, $nEs);
                    $emit('step', ['slide_id' => $slideId, 'phase' => 'narration_es', 'message' => 'Saved narration ES from DB EN']);
                } catch (Throwable $e) {
                    $emit('slide_error', [
                        'slide_id' => $slideId,
                        'external_lesson_id' => $extLessonId,
                        'page_number' => $pageNum,
                        'error' => 'narration_es: ' . $e->getMessage(),
                    ]);
                    $slideOk = false;
                }
            }
        }

        // Spanish slide text — independent of narration ES
        if ($doEsSlide) {
            $emit('step', ['slide_id' => $slideId, 'phase' => 'slide_es', 'message' => 'Translating slide text EN → ES']);
            if ($englishText === '') {
                $stmt = $pdo->prepare("SELECT plain_text FROM slide_content WHERE slide_id=? AND lang='en' LIMIT 1");
                $stmt->execute([$slideId]);
                $englishText = trim((string)$stmt->fetchColumn());
            }

            if ($englishText !== '') {
                try {
                    $spanishText = bulk_enrich_core_ai_translate_es($englishText);
                    bulk_enrich_core_sc_upsert($pdo, $slideId, 'es', ['blocks' => [['type' => 'raw', 'text' => $spanishText]]], $spanishText);
                    $emit('step', ['slide_id' => $slideId, 'phase' => 'slide_es', 'message' => 'Saved Spanish slide text']);
                } catch (Throwable $e) {
                    $emit('slide_error', [
                        'slide_id' => $slideId,
                        'external_lesson_id' => $extLessonId,
                        'page_number' => $pageNum,
                        'error' => 'slide_es: ' . $e->getMessage(),
                    ]);
                    $slideOk = false;
                }
            } else {
                $emit('step', ['slide_id' => $slideId, 'phase' => 'slide_es', 'message' => 'Skip: no EN slide text']);
            }
        }

        $emit('slide_done', [
            'slide_id' => $slideId,
            'external_lesson_id' => $extLessonId,
            'page_number' => $pageNum,
            'ok' => $slideOk,
        ]);
        $processed++;
    }

    $emit('run_done', ['processed' => $processed, 'slides_in_batch' => count($slides)]);

    return $processed;
}

/**
 * @param array<string,mixed> $post
 * @return array{0: list<array<string,mixed>>, 1: ?string, 2: int}
 */
function bulk_enrich_core_resolve_slide_batch(PDO $pdo, array $post): array
{
    $scope = (string)($post['scope'] ?? 'course');
    $courseId = (int)($post['course_id'] ?? 0);
    $lessonId = (int)($post['lesson_id'] ?? 0);

    $targetSlideIds = [];
    $rawTargets = $post['target_slide_ids'] ?? null;
    if (is_array($rawTargets)) {
        $targetSlideIds = array_values(array_unique(array_map('intval', $rawTargets)));
    } elseif (is_string($rawTargets) && trim($rawTargets) !== '') {
        $targetSlideIds = array_values(array_unique(array_map('intval', preg_split('/\s*,\s*/', $rawTargets))));
    }
    $targetSlideIds = array_values(array_filter($targetSlideIds, function ($x) {
        return (int)$x > 0;
    }));

    if ($courseId <= 0) {
        return [[], 'course_id required', 0];
    }
    if ($scope === 'lesson' && $lessonId <= 0) {
        return [[], 'lesson_id required for scope=lesson', 0];
    }

    $sql = "
      SELECT s.id AS slide_id, s.page_number, s.image_path,
             l.external_lesson_id, l.id AS lesson_row_id
      FROM slides s
      JOIN lessons l ON l.id = s.lesson_id
      WHERE COALESCE(s.is_deleted, 0) = 0
    ";
    $params = [];

    if ($scope === 'course') {
        $sql .= ' AND l.course_id=? ';
        $params[] = $courseId;
    } else {
        $sql .= ' AND l.id=? ';
        $params[] = $lessonId;
    }

    $sql .= ' ORDER BY l.sort_order, l.external_lesson_id, l.id, s.page_number ';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $slides = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($targetSlideIds !== []) {
        $allowed = array_flip($targetSlideIds);
        $slides = array_values(array_filter($slides, function ($row) use ($allowed) {
            return isset($allowed[(int)$row['slide_id']]);
        }));
        if ($slides === []) {
            return [[], 'No slides matched target_slide_ids in scope', 0];
        }
    }

    $batchSize = max(0, (int)($post['batch_size'] ?? 0));
    $batchOffset = max(0, (int)($post['batch_offset'] ?? 0));
    $totalInScope = count($slides);
    if ($targetSlideIds === [] && $batchSize > 0) {
        $slides = array_slice($slides, $batchOffset, $batchSize);
    }

    if ($slides === []) {
        return [[], 'No slides in this batch or scope', $totalInScope];
    }

    return [$slides, null, $totalInScope];
}
