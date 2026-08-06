<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/FlightDebriefService.php';
require_once __DIR__ . '/../../../src/ManualReconstructionBundleService.php';

cw_require_admin();
header('Content-Type: application/json; charset=utf-8');

$debriefId = (int)($_GET['debrief_id'] ?? 0);
$bundleId = (int)($_GET['bundle_id'] ?? 0);

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
            'copy_text' => '',
            'message' => $bundleId > 0
                ? 'No structured debrief yet for this leg. Generate one from Reconstruction, then reopen Debriefing.'
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
    $lines[] = trim((string)($debrief['mission_standards_assessment'] ?? '')) ?: '—';
    $lines[] = '';
    $lines[] = 'SUMMARY / NEXT STEPS';
    $lines[] = '--------------------';
    $lines[] = trim((string)($debrief['summary_next_steps'] ?? '')) ?: '—';

    echo json_encode(array(
        'ok' => true,
        'copy_text' => implode("\n", $lines),
        'debrief_id' => (int)($debrief['id'] ?? 0),
        'bundle_id' => $bundleId,
        'message' => '',
    ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(array(
        'ok' => false,
        'copy_text' => '',
        'message' => $e->getMessage(),
    ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
