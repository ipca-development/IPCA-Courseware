<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/openai.php';
require_once __DIR__ . '/../../../src/ecfr_api_client.php';
require_once __DIR__ . '/../../../src/resource_library_catalog.php';
require_once __DIR__ . '/../../../src/easa_erules_storage.php';
require_once __DIR__ . '/../../../src/easa_download_monitor.php';

cw_require_admin();

header('Content-Type: application/json; charset=utf-8');

function rl_easa_json_out(int $code, array $payload): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function rl_easa_extract_ai_text(array $resp): string
{
    if (!empty($resp['output_text']) && is_string($resp['output_text'])) {
        return trim($resp['output_text']);
    }
    $out = $resp['output'] ?? [];
    if (!is_array($out)) {
        return '';
    }
    $text = '';
    foreach ($out as $item) {
        if (!is_array($item)) {
            continue;
        }
        $content = $item['content'] ?? [];
        if (!is_array($content)) {
            continue;
        }
        foreach ($content as $c) {
            if (is_array($c) && ($c['type'] ?? '') === 'output_text') {
                $text .= (string) ($c['text'] ?? '');
            }
        }
    }

    return trim($text);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $action = trim((string) ($_GET['action'] ?? 'status'));
    if ($action !== 'status') {
        rl_easa_json_out(400, ['ok' => false, 'error' => 'Unknown action']);
    }

    $tablesOk = easa_download_monitor_tables_ok($pdo);
    $monitor = [];
    $batches = [];
    if ($tablesOk) {
        $monitor = $pdo->query('SELECT id, url, label, checked_at, http_status, final_url, etag, last_modified, content_length, changed_flag, last_error FROM easa_download_monitor ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $batches = $pdo->query('SELECT id, status, original_filename, file_sha256, file_size, rows_detected, created_at, updated_at, error_message FROM easa_erules_import_batches ORDER BY id DESC LIMIT 25')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    rl_easa_json_out(200, [
        'ok' => true,
        'tables_ok' => $tablesOk,
        'migrate_hint' => $tablesOk ? null : 'Apply scripts/sql/resource_library_easa_erules.sql',
        'indexed_nodes' => 0,
        'indexed_hint' => 'Canonical regulatory_nodes + search chunks will appear after the XML ingest pipeline is enabled.',
        'monitor' => $monitor,
        'batches' => $batches,
        'ecfr_configured' => rl_catalog_resolve_ecfr_training_report_edition($pdo) !== null,
    ]);
}

if ($method !== 'POST') {
    rl_easa_json_out(405, ['ok' => false, 'error' => 'Method not allowed']);
}

$contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? ''));

