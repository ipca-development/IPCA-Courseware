<?php
declare(strict_types=1);

/**
 * Phase 0 evidence platform verification — schema, migration, and persistence checks.
 *
 * Usage:
 *   php scripts/diagnostics/cockpit_transcript_phase0_verify_all.php
 *   php scripts/diagnostics/cockpit_transcript_phase0_verify_all.php --apply-migrations
 *   php scripts/diagnostics/cockpit_transcript_phase0_verify_all.php --probe-execution-uuid=UUID
 *   php scripts/diagnostics/cockpit_transcript_phase0_verify_all.php --replay-evidence-dir=/path/to/phase0_evidence
 */
require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/CockpitRecorderService.php';
require_once __DIR__ . '/../../src/AviationEvidence/EvidenceSchema.php';
require_once __DIR__ . '/../../src/AviationEvidence/Phase0InvestigationService.php';
require_once __DIR__ . '/../../src/AviationEvidence/ProviderRunPersister.php';
require_once __DIR__ . '/../../src/AviationEvidence/Phase0PersistenceVerifier.php';

function verify_arg(string $name, ?string $default = null): ?string
{
    global $argv;
    $prefix = '--' . $name . '=';
    foreach ($argv ?? array() as $arg) {
        if (str_starts_with($arg, $prefix)) {
            return substr($arg, strlen($prefix));
        }
        if ($arg === '--' . $name) {
            return '1';
        }
    }
    return $default;
}

function verify_check(string $name, bool $ok, string $message, array $details = array()): array
{
    return array('name' => $name, 'ok' => $ok, 'message' => $message, 'details' => $details);
}

/**
 * @return list<array<string,mixed>>
 */
function verify_schema(PDO $pdo): array
{
    $checks = array();
    $requiredTables = array_merge(
        EvidenceSchema::persistenceRequiredTables(),
        array(
            EvidenceSchema::TABLE_MODEL_CAPABILITIES,
            EvidenceSchema::TABLE_SCHEMA_VERSIONS,
            EvidenceSchema::TABLE_PROVIDER_WORDS,
        )
    );

    foreach ($requiredTables as $table) {
        $checks[] = verify_check(
            'table_' . $table,
            EvidenceSchema::tablePresent($pdo, $table),
            'Table exists: ' . $table
        );
    }

    $requiredColumns = array(
        'probe_execution_uuid', 'probe_label', 'prompt_hash', 'returned_text',
        'chunk_audio_sha256', 'success_status', 'error_type', 'latency_ms',
        'capability_observations_json', 'evidence_files_json', 'code_version',
        'matching_response_provider_run_id',
    );
    foreach ($requiredColumns as $col) {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()'
            . ' AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $stmt->execute(array(EvidenceSchema::TABLE_PROVIDER_RUNS, $col));
        $checks[] = verify_check(
            'column_' . $col,
            (int)$stmt->fetchColumn() > 0,
            'Column ipca_evidence_provider_runs.' . $col . ' exists'
        );
    }

    $schemaVersion = EvidenceSchema::currentSchemaVersion($pdo);
    $checks[] = verify_check(
        'schema_version_row',
        is_array($schemaVersion),
        'Schema version registry has at least one row',
        is_array($schemaVersion) ? array('version' => $schemaVersion['version'] ?? null) : array()
    );

    return $checks;
}

/**
 * @return array<string,mixed>
 */
function apply_migrations(PDO $pdo): array
{
    $files = array(
        EvidenceSchema::MIGRATION_FILE,
        'scripts/sql/2026_07_30_aviation_evidence_platform_probe_persistence.sql',
    );
    $results = array();
    foreach ($files as $file) {
        $path = CockpitRecorderService::projectRoot() . '/' . $file;
        if (!is_file($path)) {
            $results[] = array('file' => $file, 'ok' => false, 'error' => 'File not found');
            continue;
        }
        $sql = (string)file_get_contents($path);
        $started = gmdate('c');
        try {
            $pdo->exec($sql);
            $results[] = array('file' => $file, 'ok' => true, 'started_at' => $started, 'completed_at' => gmdate('c'));
        } catch (Throwable $e) {
            $results[] = array('file' => $file, 'ok' => false, 'error' => $e->getMessage(), 'started_at' => $started);
        }
    }
    return array('ok' => !in_array(false, array_column($results, 'ok'), true), 'migrations' => $results);
}

