<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/bulk_enrich_video_manifest.php';

cw_require_admin();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$CDN_BASE = rtrim(getenv('CW_CDN_BASE') ?: '', '/');
if ($CDN_BASE === '') {
    $CDN_BASE = 'https://ipca-media.nyc3.cdn.digitaloceanspaces.com';
}

$videoManifestFile = trim((string)($_GET['video_manifest'] ?? 'kings_videos_manifest.json'));
$videoManifestPath = bec_resolve_video_manifest_file($videoManifestFile) ?? '';

$scopeProgramKey = '';
$programId = (int)($_GET['program_id'] ?? 0);
$courseId = (int)($_GET['course_id'] ?? 0);
$lessonId = (int)($_GET['lesson_id'] ?? 0);
$listOffset = max(0, (int)($_GET['offset'] ?? 0));
$listLimit = (int)($_GET['limit'] ?? 0);
if ($listLimit <= 0) {
    $listLimit = 400;
}
$listLimit = min(800, max(25, $listLimit));

if ($programId <= 0 && $courseId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'course_id or program_id required']);
    exit;
}

$scopeProgram = $programId > 0;
if ($scopeProgram) {
    $pkStmt = $pdo->prepare('SELECT program_key FROM programs WHERE id = ? LIMIT 1');
    $pkStmt->execute([$programId]);
    $scopeProgramKey = (string)$pkStmt->fetchColumn();
} elseif ($courseId > 0) {
    $pkStmt = $pdo->prepare('SELECT p.program_key FROM courses c JOIN programs p ON p.id = c.program_id WHERE c.id = ? LIMIT 1');
    $pkStmt->execute([$courseId]);
    $scopeProgramKey = (string)$pkStmt->fetchColumn();
}

$sql = "
  SELECT
    s.id AS slide_id,
    s.image_path,
    COALESCE(s.is_deleted, 0) AS slide_is_deleted,
    s.page_number,
    l.external_lesson_id,
    l.id AS lesson_id,
    l.course_id AS slide_course_id,
    c.title AS course_title,
    l.title AS lesson_title,
    sc_en.plain_text AS en_plain,
    sc_es.plain_text AS es_plain,
    se.narration_en,
    se.narration_es,
    (SELECT COUNT(*) FROM slide_hotspots h WHERE h.slide_id = s.id AND h.is_deleted = 0) AS hotspot_cnt,
    (SELECT COUNT(*) FROM slide_references r WHERE r.slide_id = s.id AND r.ref_type = 'PHAK') AS phak_cnt,
    (SELECT COUNT(*) FROM slide_references r WHERE r.slide_id = s.id AND r.ref_type = 'ACS') AS acs_cnt,
    (SELECT COUNT(*) FROM slide_references r WHERE r.slide_id = s.id AND r.ref_type NOT IN ('PHAK','ACS')) AS other_ref_cnt,
    (SELECT MIN(r.confidence) FROM slide_references r WHERE r.slide_id = s.id AND r.ref_type IN ('PHAK','ACS')) AS min_ref_confidence
  FROM slides s
  INNER JOIN lessons l ON l.id = s.lesson_id
  INNER JOIN courses c ON c.id = l.course_id
  LEFT JOIN slide_content sc_en ON sc_en.slide_id = s.id AND sc_en.lang = 'en'
  LEFT JOIN slide_content sc_es ON sc_es.slide_id = s.id AND sc_es.lang = 'es'
  LEFT JOIN slide_enrichment se ON se.slide_id = s.id
  WHERE COALESCE(s.is_deleted, 0) = 0
    AND " . ($scopeProgram ? 'c.program_id = ?' : 'l.course_id = ?') . "
";
$params = [$scopeProgram ? $programId : $courseId];

if ($lessonId > 0) {
    $sql .= ' AND l.id = ? ';
    $params[] = $lessonId;
}