if (str_contains($contentType, 'multipart/form-data')) {
    if (!easa_download_monitor_tables_ok($pdo)) {
        rl_easa_json_out(503, ['ok' => false, 'error' => 'Apply scripts/sql/resource_library_easa_erules.sql first']);
    }
    if (!isset($_FILES['erules_xml']) || !is_array($_FILES['erules_xml'])) {
        rl_easa_json_out(400, ['ok' => false, 'error' => 'Missing file field erules_xml']);
    }
    $err = (int) ($_FILES['erules_xml']['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err !== UPLOAD_ERR_OK) {
        rl_easa_json_out(400, ['ok' => false, 'error' => 'Upload error code ' . $err]);
    }
    $tmp = (string) ($_FILES['erules_xml']['tmp_name'] ?? '');
    $orig = (string) ($_FILES['erules_xml']['name'] ?? 'export.xml');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        rl_easa_json_out(400, ['ok' => false, 'error' => 'Invalid upload']);
    }
    $raw = file_get_contents($tmp);
    if ($raw === false) {
        rl_easa_json_out(400, ['ok' => false, 'error' => 'Could not read upload']);
    }
    $sha = hash('sha256', $raw);
    $uid = cw_current_user($pdo);
    $userId = is_array($uid) ? (int) ($uid['id'] ?? 0) : 0;

    $pdo->beginTransaction();
    try {
        $ins = $pdo->prepare('
            INSERT INTO easa_erules_import_batches (status, original_filename, file_sha256, storage_relpath, file_size, uploaded_by_user_id)
            VALUES (\'uploaded\', ?, ?, \'\', ?, ?)
        ');
        $ins->execute([$orig, $sha, strlen($raw), $userId > 0 ? $userId : null]);
        $batchId = (int) $pdo->lastInsertId();
        if ($batchId <= 0) {
            throw new RuntimeException('Could not create batch row');
        }
        $stored = easa_erules_store_batch_upload($batchId, $tmp);
        $rel = $stored['relpath'];
        $pdo->prepare('UPDATE easa_erules_import_batches SET storage_relpath = ?, file_size = ? WHERE id = ?')->execute([$rel, $stored['size'], $batchId]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        rl_easa_json_out(500, ['ok' => false, 'error' => $e->getMessage()]);
    }

    rl_easa_json_out(200, [
        'ok' => true,
        'batch_id' => $batchId,
        'sha256' => $sha,
        'message' => 'File stored as official evidence. Staging parse & approval UI will process this batch next.',
    ]);
}

$rawIn = file_get_contents('php://input');
$data = json_decode((string) $rawIn, true);
if (!is_array($data)) {
    rl_easa_json_out(400, ['ok' => false, 'error' => 'Invalid JSON']);
}

$action = trim((string) ($data['action'] ?? ''));

if ($action === 'probe_monitor') {
    if (!easa_download_monitor_tables_ok($pdo)) {
        rl_easa_json_out(503, ['ok' => false, 'error' => 'Apply scripts/sql/resource_library_easa_erules.sql']);
    }
    $res = easa_download_monitor_probe_all($pdo);
    rl_easa_json_out(200, ['ok' => true, 'probed' => $res['probed'], 'errors' => $res['errors']]);
}

if ($action === 'search') {
    $q = trim((string) ($data['query'] ?? ''));
    if ($q === '') {
        rl_easa_json_out(400, ['ok' => false, 'error' => 'query required']);
    }
    rl_easa_json_out(200, [
        'ok' => true,
        'hits' => [],
        'hit_count' => 0,
        'note' => 'Indexed EASA chunks are not deployed yet. After ingest + publish, results will appear here (FULLTEXT / semantic).',
    ]);
}

if ($action === 'regulatory_compare_ai') {
    $q = trim((string) ($data['query'] ?? ''));
    if ($q === '') {
        rl_easa_json_out(400, ['ok' => false, 'error' => 'query required']);
    }
    $useAi = !empty($data['use_ai']);
    $includeEcfr = !empty($data['include_ecfr']);
    $titleNum = (int) ($data['ecfr_title_number'] ?? 14);
    $section = trim((string) ($data['ecfr_section'] ?? ''));

    $easaCtx = 'No EASA Easy Access Rules excerpts are indexed in this database yet. Upload official XML via this center, then run the ingest pipeline when available. Always verify binding law on EASA and national portals; this assistant is not legal advice.';

    $ecfrHtml = '';
    $ecfrNote = '';
    if ($includeEcfr) {
        if ($section === '') {
            $ecfrNote = 'Include U.S. eCFR: provide ecfr_section (e.g. 61.105).';
        } else {
            try {
                $cfg = rl_catalog_ecfr_runtime_config($pdo);
                $client = new EcfrApiClient($cfg['api_base_url']);
                $snap = $client->resolveTitleSnapshotDate($titleNum > 0 ? $titleNum : 14);
                $xml = $client->fetchSectionXml($titleNum > 0 ? $titleNum : 14, $section, $snap);
                $ecfrHtml = $client->sectionXmlToHtml($xml);
                $browse = $client->sectionBrowseUrl($titleNum > 0 ? $titleNum : 14, $section);
                $ecfrNote = 'Official excerpt via eCFR versioner API · snapshot ' . $snap . ' · Browse: ' . $browse;
            } catch (Throwable $e) {
                $ecfrNote = 'eCFR fetch failed: ' . $e->getMessage();
            }
        }
    }

    $payload = [
        'ok' => true,
        'easa_context_note' => $easaCtx,
        'ecfr_html' => $ecfrHtml !== '' ? $ecfrHtml : null,
        'ecfr_note' => $ecfrNote !== '' ? $ecfrNote : null,
        'ai_answer' => '',
        'ai_error' => null,
    ];

    if (!$useAi) {
        rl_easa_json_out(200, $payload);
    }

    $bundle = "EU / EASA context (indexed excerpts):\n" . $easaCtx . "\n\n";
    if ($ecfrHtml !== '') {
        $strip = preg_replace('/\s+/', ' ', strip_tags($ecfrHtml));
        $strip = is_string($strip) ? trim($strip) : '';
        if (strlen($strip) > 12000) {
            $strip = substr($strip, 0, 12000) . '…';
        }
        $bundle .= "U.S. 14 CFR excerpt (eCFR API, for comparison only):\n" . $strip . "\n\n";
    }

    try {
        $resp = cw_openai_responses([
            'model' => cw_openai_model(),
            'input' => [
                [
                    'role' => 'system',
                    'content' => [
                        [
                            'type' => 'input_text',
                            'text' => 'You help aviation compliance staff compare regulatory concepts. The EU side may lack indexed excerpts in this installation—say so when true. When U.S. text is provided from eCFR, label it clearly as U.S. 14 CFR and do not conflate with EASA. Never replace official sources; cite what jurisdiction each paragraph belongs to. Not legal advice.',
                        ],
                    ],
                ],
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'input_text',
                            'text' => "Question:\n" . $q . "\n\nReference bundle:\n" . $bundle,
                        ],
                    ],
                ],
            ],
        ], 120);
        $payload['ai_answer'] = rl_easa_extract_ai_text($resp);
    } catch (Throwable $e) {
        $payload['ai_error'] = $e->getMessage();
    }

    rl_easa_json_out(200, $payload);
}

rl_easa_json_out(400, ['ok' => false, 'error' => 'Unknown action']);
