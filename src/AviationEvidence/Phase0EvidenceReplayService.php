<?php
declare(strict_types=1);

require_once __DIR__ . '/EvidenceSchema.php';
require_once __DIR__ . '/ProviderRunPersister.php';
require_once __DIR__ . '/Phase0PersistenceVerifier.php';
require_once __DIR__ . '/Phase0InvestigationService.php';
require_once __DIR__ . '/../CockpitRecorderService.php';

final class Phase0EvidenceReplayService
{
    /**
     * Replay typed persistence from existing filesystem evidence (no OpenAI calls).
     *
     * @return array<string,mixed>
     */
    public static function replayDirectory(PDO $pdo, string $dir, ?string $reportBasename = null): array
    {
        $absDir = str_starts_with($dir, '/') ? $dir : CockpitRecorderService::projectRoot() . '/' . ltrim($dir, '/');
        if (!is_dir($absDir)) {
            return array('ok' => false, 'error' => 'Evidence directory not found: ' . $absDir);
        }

        $reportPath = null;
        if (is_string($reportBasename) && $reportBasename !== '') {
            $candidate = $absDir . '/' . ltrim($reportBasename, '/');
            if (is_file($candidate)) {
                $reportPath = $candidate;
            }
        }
        if ($reportPath === null) {
            $reports = glob($absDir . '/recording_*_report.json') ?: array();
            if ($reports === array()) {
                return array('ok' => false, 'error' => 'No recording_*_report.json files in ' . $absDir);
            }
            rsort($reports);
            $reportPath = $reports[0];
        }

        $report = json_decode((string)file_get_contents($reportPath), true);
        if (!is_array($report)) {
            return array('ok' => false, 'error' => 'Invalid report JSON: ' . $reportPath);
        }

        $recordingId = (int)($report['recording_id'] ?? 0);
        $chunkIndex = (int)($report['chunk_index'] ?? 0);
        $probeExecutionUuid = Phase0InvestigationService::generateUuid();

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
            $httpCode = 400;
            if ($label === 'production_json' || $label === 'whisper1_verbose_json') {
                $httpCode = isset($rawJson['error']) ? 400 : 200;
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
            return array('ok' => false, 'error' => 'No raw JSON evidence files found beside report.', 'report_path' => $reportPath);
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
}