$sql .= ' ORDER BY c.sort_order, c.id, l.sort_order, l.external_lesson_id, l.id, s.page_number ';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$countSql = '
  SELECT COUNT(*)
  FROM slides s
  INNER JOIN lessons l ON l.id = s.lesson_id
  INNER JOIN courses c ON c.id = l.course_id
  WHERE COALESCE(s.is_deleted, 0) = 0
    AND ' . ($scopeProgram ? 'c.program_id = ?' : 'l.course_id = ?') . '
';
$countParams = [$scopeProgram ? $programId : $courseId];
if ($lessonId > 0) {
    $countSql .= ' AND l.id = ? ';
    $countParams[] = $lessonId;
}
$cntStmt = $pdo->prepare($countSql);
$cntStmt->execute($countParams);
$slidesExpectedFromDb = (int)$cntStmt->fetchColumn();

$sqlLessons = "
  SELECT
    l.id,
    l.external_lesson_id,
    l.title,
    l.sort_order,
    l.course_id AS lesson_course_id,
    c.title AS course_title,
    COALESCE(SUM(CASE WHEN COALESCE(s.is_deleted, 0) = 0 THEN 1 ELSE 0 END), 0) AS active_slides,
    COALESCE(SUM(CASE WHEN s.is_deleted = 1 THEN 1 ELSE 0 END), 0) AS deleted_slides
  FROM lessons l
  INNER JOIN courses c ON c.id = l.course_id
  LEFT JOIN slides s ON s.lesson_id = l.id
  WHERE " . ($scopeProgram ? 'c.program_id = ?' : 'l.course_id = ?') . '
';
$paramsLessons = [$scopeProgram ? $programId : $courseId];
if ($lessonId > 0) {
    $sqlLessons .= ' AND l.id = ? ';
    $paramsLessons[] = $lessonId;
}
$sqlLessons .= ' GROUP BY l.id, l.external_lesson_id, l.title, l.sort_order, l.course_id, c.sort_order, c.id, c.title
  ORDER BY c.sort_order, c.id, l.sort_order, l.external_lesson_id, l.id ';

$stmtLessons = $pdo->prepare($sqlLessons);
$stmtLessons->execute($paramsLessons);
$lessonList = $stmtLessons->fetchAll(PDO::FETCH_ASSOC);

const BEC_MIN_EN_LEN = 24;
const BEC_MIN_ES_LEN = 12;
const BEC_MIN_NARR_EN = 32;
const BEC_MIN_NARR_ES = 12;
const BEC_LOW_CONF = 0.42;

function bec_preview_multiline(string $text, int $maxLines = 4, int $softWrap = 40): string
{
    $text = trim($text);
    if ($text === '') {
        return '';
    }
    $text = preg_replace("/[\x00-\x08\x0B\x0C\x0E-\x1F]/u", '', $text);
    $lines = preg_split('/\R+/', $text) ?: [];
    $out = [];
    foreach ($lines as $ln) {
        $ln = trim($ln);
        if ($ln === '') {
            continue;
        }
        while (mb_strlen($ln) > $softWrap) {
            $out[] = mb_substr($ln, 0, $softWrap);
            $ln = mb_substr($ln, $softWrap);
            if (count($out) >= $maxLines) {
                return implode("\n", $out);
            }
        }
        $out[] = $ln;
        if (count($out) >= $maxLines) {
            break;
        }
    }

    return implode("\n", $out);
}

$slides = [];
$counts = [
    'total' => 0,
    'flagged' => 0,
    'refs_ok' => 0,
    'en_ok' => 0,
    'es_ok' => 0,
    'narr_en_ok' => 0,
    'narr_es_ok' => 0,
    'phak_ok' => 0,
    'acs_ok' => 0,
    'hotspot_expected_ok' => 0,
    'hotspot_expected' => 0,
    'lessons_in_scope' => count($lessonList),
    'lessons_without_active_slides' => 0,
    'lessons_slide_aggregate_mismatch' => 0,
    'slides_rows_from_query' => count($rows),
    'slides_expected_db_count' => $slidesExpectedFromDb,
];

