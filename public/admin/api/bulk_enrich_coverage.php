<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';

cw_require_admin();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

/**
 * True when kings manifest lists at least one video URL for this lesson page.
 */
function bec_manifest_has_video(int $extLessonId, int $pageNum): bool
{
    $manifestPath = __DIR__ . '/../../assets/kings_videos_manifest.json';
    if (!file_exists($manifestPath)) {
        return false;
    }
    $raw = file_get_contents($manifestPath);
    $arr = json_decode($raw, true);
    if (!is_array($arr)) {
        return false;
    }
    foreach ($arr as $item) {
        $lid = (int)($item['lessonId'] ?? 0);
        $pg = (int)($item['page'] ?? 0);
        if ($lid !== $extLessonId || $pg !== $pageNum) {
            continue;
        }
        $urls = $item['videoUrls'] ?? [];
        return is_array($urls) && count($urls) > 0;
    }

    return false;
}

$courseId = (int)($_GET['course_id'] ?? 0);
$lessonId = (int)($_GET['lesson_id'] ?? 0);

if ($courseId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'course_id required']);
    exit;
}

$sql = "
  SELECT
    s.id AS slide_id,
    s.page_number,
    l.external_lesson_id,
    l.id AS lesson_id,
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
  LEFT JOIN slide_content sc_en ON sc_en.slide_id = s.id AND sc_en.lang = 'en'
  LEFT JOIN slide_content sc_es ON sc_es.slide_id = s.id AND sc_es.lang = 'es'
  LEFT JOIN slide_enrichment se ON se.slide_id = s.id
  WHERE s.is_deleted = 0
    AND l.course_id = ?
";
$params = [$courseId];

if ($lessonId > 0) {
    $sql .= ' AND l.id = ? ';
    $params[] = $lessonId;
}

$sql .= ' ORDER BY l.sort_order, l.external_lesson_id, s.page_number ';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

const BEC_MIN_EN_LEN = 24;
const BEC_MIN_ES_LEN = 12;
const BEC_MIN_NARR_EN = 32;
const BEC_MIN_NARR_ES = 12;
const BEC_LOW_CONF = 0.42;

$slides = [];
$counts = [
    'total' => 0,
    'flagged' => 0,
    'en_ok' => 0,
    'es_ok' => 0,
    'narr_en_ok' => 0,
    'narr_es_ok' => 0,
    'phak_ok' => 0,
    'acs_ok' => 0,
    'hotspot_expected_ok' => 0,
    'hotspot_expected' => 0,
];

foreach ($rows as $r) {
    $slideId = (int)$r['slide_id'];
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

    $manifestVideo = bec_manifest_has_video($extLessonId, $pageNum);
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

    $counts['total']++;
    if ($flagged) {
        $counts['flagged']++;
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
        'lesson_id' => (int)$r['lesson_id'],
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
            'refs_low_confidence' => $refsLowConfidence,
            'other_refs_count' => (int)($r['other_ref_cnt'] ?? 0),
            'video_hotspot' => $hotspotOk,
            'manifest_lists_video' => $manifestVideo,
        ],
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
        'overlay_editor_url' => '/admin/slide_overlay_editor.php?slide_id=' . $slideId . '&course_id=' . $courseId . '&lesson_id=' . (int)$r['lesson_id'],
    ];
}

echo json_encode([
    'ok' => true,
    'course_id' => $courseId,
    'lesson_id' => $lessonId,
    'thresholds' => [
        'min_en_len' => BEC_MIN_EN_LEN,
        'min_es_len' => BEC_MIN_ES_LEN,
        'min_narration_en' => BEC_MIN_NARR_EN,
        'min_narration_es' => BEC_MIN_NARR_ES,
        'low_confidence_below' => BEC_LOW_CONF,
    ],
    'notes' => [
        'bulk_pipeline' => 'Bulk enrich writes EN/ES slide_content, narration_en/es in slide_enrichment, PHAK+ACS in slide_references, and optional hotspots from kings_videos_manifest.json.',
        'other_refs' => 'References outside PHAK/ACS are not created by bulk_enrich_run.php; other_ref_count is informational.',
        'ecfr' => 'eCFR/FAR rows are not produced by the current bulk enrich script — only PHAK and ACS.',
    ],
    'summary' => $counts,
    'slides' => $slides,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