/**
 * Replay typed persistence from existing filesystem evidence (no OpenAI calls).
 *
 * @return array<string,mixed>
 */
function replay_evidence_dir(PDO $pdo, string $dir): array
{
    $absDir = str_starts_with($dir, '/') ? $dir : CockpitRecorderService::projectRoot() . '/' . ltrim($dir, '/');
    if (!is_dir($absDir)) {
        return array('ok' => false, 'error' => 'Evidence directory not found: ' . $absDir);
    }

    $reports = glob($absDir . '/recording_*_report.json') ?: array();
    if ($reports === array()) {
        return array('ok' => false, 'error' => 'No recording_*_report.json files in ' . $absDir);
    }
    rsort($reports);
    $reportPath = $reports[0];
    $report = json_decode((string)file_get_contents($reportPath), true);
    if (!is_array($report)) {
        return array('ok' => false, 'error' => 'Invalid report JSON: ' . $reportPath);
    }

    $recordingId = (int)($report['recording_id'] ?? 0);
    $chunkIndex = (int)($report['chunk_index'] ?? 0);
    $probeExecutionUuid = (string)($report['probe_execution_uuid'] ?? '');
    if ($probeExecutionUuid === '') {
        $probeExecutionUuid = Phase0InvestigationService::generateUuid();
    }

    $chunkAudio = is_array($report['chunk_audio'] ?? null) ? $report['chunk_audio'] : array();
    $sourceSha = (string)($chunkAudio['source_sha256'] ?? '');
    $chunkSha = (string)($chunkAudio['chunk_sha256'] ?? $sourceSha);
    $startMs = (int)round(((float)($chunkAudio['chunk_start_seconds'] ?? 0)) * 1000.0);
    $durationMs = (int)round(((float)($chunkAudio['chunk_duration_seconds'] ?? 300)) * 1000.0);

    $base = preg_replace('/_report\.json$/', '', $reportPath) ?? $reportPath;
    $labels = array('production_json', 'production_verbose_json', 'whisper1_verbose_json');
    $probeRuns = array();
    $evidenceFiles = array('report' => $reportPath);

    foreach ($labels as $label) {
        $rawPath = $base . '_' . $label . '_raw.json';
        if (!is_file($rawPath)) {
            continue;
        }
        $rawJson = json_decode((string)file_get_contents($rawPath), true);
        if (!is_array($rawJson)) {
            continue;
        }
        $evidenceFiles[$label] = $rawPath;
        $httpCode = isset($rawJson['error']) ? 400 : 200;
        if ($label === 'production_verbose_json') {
            $httpCode = 400;
        }
        $probeRuns[] = array(
            'ok' => $httpCode >= 200 && $httpCode < 300,
            'label' => $label,
            'http_code' => $httpCode,
            'request' => array(
                'provider' => 'openai',
                'model' => $label === 'whisper1_verbose_json' ? 'whisper-1' : (getenv('CW_OPENAI_ASR_MODEL') ?: 'gpt-4o-transcribe'),
                'response_format' => $label === 'production_json' ? 'json' : 'verbose_json',
                'language_forced' => true,
                'language' => 'en',
                'timestamp_granularities_requested' => $label !== 'production_json' ? array('word', 'segment') : array(),
                'previous_text_conditioning' => false,
                'prompt_supplied' => '',
                'match_production_request' => $label !== 'whisper1_verbose_json',
            ),
            'response' => array(
                'openai_request_id' => null,
                'observed_fields' => array(
                    'text' => isset($rawJson['text']),
                    'segments' => is_array($rawJson['segments'] ?? null) && count($rawJson['segments']) > 0,
                ),
                'segment_count' => is_array($rawJson['segments'] ?? null) ? count($rawJson['segments']) : 0,
                'word_timestamp_count' => 0,
            ),
            'raw_provider_text' => trim((string)($rawJson['text'] ?? '')),
            'error' => is_array($rawJson['error'] ?? null) ? ($rawJson['error']['message'] ?? null) : null,
            'raw_json' => $rawJson,
            'request_started_at' => gmdate('Y-m-d H:i:s') . '.000',
            'request_completed_at' => gmdate('Y-m-d H:i:s') . '.000',
            'latency_ms' => 0,
        );
    }

    if ($probeRuns === array()) {
        return array('ok' => false, 'error' => 'No raw JSON evidence files found beside report.');
    }

    EvidenceSchema::requirePersistenceReady($pdo);
    $persister = ProviderRunPersister::fromPdo($pdo);
    $result = $persister->persistProbeExecution(
        $recordingId,
        $chunkIndex,
        $probeExecutionUuid,
        $sourceSha,
        $chunkSha,
        $startMs,
        $durationMs,
        isset($chunkAudio['chunk_byte_length']) ? (int)$chunkAudio['chunk_byte_length'] : null,
        $probeRuns,
        $evidenceFiles
    );

    $verifier = Phase0PersistenceVerifier::fromPdo($pdo);
    $verification = $verifier->verifyProbeExecution($probeExecutionUuid, $evidenceFiles);

    return array(
        'ok' => !empty($verification['ok']),
        'mode' => 'replay_evidence',
        'report_path' => $reportPath,
        'probe_execution_uuid' => $probeExecutionUuid,
        'persistence' => $result,
        'verification' => $verification,
    );
}

