<?php
declare(strict_types=1);

require_once __DIR__ . '/AuditEventService.php';
require_once __DIR__ . '/CockpitRecorderService.php';

final class CvrLiveAudioSegmentService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<string,mixed> */
    public function receive(array $metadata, string $audioBytes, array $device): array
    {
        $recordingUid = $this->identifier((string)($metadata['recording_uid'] ?? ''), 96);
        $operationalSessionUuid = $this->uuid((string)($metadata['operational_session_uuid'] ?? ''));
        $flightRecordUuid = $this->uuid((string)($metadata['workflow_flight_record_uuid'] ?? ''));
        $segmentIndex = (int)($metadata['segment_index'] ?? 0);
        $startedAt = $this->dateTime((string)($metadata['started_at'] ?? ''));
        $duration = (float)($metadata['duration_seconds'] ?? 0);
        $expectedSha = strtolower(trim((string)($metadata['sha256'] ?? '')));
        $language = CockpitRecorderService::normalizeLanguage((string)($metadata['language'] ?? 'en'));
        if ($recordingUid === '' || $segmentIndex <= 0 || $duration <= 0 || $audioBytes === '') {
            throw new InvalidArgumentException('Complete live audio segment metadata and audio are required.');
        }
        if (!preg_match('/^[a-f0-9]{64}$/', $expectedSha)) {
            throw new InvalidArgumentException('A valid audio SHA-256 is required.');
        }
        $actualSha = hash('sha256', $audioBytes);
        if (!hash_equals($expectedSha, $actualSha)) {
            throw new InvalidArgumentException('Live audio segment SHA-256 does not match its content.');
        }
        if (strlen($audioBytes) > 16 * 1024 * 1024) {
            throw new InvalidArgumentException('Live audio segment exceeds the 16 MB limit.');
        }

        $existing = $this->pdo->prepare(
            'SELECT id, sha256, transcription_status
             FROM ipca_cvr_live_audio_segments
             WHERE recording_uid = ? AND segment_index = ? LIMIT 1'
        );
        $existing->execute(array($recordingUid, $segmentIndex));
        $row = $existing->fetch(PDO::FETCH_ASSOC);
        if (is_array($row)) {
            if (!hash_equals(strtolower((string)$row['sha256']), $actualSha)) {
                throw new RuntimeException('This immutable segment index already contains different audio.');
            }
            return array(
                'ok' => true,
                'segment_id' => (int)$row['id'],
                'status' => (string)$row['transcription_status'],
                'already_present' => true,
            );
        }

        $relativeDirectory = 'storage/cvr_live_audio_segments/'
            . $operationalSessionUuid . '/' . $recordingUid;
        $directory = CockpitRecorderService::projectRoot() . '/' . $relativeDirectory;
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Could not create live audio segment storage.');
        }
        $filename = str_pad((string)$segmentIndex, 6, '0', STR_PAD_LEFT) . '-' . $actualSha . '.m4a';
        $absolutePath = $directory . '/' . $filename;
        $temporaryPath = $absolutePath . '.tmp-' . bin2hex(random_bytes(4));
        if (file_put_contents($temporaryPath, $audioBytes, LOCK_EX) !== strlen($audioBytes)
            || !rename($temporaryPath, $absolutePath)) {
            @unlink($temporaryPath);
            throw new RuntimeException('Could not persist the live audio segment.');
        }

        try {
            $insert = $this->pdo->prepare(
                'INSERT INTO ipca_cvr_live_audio_segments
                 (segment_uuid, operational_session_uuid, workflow_flight_record_uuid,
                  recording_uid, segment_index, started_at, duration_seconds, storage_path,
                  sha256, file_size_bytes, transcription_status, provider_result_json,
                  uploaded_by_device_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'queued\', ?, ?)'
            );
            $insert->execute(array(
                AuditEventService::uuid(),
                $operationalSessionUuid,
                $flightRecordUuid,
                $recordingUid,
                $segmentIndex,
                $startedAt,
                round($duration, 3),
                $relativeDirectory . '/' . $filename,
                $actualSha,
                strlen($audioBytes),
                AuditEventService::jsonEncode(array('language' => $language)),
                isset($device['id']) ? (int)$device['id'] : null,
            ));
            $segmentId = (int)$this->pdo->lastInsertId();
        } catch (Throwable $e) {
            @unlink($absolutePath);
            throw $e;
        }

        $spawned = $this->spawnTranscription($segmentId);
        if (!$spawned) {
            $this->pdo->prepare(
                'UPDATE ipca_cvr_live_audio_segments
                 SET transcription_status = \'queued\',
                     transcription_error = \'Transcription worker will be retried by the queue runner.\'
                 WHERE id = ?'
            )->execute(array($segmentId));
        }
        return array(
            'ok' => true,
            'segment_id' => $segmentId,
            'status' => 'queued',
            'already_present' => false,
        );
    }

    /** @return array<string,mixed> */
    public function transcribe(int $segmentId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM ipca_cvr_live_audio_segments WHERE id = ? LIMIT 1'
        );
        $statement->execute(array($segmentId));
        $segment = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($segment)) {
            throw new RuntimeException('Live audio segment not found.');
        }
        if ((string)$segment['transcription_status'] === 'ready') {
            return array('ok' => true, 'segment_id' => $segmentId, 'already_ready' => true);
        }
        $path = CockpitRecorderService::projectRoot() . '/' . ltrim((string)$segment['storage_path'], '/');
        if (!is_file($path)) {
            throw new RuntimeException('Live audio segment file is missing.');
        }
        $providerMetadata = json_decode((string)($segment['provider_result_json'] ?? ''), true);
        $language = CockpitRecorderService::normalizeLanguage(
            is_array($providerMetadata) ? (string)($providerMetadata['language'] ?? 'en') : 'en'
        );
        $this->pdo->prepare(
            'UPDATE ipca_cvr_live_audio_segments
             SET transcription_status = \'transcribing\', transcription_error = NULL WHERE id = ?'
        )->execute(array($segmentId));
        try {
            $result = (new CockpitRecorderService($this->pdo))->transcribeOpenAiAudioStructured(
                $path,
                'audio/mp4',
                basename($path),
                $language,
                'whisper-1',
                'verbose_json',
                true,
                true
            );
            $text = trim((string)($result['text'] ?? ''));
            $this->pdo->prepare(
                'UPDATE ipca_cvr_live_audio_segments
                 SET transcription_status = \'ready\', transcript_text = ?,
                     provider_result_json = ?, transcription_error = NULL,
                     transcribed_at = CURRENT_TIMESTAMP(3)
                 WHERE id = ?'
            )->execute(array(
                $text,
                AuditEventService::jsonEncode($result),
                $segmentId,
            ));
            require_once __DIR__ . '/CvrAutoReconstructionOrchestrator.php';
            CvrAutoReconstructionOrchestrator::safeConsider(
                $this->pdo,
                (string)$segment['workflow_flight_record_uuid'],
                null,
                null,
                null
            );
            return array('ok' => true, 'segment_id' => $segmentId, 'text_length' => mb_strlen($text));
        } catch (Throwable $e) {
            $this->pdo->prepare(
                'UPDATE ipca_cvr_live_audio_segments
                 SET transcription_status = \'failed\', transcription_error = ? WHERE id = ?'
            )->execute(array(mb_substr($e->getMessage(), 0, 1000), $segmentId));
            throw $e;
        }
    }

    private function spawnTranscription(int $segmentId): bool
    {
        if (!function_exists('exec')) {
            return false;
        }
        $php = CockpitRecorderService::findBinary(array(
            trim((string)PHP_BINDIR) !== '' ? rtrim((string)PHP_BINDIR, '/') . '/php' : '',
            '/usr/bin/php',
            '/usr/local/bin/php',
        ));
        $script = realpath(__DIR__ . '/../scripts/run_cvr_live_audio_transcription.php');
        if ($php === '' || $script === false) {
            return false;
        }
        $logDirectory = CockpitRecorderService::projectRoot() . '/storage/logs';
        if (!is_dir($logDirectory)) {
            @mkdir($logDirectory, 0775, true);
        }
        $command = 'nohup ' . escapeshellarg($php) . ' ' . escapeshellarg($script)
            . ' ' . escapeshellarg('--segment-id=' . $segmentId)
            . ' >> ' . escapeshellarg($logDirectory . '/cvr_live_audio_' . $segmentId . '.log')
            . ' 2>&1 < /dev/null &';
        exec($command, $output, $exitCode);
        return $exitCode === 0;
    }

    private function identifier(string $value, int $limit): string
    {
        return mb_substr(preg_replace('/[^A-Za-z0-9_-]+/', '', trim($value)) ?? '', 0, $limit);
    }

    private function uuid(string $value): string
    {
        $value = strtolower(trim($value));
        if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/', $value)) {
            throw new InvalidArgumentException('Canonical Operational Session and Flight Record UUIDs are required.');
        }
        return $value;
    }

    private function dateTime(string $value): string
    {
        try {
            return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
        } catch (Throwable) {
            throw new InvalidArgumentException('A valid segment start time is required.');
        }
    }
}