/**
 * Append one slide audit row (mutates $slides, $counts).
 *
 * @param array<string,mixed> $r
 */
$appendSlideRow = function (array $r, int $lessonRowId) use (&$slides, &$counts, $videoManifestPath, $CDN_BASE): void {
    $slideId = (int)$r['slide_id'];
    $slideCourseId = (int)($r['slide_course_id'] ?? 0);
    $extLessonId = (int)$r['external_lesson_id'];
    $pageNum = (int)$r['page_number'];

    $enPlain = trim((string)($r['en_plain'] ?? ''));
    $esPlain = trim((string)($r['es_plain'] ?? ''));
    $narrEn = trim((string)($r['narration_en'] ?? ''));
    $narrEs = trim((string)($r['narration_es'] ?? ''));

    $enOk = mb_strlen($enPlain) >= BEC_MIN_EN_LEN;
    $esOk = $enOk && mb_strlen($esPlain) >= BEC_MIN_ES_LEN;
    $narrEnOk = $enOk && mb_strlen($narrEn) >= BEC_MIN_NARR_EN;
    $narrEsOk = $narrEnOk && mb_strlen($narrEs) >= BEC_MIN_NARR_ES;

    $phakCnt = (int)($r['phak_cnt'] ?? 0);
    $acsCnt = (int)($r['acs_cnt'] ?? 0);
    $phakOk = $phakCnt >= 1;
    $acsOk = $acsCnt >= 1;

    $minConf = $r['min_ref_confidence'];
    $minConfF = is_numeric($minConf) ? (float)$minConf : null;
    $refsLowConfidence = $minConfF !== null && $minConfF < BEC_LOW_CONF;

    $manifestVideo = $videoManifestPath !== '' && bec_manifest_has_video_from_file($videoManifestPath, $extLessonId, $pageNum);
    $hotspotCnt = (int)($r['hotspot_cnt'] ?? 0);
    $hotspotOk = !$manifestVideo || $hotspotCnt > 0;

    $reasons = [];
    if (!$enOk) {
        $reasons[] = 'missing_or_short_en';
    }
    if ($enOk && !$esOk) {
        $reasons[] = 'missing_or_short_es';
    }
    if ($enOk && !$narrEnOk) {
        $reasons[] = 'missing_or_short_narration_en';
    }
    if ($narrEnOk && !$narrEsOk) {
        $reasons[] = 'missing_or_short_narration_es';
    }
    if (!$phakOk) {
        $reasons[] = 'no_phak_refs';
    }
    if (!$acsOk) {
        $reasons[] = 'no_acs_refs';
    }
    if ($refsLowConfidence) {
        $reasons[] = 'low_reference_confidence';
    }
    if ($manifestVideo && $hotspotCnt <= 0) {
        $reasons[] = 'manifest_video_but_no_hotspot';
    }

    $flagged = $reasons !== [];

    $refsOk = $phakOk && $acsOk && !$refsLowConfidence;

    $counts['total']++;
    if ($flagged) {
        $counts['flagged']++;
    }
    if ($refsOk) {
        $counts['refs_ok']++;
    }
    if ($enOk) {
        $counts['en_ok']++;
    }
    if ($esOk) {
        $counts['es_ok']++;
    }
    if ($narrEnOk) {
        $counts['narr_en_ok']++;
    }
    if ($narrEsOk) {
        $counts['narr_es_ok']++;
    }
    if ($phakOk) {
        $counts['phak_ok']++;
    }
    if ($acsOk) {
        $counts['acs_ok']++;
    }
    if ($manifestVideo) {
        $counts['hotspot_expected']++;
        if ($hotspotOk) {
            $counts['hotspot_expected_ok']++;
        }
    }

    $slides[] = [
        'slide_id' => $slideId,
        'placeholder' => false,
        'course_id' => $slideCourseId,
        'course_title' => (string)($r['course_title'] ?? ''),
        'lesson_id' => $lessonRowId,
        'lesson_title' => (string)$r['lesson_title'],
        'external_lesson_id' => $extLessonId,
        'page_number' => $pageNum,
        'checks' => [
            'extract_en' => $enOk,
            'translate_es' => $esOk,
            'narration_en' => $narrEnOk,
            'narration_es' => $narrEsOk,
            'phak_refs' => $phakOk,
            'acs_refs' => $acsOk,
            'refs_ok' => $refsOk,
            'refs_low_confidence' => $refsLowConfidence,
            'other_refs_count' => (int)($r['other_ref_cnt'] ?? 0),
            'video_hotspot' => $hotspotOk,
            'manifest_lists_video' => $manifestVideo,
        ],
        'thumb_url' => (trim((string)($r['image_path'] ?? '')) !== '')
            ? cdn_url($CDN_BASE, (string)$r['image_path'])
            : '',
        'preview_en' => bec_preview_multiline($enPlain),
        'preview_es' => bec_preview_multiline($esPlain),
        'preview_narr_en' => bec_preview_multiline($narrEn),
        'preview_narr_es' => bec_preview_multiline($narrEs),
        'metrics' => [
            'en_len' => mb_strlen($enPlain),
            'es_len' => mb_strlen($esPlain),
            'narr_en_len' => mb_strlen($narrEn),
            'narr_es_len' => mb_strlen($narrEs),
            'phak_count' => $phakCnt,
            'acs_count' => $acsCnt,
            'hotspot_count' => $hotspotCnt,
            'min_phak_acs_confidence' => $minConfF,
        ],
        'flagged' => $flagged,
        'flag_reasons' => $reasons,
        'overlay_editor_url' => '/admin/slide_overlay_editor.php?slide_id=' . $slideId . '&course_id=' . $slideCourseId . '&lesson_id=' . $lessonRowId . '&return_to=bulk_enrich',
    ];
};