// --- main ---

$checks = array();
$overallOk = true;

if (verify_arg('apply-migrations') !== null) {
    $migrationResult = apply_migrations($pdo);
    $checks[] = verify_check('apply_migrations', !empty($migrationResult['ok']), 'Migration apply', $migrationResult);
    if (empty($migrationResult['ok'])) {
        $overallOk = false;
    }
}

$checks = array_merge($checks, verify_schema($pdo));
foreach ($checks as $check) {
    if (empty($check['ok'])) {
        $overallOk = false;
    }
}

$replayDir = verify_arg('replay-evidence-dir', null);
if (is_string($replayDir) && $replayDir !== '') {
    $replay = replay_evidence_dir($pdo, $replayDir);
    $checks[] = verify_check('replay_evidence', !empty($replay['ok']), 'Replay evidence persistence', $replay);
    if (empty($replay['ok'])) {
        $overallOk = false;
    }
}

$probeUuid = verify_arg('probe-execution-uuid', null);
if (is_string($probeUuid) && trim($probeUuid) !== '') {
    $service = new Phase0InvestigationService($pdo, new CockpitRecorderService($pdo));
    $verification = $service->verifyProbePersistence(trim($probeUuid));
    $checks[] = verify_check('probe_persistence_verification', !empty($verification['ok']), 'Probe execution verification', $verification);
    if (empty($verification['ok'])) {
        $overallOk = false;
    }
}

// List recent probe executions if column exists
$recentProbes = array();
try {
    $recentProbes = $pdo->query(
        "SELECT probe_execution_uuid, COUNT(*) AS provider_run_count,"
        . " MIN(created_at) AS first_at, MAX(created_at) AS last_at"
        . " FROM ipca_evidence_provider_runs"
        . " WHERE run_purpose = 'phase0_mandatory_probe' AND probe_execution_uuid IS NOT NULL"
        . " GROUP BY probe_execution_uuid ORDER BY last_at DESC LIMIT 5"
    )->fetchAll(PDO::FETCH_ASSOC) ?: array();
} catch (Throwable $e) {
    $recentProbes = array(array('error' => $e->getMessage()));
}

$payload = array(
    'ok' => $overallOk,
    'generated_at' => gmdate('c'),
    'schema_ready' => EvidenceSchema::persistenceReady($pdo),
    'schema_version' => EvidenceSchema::currentSchemaVersion($pdo),
    'checks' => $checks,
    'recent_probe_executions' => $recentProbes,
    'next_steps' => array(),
);

if (!$overallOk && !EvidenceSchema::persistenceReady($pdo)) {
    $payload['next_steps'][] = 'Run: php scripts/diagnostics/cockpit_transcript_phase0_verify_all.php --apply-migrations';
}
if ($overallOk && $recentProbes === array()) {
    $payload['next_steps'][] = 'On App Platform (audio + OpenAI key): php scripts/diagnostics/cockpit_transcript_phase0_provider_probe.php --recording-id=552 --probe-chunk=0 --persist=1';
    $payload['next_steps'][] = 'Or replay existing evidence: php scripts/diagnostics/cockpit_transcript_phase0_verify_all.php --replay-evidence-dir=storage/cockpit_recorder/phase0_evidence';
}

echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($overallOk ? 0 : 1);
