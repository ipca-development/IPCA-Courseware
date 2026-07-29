<?php
declare(strict_types=1);

require_once __DIR__ . '/EvidenceSchema.php';
require_once __DIR__ . '/ProviderRunRepository.php';
require_once __DIR__ . '/ProviderObservationRepository.php';

final class Phase0PersistenceVerifier
{
    private const FORBIDDEN_SECRET_PATTERNS = array(
        '/sk-[A-Za-z0-9]{10,}/',
        '/Bearer\s+[A-Za-z0-9._\-]+/i',
        '/Authorization/i',
    );

    /** Interpretation-like observation keys that must not appear. */
    private const FORBIDDEN_OBSERVATION_KEYS = array(
        'probable_hallucination',
        'repetition_loop',
        'poor_speech_quality',
        'usable_timestamp_source',
        'inferred_conclusion',
        'loop_detected',
    );

    public function __construct(
        private readonly PDO $pdo,
        private readonly ProviderRunRepository $providerRuns,
        private readonly ProviderObservationRepository $observations,
    ) {
    }

    /**
     * @param array<string,string>|null $filesystemEvidencePaths label => path
     * @return array<string,mixed>
     */
    public function verifyProbeExecution(string $probeExecutionUuid, ?array $filesystemEvidencePaths = null): array
    {
        EvidenceSchema::requirePersistenceReady($this->pdo);

        $runs = $this->providerRuns->listByProbeExecutionUuid($probeExecutionUuid);
        $checks = array();
        $ok = true;

        $checks[] = $this->checkRunCount($runs);
        $checks[] = $this->checkLabelPresent($runs, 'production_json');
        $checks[] = $this->checkLabelPresent($runs, 'production_verbose_json');
        $checks[] = $this->checkLabelPresent($runs, 'whisper1_verbose_json');

        foreach ($runs as $run) {
            $label = (string)($run['probe_label'] ?? '');
            $runId = (int)$run['id'];
            $segmentCount = $this->providerRuns->countSegments($runId);
            $wordCount = $this->providerRuns->countWordsForProviderRun($runId);
            $obsRows = $this->observations->listForProviderRun($runId);

            if ($label === 'production_json') {
                $checks[] = self::result(
                    'production_json_zero_segments',
                    $segmentCount === 0,
                    'Expected 0 segment rows for production_json',
                    array('segment_count' => $segmentCount)
                );
                $checks[] = self::result(
                    'production_json_success',
                    (int)($run['http_status'] ?? 0) >= 200 && (int)($run['http_status'] ?? 0) < 300,
                    'Expected successful production_json HTTP status',
                    array('http_status' => (int)($run['http_status'] ?? 0))
                );
            }

            if ($label === 'production_verbose_json') {
                $checks[] = self::result(
                    'production_verbose_json_failed',
                    (int)($run['http_status'] ?? 0) === 400,
                    'Expected HTTP 400 for production_verbose_json rejection',
                    array('http_status' => (int)($run['http_status'] ?? 0))
                );
                $checks[] = self::result(
                    'production_verbose_json_zero_segments',
                    $segmentCount === 0,
                    'Expected 0 segment rows for rejected verbose_json probe',
                    array('segment_count' => $segmentCount)
                );
            }

            if ($label === 'whisper1_verbose_json') {
                $checks[] = self::result(
                    'whisper1_segment_count',
                    $segmentCount === 71,
                    'Expected 71 segment rows for whisper1_verbose_json',
                    array('segment_count' => $segmentCount)
                );
                $checks[] = self::result(
                    'whisper1_zero_words',
                    $wordCount === 0,
                    'Expected 0 word rows (word timestamps unconfirmed in probe)',
                    array('word_count' => $wordCount)
                );
            }

            $checks[] = $this->checkNoSecretsInRun($run);
            $checks[] = $this->checkObservationsDirectOnly($obsRows);
            $checks[] = $this->checkFilesystemHashMatch($run, $filesystemEvidencePaths, $label);
        }

        foreach ($checks as $check) {
            if (empty($check['ok'])) {
                $ok = false;
            }
        }

        return array(
            'ok' => $ok,
            'probe_execution_uuid' => $probeExecutionUuid,
            'provider_run_count' => count($runs),
            'schema_version' => EvidenceSchema::currentSchemaVersion($this->pdo),
            'checks' => $checks,
            'provider_runs' => array_map(static function (array $run): array {
                return array(
                    'id' => (int)$run['id'],
                    'probe_label' => (string)($run['probe_label'] ?? ''),
                    'http_status' => (int)($run['http_status'] ?? 0),
                    'response_sha256' => (string)($run['response_sha256'] ?? ''),
                    'openai_request_id' => $run['openai_request_id'] ?? null,
                    'segment_count' => null,
                );
            }, $runs),
            'verification_sql' => self::documentedVerificationSql(),
        );
    }