$slideChunks = [];
foreach ($rows as $r) {
    $chunkLid = (int)$r['lesson_id'];
    if (!isset($slideChunks[$chunkLid])) {
        $slideChunks[$chunkLid] = [];
    }
    $slideChunks[$chunkLid][] = $r;
}

foreach ($lessonList as $les) {
    $lid = (int)$les['id'];
    $activeCount = (int)$les['active_slides'];
    $deletedCount = (int)$les['deleted_slides'];
    $chunk = $slideChunks[$lid] ?? [];

    if ($chunk !== []) {
        foreach ($chunk as $r) {
            $appendSlideRow($r, $lid);
        }
        continue;
    }

    if ($activeCount === 0) {
        $counts['lessons_without_active_slides']++;
        $lcourseId = (int)($les['lesson_course_id'] ?? 0);
        $slides[] = [
            'slide_id' => 0,
            'placeholder' => true,
            'course_id' => $lcourseId,
            'course_title' => (string)($les['course_title'] ?? ''),
            'lesson_id' => $lid,
            'lesson_title' => (string)$les['title'],
            'external_lesson_id' => (int)$les['external_lesson_id'],
            'page_number' => null,
            'checks' => [
                'extract_en' => false,
                'translate_es' => false,
                'narration_en' => false,
                'narration_es' => false,
                'phak_refs' => false,
                'acs_refs' => false,
                'refs_ok' => false,
                'refs_low_confidence' => false,
                'other_refs_count' => 0,
                'video_hotspot' => false,
                'manifest_lists_video' => false,
            ],
            'thumb_url' => '',
            'preview_en' => '',
            'preview_es' => '',
            'preview_narr_en' => '',
            'preview_narr_es' => '',
            'metrics' => [
                'en_len' => 0,
                'es_len' => 0,
                'narr_en_len' => 0,
                'narr_es_len' => 0,
                'phak_count' => 0,
                'acs_count' => 0,
                'hotspot_count' => 0,
                'min_phak_acs_confidence' => null,
                'active_slides' => 0,
                'deleted_slides' => $deletedCount,
            ],
            'flagged' => true,
            'flag_reasons' => $deletedCount > 0
                ? ['no_active_slides', 'only_deleted_slides_count_' . $deletedCount]
                : ['no_active_slides'],
            'overlay_editor_url' => '/admin/slides.php?course_id=' . $lcourseId . '&lesson_id=' . $lid,
        ];
        $counts['flagged']++;
        continue;
    }

    $counts['lessons_slide_aggregate_mismatch']++;
    $lcourseId = (int)($les['lesson_course_id'] ?? 0);
    $slides[] = [
        'slide_id' => 0,
        'placeholder' => true,
        'course_id' => $lcourseId,
        'course_title' => (string)($les['course_title'] ?? ''),
        'lesson_id' => $lid,
        'lesson_title' => (string)$les['title'],
        'external_lesson_id' => (int)$les['external_lesson_id'],
        'page_number' => null,
            'checks' => [
                'extract_en' => false,
                'translate_es' => false,
                'narration_en' => false,
                'narration_es' => false,
                'phak_refs' => false,
                'acs_refs' => false,
                'refs_ok' => false,
                'refs_low_confidence' => false,
                'other_refs_count' => 0,
                'video_hotspot' => false,
                'manifest_lists_video' => false,
            ],
            'thumb_url' => '',
            'preview_en' => '',
            'preview_es' => '',
            'preview_narr_en' => '',
            'preview_narr_es' => '',
            'metrics' => [
                'en_len' => 0,
                'es_len' => 0,
                'narr_en_len' => 0,
                'narr_es_len' => 0,
                'phak_count' => 0,
                'acs_count' => 0,
                'hotspot_count' => 0,
                'min_phak_acs_confidence' => null,
                'active_slides' => $activeCount,
                'deleted_slides' => $deletedCount,
            ],
            'flagged' => true,
            'flag_reasons' => ['lesson_slide_count_mismatch_db_reports_' . $activeCount . '_active_but_main_query_returned_zero_rows'],
            'overlay_editor_url' => '/admin/slides.php?course_id=' . $lcourseId . '&lesson_id=' . $lid,
        ];
    $counts['flagged']++;
}

