<?php
declare(strict_types=1);

require_once __DIR__ . '/CockpitRecorderService.php';
require_once __DIR__ . '/StandaloneG3XReplayBuilder.php';

final class GarminCsvReplayPayloadService
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return array<string,mixed>
     */
    public function buildForCsvFileId(int $csvFileId, bool $force = false): array
    {
        $this->ensureTable();
        $csvFile = $this->csvFile($csvFileId);
        if ($csvFile === null) {
            throw new RuntimeException('Garmin CSV evidence record not found.');
        }
        $sha256 = (string)($csvFile['sha256'] ?? '');
        $existing = $this->payloadForCsvFile($csvFileId, $sha256, StandaloneG3XReplayBuilder::VERSION);
        if (!$force && $existing !== null && (string)($existing['build_status'] ?? '') === 'ready') {
            return $existing;
        }

        try {
            $csvPath = $this->resolveStoragePath((string)($csvFile['storage_path'] ?? ''));
            $aircraftLabel = trim((string)($csvFile['aircraft_registration'] ?? ''));
            if ($aircraftLabel === '') {
                $aircraftLabel = trim((string)($csvFile['aircraft_ident'] ?? ''));
            }
            $payload = (new StandaloneG3XReplayBuilder())->build(
                $csvPath,
                (string)($csvFile['import_profile'] ?? ''),
                $aircraftLabel
            );
            $key = $this->replayKey($csvFileId, $sha256, StandaloneG3XReplayBuilder::VERSION);
            $payloadPath = CockpitRecorderService::projectRoot() . '/storage/tmp/' . $key . '.json';
            $this->ensureWritableDirectory(dirname($payloadPath));
            $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($json === false) {
                throw new RuntimeException('Could not encode Garmin replay payload.');
            }
            if (file_put_contents($payloadPath, $json . PHP_EOL) === false) {
                throw new RuntimeException('Could not store Garmin replay payload.');
            }
            $this->storePayload(
                $csvFileId,
                $sha256,
                StandaloneG3XReplayBuilder::VERSION,
                $key,
                'storage/tmp/' . $key . '.json',
                (int)($payload['replay_sample_count'] ?? count($payload['samples'] ?? array())),
                'ready',
                ''
            );
        } catch (Throwable $e) {
            $this->storePayload($csvFileId, $sha256, StandaloneG3XReplayBuilder::VERSION, '', '', 0, 'failed', $e->getMessage());
            throw $e;
        }

        $payloadRow = $this->payloadForCsvFile($csvFileId, $sha256, StandaloneG3XReplayBuilder::VERSION);
        if ($payloadRow === null) {
            throw new RuntimeException('Garmin replay payload was built but could not be read back.');
        }
        return $payloadRow;
    }

    /**
     * @param list<int> $csvFileIds
     * @return array<int,array<string,mixed>>
     */
    public function payloadsForCsvFileIds(array $csvFileIds): array
    {
        $this->ensureTable();
        $ids = array_values(array_unique(array_filter(array_map('intval', $csvFileIds))));
        if (!$ids) {
            return array();
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare("
            SELECT *
            FROM ipca_garmin_csv_replay_payloads
            WHERE garmin_csv_file_id IN ({$placeholders})
              AND builder_version = ?
            ORDER BY built_at DESC, id DESC
        ");
        $stmt->execute(array_merge($ids, array(StandaloneG3XReplayBuilder::VERSION)));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $out = array();
        foreach (is_array($rows) ? $rows : array() as $row) {
            $id = (int)($row['garmin_csv_file_id'] ?? 0);
            if ($id > 0 && !isset($out[$id])) {
                $out[$id] = $row;
            }
        }
        return $out;
    }

    public function ensureTable(): void
    {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS ipca_garmin_csv_replay_payloads (
              id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
              garmin_csv_file_id BIGINT UNSIGNED NOT NULL,
              csv_sha256 CHAR(64) NOT NULL,
              builder_version VARCHAR(64) NOT NULL,
              replay_key VARCHAR(160) NOT NULL DEFAULT '',
              payload_storage_path VARCHAR(512) NOT NULL DEFAULT '',
              sample_count INT UNSIGNED NOT NULL DEFAULT 0,
              build_status VARCHAR(32) NOT NULL DEFAULT 'pending',
              last_error TEXT NULL,
              built_at DATETIME(3) NULL,
              created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
              updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
              UNIQUE KEY uk_garmin_csv_replay_current (garmin_csv_file_id, csv_sha256, builder_version),
              KEY idx_garmin_csv_replay_status (build_status, built_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    /**
     * @return array<string,mixed>|null
     */
    private function csvFile(int $csvFileId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ipca_garmin_csv_files WHERE id = ? LIMIT 1');
        $stmt->execute(array($csvFileId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function payloadForCsvFile(int $csvFileId, string $sha256, string $builderVersion): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ipca_garmin_csv_replay_payloads WHERE garmin_csv_file_id = ? AND csv_sha256 = ? AND builder_version = ? LIMIT 1');
        $stmt->execute(array($csvFileId, $sha256, $builderVersion));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function storePayload(int $csvFileId, string $sha256, string $builderVersion, string $key, string $path, int $sampleCount, string $status, string $error): void
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO ipca_garmin_csv_replay_payloads
              (garmin_csv_file_id, csv_sha256, builder_version, replay_key, payload_storage_path, sample_count, build_status, last_error, built_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP(3))
            ON DUPLICATE KEY UPDATE
              replay_key = VALUES(replay_key),
              payload_storage_path = VALUES(payload_storage_path),
              sample_count = VALUES(sample_count),
              build_status = VALUES(build_status),
              last_error = VALUES(last_error),
              built_at = CURRENT_TIMESTAMP(3),
              updated_at = CURRENT_TIMESTAMP(3)
        ");
        $stmt->execute(array($csvFileId, $sha256, $builderVersion, $key, $path, $sampleCount, $status, $error !== '' ? $error : null));
    }

    private function replayKey(int $csvFileId, string $sha256, string $builderVersion): string
    {
        return 'garmin_csv_replay_' . $csvFileId . '_' . substr(hash('sha256', $sha256 . '|' . $builderVersion), 0, 16);
    }

    private function resolveStoragePath(string $storagePath): string
    {
        $storagePath = trim($storagePath);
        if ($storagePath === '') {
            throw new RuntimeException('Garmin CSV storage path is empty.');
        }
        $projectRoot = CockpitRecorderService::projectRoot();
        $candidates = str_starts_with($storagePath, '/')
            ? array($storagePath)
            : array($projectRoot . '/' . ltrim($storagePath, '/'), $projectRoot . '/storage/cvr/' . ltrim($storagePath, '/'));
        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }
        throw new RuntimeException('Stored Garmin CSV file is not available on this server.');
    }

    private function ensureWritableDirectory(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Could not create replay payload directory.');
        }
        if (!is_writable($dir)) {
            throw new RuntimeException('Replay payload directory is not writable.');
        }
    }
}