    /**
     * @param list<array<string,mixed>> $runs
     * @return array<string,mixed>
     */
    private function checkRunCount(array $runs): array
    {
        return self::result(
            'three_provider_runs',
            count($runs) === 3,
            'Expected exactly 3 provider runs per Phase 0 probe execution',
            array('actual' => count($runs))
        );
    }

    /**
     * @param list<array<string,mixed>> $runs
     * @return array<string,mixed>
     */
    private function checkLabelPresent(array $runs, string $label): array
    {
        $found = false;
        foreach ($runs as $run) {
            if ((string)($run['probe_label'] ?? '') === $label) {
                $found = true;
                break;
            }
        }
        return self::result(
            'label_' . $label,
            $found,
            'Expected provider run with probe_label=' . $label,
            array('found' => $found)
        );
    }

    /**
     * @param array<string,mixed> $run
     * @return array<string,mixed>
     */
    private function checkNoSecretsInRun(array $run): array
    {
        $haystack = json_encode(array(
            'request_config_json' => $run['request_config_json'] ?? '',
            'raw_response_json' => $run['raw_response_json'] ?? '',
            'prompt_text' => $run['prompt_text'] ?? '',
        ), JSON_UNESCAPED_SLASHES) ?: '';

        foreach (self::FORBIDDEN_SECRET_PATTERNS as $pattern) {
            if (preg_match($pattern, $haystack)) {
                return self::result(
                    'no_secrets_run_' . (int)$run['id'],
                    false,
                    'Secret or authorization material detected in persisted provider run',
                    array('provider_run_id' => (int)$run['id'])
                );
            }
        }

        return self::result(
            'no_secrets_run_' . (int)$run['id'],
            true,
            'No API key or authorization header persisted',
            array('provider_run_id' => (int)$run['id'])
        );
    }

    /**
     * @param list<array<string,mixed>> $obsRows
     * @return array<string,mixed>
     */
    private function checkObservationsDirectOnly(array $obsRows): array
    {
        $runId = (int)($obsRows[0]['provider_run_id'] ?? 0);
        foreach ($obsRows as $row) {
            $key = strtolower((string)($row['observation_key'] ?? ''));
            if (in_array($key, self::FORBIDDEN_OBSERVATION_KEYS, true)) {
                return self::result(
                    'direct_observations_only_' . $runId,
                    false,
                    'Interpretation-like observation key persisted: ' . $key,
                    array('provider_run_id' => $runId, 'observation_key' => $key)
                );
            }
        }
        return self::result(
            'direct_observations_only_' . $runId,
            true,
            'Observations contain direct provider facts only',
            array('provider_run_id' => $runId, 'observation_count' => count($obsRows))
        );
    }