$listedLessonIds = array_fill_keys(array_map('intval', array_column($lessonList, 'id')), true);
foreach ($slideChunks as $orphanLid => $chunk) {
    if (isset($listedLessonIds[$orphanLid])) {
        continue;
    }
    foreach ($chunk as $r) {
        $appendSlideRow($r, $orphanLid);
    }
}

$counts['slides_processed_matches_query'] = ($counts['total'] === $slidesExpectedFromDb && count($rows) === $slidesExpectedFromDb);
if ($counts['total'] !== $slidesExpectedFromDb || count($rows) !== $slidesExpectedFromDb) {
    $counts['coverage_warning'] = 'Slide row count mismatch: summary.total=' . $counts['total']
        . ', fetched_rows=' . count($rows) . ', db_count=' . $slidesExpectedFromDb;
} else {
    $counts['coverage_warning'] = null;
}

$filter = trim((string)($_GET['filter'] ?? ''));
if ($filter !== '' && $filter !== 'all') {
    $slides = array_values(array_filter($slides, function (array $row) use ($filter): bool {
        if (!empty($row['placeholder'])) {
            return in_array($filter, ['flagged', 'incomplete'], true);
        }
        $c = $row['checks'] ?? [];
        switch ($filter) {
            case 'en_missing':
                return empty($c['extract_en']);
            case 'es_missing':
                return !empty($c['extract_en']) && empty($c['translate_es']);
            case 'narr_en_missing':
                return !empty($c['extract_en']) && empty($c['narration_en']);
            case 'narr_es_missing':
                return !empty($c['narration_en']) && empty($c['narration_es']);
            case 'refs_missing':
                return empty($c['refs_ok']);
            case 'video_hotspot_missing':
                return !empty($c['manifest_lists_video']) && empty($c['video_hotspot']);
            case 'flagged':
            case 'incomplete':
                return !empty($row['flagged']);
            default:
                return true;
        }
    }));
}

