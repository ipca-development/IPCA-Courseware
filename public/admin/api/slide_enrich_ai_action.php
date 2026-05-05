<?php
declare(strict_types=1);

/**
 * Single-slide AI enrichment used by the overlay editor (narration + refs use full vision bundle).
 */

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/openai.php';
require_once __DIR__ . '/../../../src/bulk_enrich_run_core.php';

cw_require_admin();

header('Content-Type: application/json; charset=utf-8');

$raw = file_get_contents('php://input');
$data = json_decode($raw !== false ? $raw : '', true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid JSON']);
    exit;
}

$slideId = (int)($data['slide_id'] ?? 0);
$step = trim((string)($data['step'] ?? ''));
$allowed = ['narration_en', 'narration_es', 'refs'];
if ($slideId <= 0 || !in_array($step, $allowed, true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'slide_id and step (narration_en|narration_es|refs) required']);
    exit;
}

$useRl = !empty($data['use_resource_library']);
$rlRequested = (int)($data['resource_library_edition_id'] ?? 0);
$rlEdition = $useRl ? rl_enrich_resolve_edition_id($pdo, $rlRequested > 0 ? $rlRequested : null) : 0;

try {
    if ($step === 'narration_en') {
        $bundle = bulk_enrich_core_vision_bundle_slide($pdo, $CDN_BASE, $slideId, $rlEdition);
        $narrEn = $bundle['narration_script_en'];
        if ($narrEn === '') {
            throw new RuntimeException('Vision returned empty narration EN');
        }
        $stmt = $pdo->prepare('SELECT narration_es FROM slide_enrichment WHERE slide_id=? LIMIT 1');
        $stmt->execute([$slideId]);
        $keepEs = trim((string)$stmt->fetchColumn());
        bulk_enrich_core_set_narration($pdo, $slideId, $narrEn, $keepEs);
        echo json_encode(['ok' => true, 'narration_en' => $narrEn], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($step === 'narration_es') {
        $stmt = $pdo->prepare('SELECT narration_en FROM slide_enrichment WHERE slide_id=? LIMIT 1');
        $stmt->execute([$slideId]);
        $nEn = trim((string)$stmt->fetchColumn());
        if ($nEn === '') {
            throw new RuntimeException('No English narration in database; run English Narration first or paste EN text and Save.');
        }
        $nEs = bulk_enrich_core_ai_translate_es($nEn);
        bulk_enrich_core_set_narration($pdo, $slideId, $nEn, $nEs);
        echo json_encode(['ok' => true, 'narration_es' => $nEs], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($step === 'refs') {
        $bundle = bulk_enrich_core_vision_bundle_slide($pdo, $CDN_BASE, $slideId, $rlEdition);
        bulk_enrich_core_replace_refs($pdo, $slideId, $bundle['phak'], $bundle['acs']);
        $phakN = is_array($bundle['phak']) ? count($bundle['phak']) : 0;
        $acsN = is_array($bundle['acs']) ? count($bundle['acs']) : 0;
        echo json_encode([
            'ok' => true,
            'phak_count' => $phakN,
            'acs_count' => $acsN,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