    /**
     * @param array<string,mixed> $run
     * @param array<string,string>|null $filesystemEvidencePaths
     * @return array<string,mixed>
     */
    private function checkFilesystemHashMatch(array $run, ?array $filesystemEvidencePaths, string $label): array
    {
        $runId = (int)$run['id'];
        if ($filesystemEvidencePaths === null) {
            return self::result(
                'filesystem_hash_run_' . $runId,
                true,
                'Filesystem hash comparison skipped (no paths supplied)',
                array('provider_run_id' => $runId)
            );
        }

        $path = $filesystemEvidencePaths[$label] ?? null;
        if (!is_string($path) || !is_file($path)) {
            return self::result(
                'filesystem_hash_run_' . $runId,
                true,
                'Filesystem raw JSON not available for hash comparison',
                array('provider_run_id' => $runId, 'label' => $label)
            );
        }

        $fileContents = (string)file_get_contents($path);
        $fileHash = self::canonicalJsonHash($fileContents);
        $dbHash = self::canonicalJsonHash((string)($run['raw_response_json'] ?? ''));

        return self::result(
            'filesystem_hash_run_' . $runId,
            $fileHash !== '' && $fileHash === $dbHash,
            'Canonical raw response hash must match filesystem evidence file',
            array(
                'provider_run_id' => $runId,
                'label' => $label,
                'filesystem_canonical_hash' => $fileHash,
                'database_canonical_hash' => $dbHash,
                'database_response_sha256' => (string)($run['response_sha256'] ?? ''),
                'path' => $path,
            )
        );
    }

    private static function canonicalJsonHash(string $jsonString): string
    {
        if ($jsonString === '') {
            return '';
        }
        $decoded = json_decode($jsonString, true);
        if (!is_array($decoded)) {
            return hash('sha256', $jsonString);
        }
        return hash('sha256', json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<string,mixed> $details
     * @return array<string,mixed>
     */
    private static function result(string $name, bool $ok, string $message, array $details = array()): array
    {
        return array(
            'name' => $name,
            'ok' => $ok,
            'message' => $message,
            'details' => $details,
        );
    }

    /**
     * @return list<string>
     */
    public static function documentedVerificationSql(): array
    {
        return array(
            "-- Provider runs for a probe execution\n"
            . "SELECT id, probe_label, http_status, success_status, response_sha256, openai_request_id\n"
            . "FROM ipca_evidence_provider_runs\n"
            . "WHERE probe_execution_uuid = :probe_execution_uuid\n"
            . "ORDER BY id;",
            "-- Segment counts per provider run\n"
            . "SELECT r.id, r.probe_label, COUNT(s.id) AS segment_count\n"
            . "FROM ipca_evidence_provider_runs r\n"
            . "LEFT JOIN ipca_evidence_provider_segments s ON s.provider_run_id = r.id\n"
            . "WHERE r.probe_execution_uuid = :probe_execution_uuid\n"
            . "GROUP BY r.id, r.probe_label\n"
            . "ORDER BY r.id;",
            "-- Direct observations (must not include interpretation keys)\n"
            . "SELECT o.provider_run_id, r.probe_label, o.observation_key, o.observation_type\n"
            . "FROM ipca_evidence_provider_observations o\n"
            . "INNER JOIN ipca_evidence_provider_runs r ON r.id = o.provider_run_id\n"
            . "WHERE r.probe_execution_uuid = :probe_execution_uuid\n"
            . "ORDER BY o.provider_run_id, o.observation_key;",
            "-- Secret leak check (should return zero rows)\n"
            . "SELECT id, probe_label FROM ipca_evidence_provider_runs\n"
            . "WHERE probe_execution_uuid = :probe_execution_uuid\n"
            . "  AND (request_config_json LIKE '%sk-%' OR raw_response_json LIKE '%sk-%' OR request_config_json LIKE '%Bearer %');",
        );
    }

    public static function fromPdo(PDO $pdo): self
    {
        return new self(
            $pdo,
            new ProviderRunRepository($pdo),
            new ProviderObservationRepository($pdo),
        );
    }
}