$slidesFilteredTotal = count($slides);
$slidesPage = array_slice($slides, $listOffset, $listLimit);

$n = max(1, (int)$counts['total']);
$videoRatio = ((int)$counts['hotspot_expected'] > 0)
    ? ((int)$counts['hotspot_expected_ok'] / max(1, (int)$counts['hotspot_expected']))
    : 1.0;
$completionPct = round(
    100 * (
        ($counts['en_ok'] / $n)
        + ($counts['es_ok'] / $n)
        + ($counts['narr_en_ok'] / $n)
        + ($counts['narr_es_ok'] / $n)
        + ($counts['refs_ok'] / $n)
        + $videoRatio
    ) / 6.0,
    1
);

$dashboard = [
    'active_slides' => (int)$counts['slides_expected_db_count'],
    'audit_rows' => (int)$counts['total'],
    'english_ok' => (int)$counts['en_ok'],
    'spanish_ok' => (int)$counts['es_ok'],
    'narration_en_ok' => (int)$counts['narr_en_ok'],
    'narration_es_ok' => (int)$counts['narr_es_ok'],
    'references_ok' => (int)$counts['refs_ok'],
    'video_pages_manifest' => (int)$counts['hotspot_expected'],
    'video_hotspots_ok' => (int)$counts['hotspot_expected_ok'],
    'flagged_slides' => (int)$counts['flagged'],
    'completion_percent' => $completionPct,
];

echo json_encode([
    'ok' => true,
    'program_id' => $scopeProgram ? $programId : null,
    'course_id' => $courseId > 0 ? $courseId : null,
    'program_key' => $scopeProgramKey,
    'scope' => $scopeProgram ? 'program' : 'course',
    'lesson_id' => $lessonId,
    'video_manifest_requested' => $videoManifestFile,
    'video_manifest_resolved' => $videoManifestPath !== '' ? basename($videoManifestPath) : null,
    'thresholds' => [
        'min_en_len' => BEC_MIN_EN_LEN,
        'min_es_len' => BEC_MIN_ES_LEN,
        'min_narration_en' => BEC_MIN_NARR_EN,
        'min_narration_es' => BEC_MIN_NARR_ES,
        'low_confidence_below' => BEC_LOW_CONF,
    ],
    'notes' => [
        'lesson_coverage' => 'Every lesson in scope appears: lessons with no active slides show one placeholder row. Soft-deleted slides (slides.is_deleted = 1) are never listed and do not count toward active slides.',
        'bulk_pipeline' => 'Bulk enrich writes EN/ES slide_content, narration_en/es in slide_enrichment, PHAK+ACS in slide_references, and optional hotspots from the selected video manifest JSON in public/assets/.',
        'other_refs' => 'References outside PHAK/ACS are not created by bulk_enrich_run.php; other_ref_count is informational.',
        'ecfr' => 'eCFR/FAR rows are not produced by the current bulk enrich script — only PHAK and ACS.',
    ],
    'summary' => $counts,
    'dashboard' => $dashboard,
    'pagination' => [
        'offset' => $listOffset,
        'limit' => $listLimit,
        'filtered_total' => $slidesFilteredTotal,
        'returned' => count($slidesPage),
        'has_more' => ($listOffset + count($slidesPage)) < $slidesFilteredTotal,
    ],
    'filter' => $filter === '' ? 'all' : $filter,
    'slides' => $slidesPage,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
