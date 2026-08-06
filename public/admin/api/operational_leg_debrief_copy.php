<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/FlightDebriefService.php';
require_once __DIR__ . '/../../../src/ManualReconstructionBundleService.php';

cw_require_admin();
header('Content-Type: application/json; charset=utf-8');

$debriefId = (int)($_GET['debrief_id'] ?? 0);
$bundleId = (int)($_GET['bundle_id'] ?? 0);
$format = strtolower(trim((string)($_GET['format'] ?? 'full')));
if (!in_array($format, array('full', 'generic_briefing'), true)) {
    $format = 'full';
}

/**
 * Match Master Logbook lettered segment titles (A. Title).
 */
function cvr_debrief_copy_segment_label(string $title, int $index): string
{
    $title = trim($title);
    while ($title !== '' && preg_match('/^[A-Z]\.\s*/', $title)) {
        $title = preg_replace('/^[A-Z]\.\s*/', '', $title, 1) ?? $title;
        $title = trim($title);
    }
    $title = trim($title, " \t\n\r\0\x0B.-");
    if ($title === '') {
        $title = 'Flight Segment';
    }
    return ($index < 26 ? chr(65 + $index) . '. ' : '') . $title;
}

/**
 * @param array<string,mixed> $debrief
 * @param list<array<string,mixed>> $chrono
 */
function cvr_debrief_copy_generic_briefing(array $debrief, array $chrono): string
{
    $sections = array();
    $general = trim((string)($debrief['general_text'] ?? ''));
    if ($general !== '') {
        $sections[] = "General\n" . $general;
    }
    foreach ($chrono as $index => $segment) {
        if (!is_array($segment)) {
            continue;
        }
        $narrative = trim((string)($segment['narrative'] ?? ''));
        $sections[] = cvr_debrief_copy_segment_label((string)($segment['title'] ?? 'Flight Segment'), (int)$index)
            . "\n" . ($narrative !== '' ? $narrative : '—');
    }
    return trim(implode("\n\n", array_filter($sections)));
}

/**
 * @param array<string,mixed> $debrief
 * @param list<array<string,mixed>> $chrono
 */
function cvr_debrief_copy_full(array $debrief, array $chrono): string
{
    $lines = array();
    $lines[] = 'IPCA STRUCTURED DEBRIEF';
    $lines[] = '=======================';
    $lines[] = 'Debrief ID: ' . (int)($debrief['id'] ?? 0);
    $lines[] = 'Status: ' . trim((string)($debrief['status'] ?? ''));
    $lines[] = 'Overall: ' . trim((string)($debrief['suggested_overall'] ?? $debrief['instructor_overall'] ?? ''));
    $lines[] = '';
    $lines[] = 'GENERAL';
    $lines[] = '-------';
    $lines[] = trim((string)($debrief['general_text'] ?? '')) ?: '—';
    $lines[] = '';
    $lines[] = 'CHRONOLOGICAL REVIEW';
    $lines[] = '--------------------';
    if ($chrono === array()) {
        $lines[] = '—';
    } else {
        $i = 1;
        foreach ($chrono as $item) {
            if (!is_array($item)) {
                continue;
            }
            $title = trim((string)($item['title'] ?? 'Segment ' . $i));
            $narrative = trim((string)($item['narrative'] ?? ''));
            $lines[] = $i . '. ' . $title;
            $lines[] = $narrative !== '' ? $narrative : '—';
            $lines[] = '';
            $i++;
        }
    }
    $lines[] = 'MISSION STANDARDS';
    $lines[] = '-----------------';
    $lines[] = trim((string)($debrief['mission_standards_assessment'] ?? $debrief['mission_assessment_text'] ?? '')) ?: '—';
    $lines[] = '';
    $lines[] = 'SUMMARY / NEXT STEPS';
    $lines[] = '--------------------';
    $lines[] = trim((string)($debrief['summary_next_steps'] ?? $debrief['summary_next_steps_text'] ?? '')) ?: '—';
    return implode("\n", $lines);
}

try {
    $debriefService = new FlightDebriefService($pdo);
    $debrief = null;
    if ($debriefId > 0) {
        $debrief = $debriefService->structuredDebrief($debriefId);
    } elseif ($bundleId > 0) {
        $list = $debriefService->structuredDebriefsForBundle($bundleId);
        $first = is_array($list[0] ?? null) ? $list[0] : null;
        if (is_array($first) && isset($first['id'])) {
            $debrief = $debriefService->structuredDebrief((int)$first['id']);
        }
    }

    if (!is_array($debrief)) {
        echo json_encode(array(
            'ok' => false,
            'format' => $format,
            'copy_text' => '',
            'message' => $bundleId > 0
                ? 'No structured debrief yet for this leg. Generate one from Reconstruction, then reopen Details.'
                : 'No debrief is linked to this leg yet.',
            'bundle_id' => $bundleId,
            'debrief_id' => $debriefId,
        ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    $chrono = $debrief['chronological_review'] ?? ($debrief['chronological_review_json'] ?? null);
    if (is_string($chrono)) {
        $decoded = json_decode($chrono, true);
        $chrono = is_array($decoded) ? $decoded : array();
    }
    if (!is_array($chrono)) {
        $chrono = array();
    }

    // Generic Briefing = student-facing narrative only (General + chronological segments).
    // Excludes grades, mission standards, and SRM evaluation sheets.
    $copyText = $format === 'generic_briefing'
        ? cvr_debrief_copy_generic_briefing($debrief, $chrono)
        : cvr_debrief_copy_full($debrief, $chrono);

    if ($format === 'generic_briefing' && trim($copyText) === '') {
        echo json_encode(array(
            'ok' => false,
            'format' => $format,
            'copy_text' => '',
            'message' => 'This debrief has no Generic Briefing narrative yet.',
            'debrief_id' => (int)($debrief['id'] ?? 0),
            'bundle_id' => $bundleId,
        ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode(array(
        'ok' => true,
        'format' => $format,
        'copy_text' => $copyText,
        'debrief_id' => (int)($debrief['id'] ?? 0),
        'bundle_id' => $bundleId,
        'message' => '',
    ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(array(
        'ok' => false,
        'format' => $format,
        'copy_text' => '',
        'message' => $e->getMessage(),
    ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
