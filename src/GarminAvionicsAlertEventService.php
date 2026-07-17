<?php
declare(strict_types=1);

require_once __DIR__ . '/AuditEventService.php';
require_once __DIR__ . '/CockpitRecorderService.php';
require_once __DIR__ . '/G3XFlightStreamParser.php';

final class GarminAvionicsAlertEventService
{
    public function __construct(private PDO $pdo)
    {
    }

    public function ensureTable(): void
    {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS ipca_garmin_avionics_alert_events (
              id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
              event_uuid CHAR(36) NOT NULL,
              provider_name VARCHAR(64) NOT NULL DEFAULT 'GARMIN',
              garmin_csv_file_id BIGINT UNSIGNED NOT NULL,
              garmin_entry_uuid VARCHAR(80) NOT NULL DEFAULT '',
              canonical_track_uuid VARCHAR(80) NOT NULL DEFAULT '',
              sample_time_utc DATETIME(3) NULL,
              replay_time_s DECIMAL(12,3) NULL,
              csv_row_number INT UNSIGNED NULL,
              alert_type VARCHAR(32) NOT NULL DEFAULT 'unknown',
              raw_column_name VARCHAR(128) NOT NULL DEFAULT '',
              raw_alert_text TEXT NOT NULL,
              normalized_alert_text VARCHAR(255) NOT NULL DEFAULT '',
              latitude DECIMAL(11,7) NULL,
              longitude DECIMAL(11,7) NULL,
              altitude_ft DECIMAL(10,1) NULL,
              alert_hash CHAR(64) NOT NULL,
              created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
              UNIQUE KEY uk_ipca_garmin_alert_uuid (event_uuid),
              UNIQUE KEY uk_ipca_garmin_alert_hash (alert_hash),
              KEY idx_ipca_garmin_alert_csv_time (garmin_csv_file_id, sample_time_utc),
              KEY idx_ipca_garmin_alert_entry_time (garmin_entry_uuid, sample_time_utc),
              KEY idx_ipca_garmin_alert_type_time (alert_type, sample_time_utc)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    /**
     * @return array<string,mixed>
     */
    public function extractForCsvFileId(int $csvFileId): array
    {
        $this->ensureTable();
        $csvFile = $this->csvFile($csvFileId);
        if ($csvFile === null) {
            throw new RuntimeException('Garmin CSV file not found.');
        }
        $path = $this->resolveStoragePath((string)($csvFile['storage_path'] ?? ''));
        if ($path === null) {
            throw new RuntimeException('Stored Garmin CSV file is not available on this server.');
        }
        $parsed = G3XFlightStreamParser::parseFile($path, (string)($csvFile['import_profile'] ?? ''));
        $alertColumns = $this->alertColumns($parsed['headers']);
        if ($alertColumns === array()) {
            return array('ok' => true, 'csv_file_id' => $csvFileId, 'inserted' => 0, 'columns' => array());
        }

        $stmt = $this->pdo->prepare("
            INSERT IGNORE INTO ipca_garmin_avionics_alert_events
              (event_uuid, provider_name, garmin_csv_file_id, garmin_entry_uuid, canonical_track_uuid, sample_time_utc, replay_time_s,
               csv_row_number, alert_type, raw_column_name, raw_alert_text, normalized_alert_text, latitude, longitude, altitude_ft, alert_hash)
            VALUES
              (:event_uuid, 'GARMIN', :csv_file_id, :entry_uuid, :track_uuid, :sample_time_utc, :replay_time_s,
               :row_number, :alert_type, :raw_column_name, :raw_alert_text, :normalized_alert_text, :latitude, :longitude, :altitude_ft, :alert_hash)
        ");

        $firstUtc = G3XFlightStreamParser::firstUtcTimestamp($parsed['rows']);
        $inserted = 0;
        $rowNumber = 0;
        $lastByColumn = array();
        foreach ($parsed['rows'] as $row) {
            $rowNumber++;
            $utc = G3XFlightStreamParser::rowUtcTimestamp($row);
            foreach ($alertColumns as $column) {
                $rawText = trim((string)($row[$column] ?? ''));
                $normalized = $this->normalizeAlertText($rawText);
                if ($normalized === '' || $normalized === ($lastByColumn[$column] ?? '')) {
                    continue;
                }
                $lastByColumn[$column] = $normalized;
                $hash = hash('sha256', implode('|', array(
                    $csvFileId,
                    $utc !== null ? $utc->format('Y-m-d H:i:s.u') : '',
                    $column,
                    $normalized,
                )));
                $stmt->execute(array(
                    ':event_uuid' => AuditEventService::uuid(),
                    ':csv_file_id' => $csvFileId,
                    ':entry_uuid' => (string)($csvFile['garmin_entry_uuid'] ?? ''),
                    ':track_uuid' => (string)($csvFile['canonical_track_uuid'] ?? ''),
                    ':sample_time_utc' => $utc !== null ? $utc->format('Y-m-d H:i:s.u') : null,
                    ':replay_time_s' => $utc !== null && $firstUtc !== null ? max(0.0, (float)($utc->getTimestamp() - $firstUtc->getTimestamp())) : null,
                    ':row_number' => $rowNumber,
                    ':alert_type' => $this->alertType($column, $normalized),
                    ':raw_column_name' => substr($column, 0, 128),
                    ':raw_alert_text' => $rawText,
                    ':normalized_alert_text' => substr($normalized, 0, 255),
                    ':latitude' => G3XFlightStreamParser::numericValue($row, 'Latitude (deg)', 'Lat'),
                    ':longitude' => G3XFlightStreamParser::numericValue($row, 'Longitude (deg)', 'Longitude', 'Lon'),
                    ':altitude_ft' => G3XFlightStreamParser::numericValue($row, 'GPS Altitude (ft)', 'Baro Altitude (ft)', 'AltB', 'AltInd'),
                    ':alert_hash' => $hash,
                ));
                $inserted += $stmt->rowCount() > 0 ? 1 : 0;
            }
        }
        return array('ok' => true, 'csv_file_id' => $csvFileId, 'inserted' => $inserted, 'columns' => $alertColumns);
    }

    /**
     * @return array<string,mixed>
     */
    private function csvFile(int $csvFileId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ipca_garmin_csv_files WHERE id = ? LIMIT 1');
        $stmt->execute(array($csvFileId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * @param list<string> $headers
     * @return list<string>
     */
    private function alertColumns(array $headers): array
    {
        $out = array();
        foreach ($headers as $header) {
            $h = trim((string)$header);
            if ($h === '') {
                continue;
            }
            $upper = strtoupper($h);
            if (
                str_contains($upper, 'CAS ALERT')
                || str_contains($upper, 'TERRAIN ALERT')
                || str_contains($upper, 'TRAFFIC')
                || preg_match('/\bTFC\b/', $upper) === 1
                || preg_match('/\bTA\b/', $upper) === 1
                || preg_match('/\bRA\b/', $upper) === 1
                || (str_contains($upper, 'ALERT') && !str_contains($upper, 'STATUS'))
                || str_contains($upper, 'ADVISORY')
            ) {
                $out[] = $h;
            }
        }
        return array_values(array_unique($out));
    }

    private function normalizeAlertText(string $text): string
    {
        $text = strtoupper(trim($text));
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;
        if ($text === '' || in_array($text, array('0', 'NO', 'NONE', 'N/A', 'NA', 'FALSE', 'OK'), true)) {
            return '';
        }
        return $text;
    }

    private function alertType(string $column, string $text): string
    {
        $combined = strtoupper($column . ' ' . $text);
        if (str_contains($combined, 'TRAFFIC') || preg_match('/\bTFC\b|\bTA\b|\bRA\b/', $combined) === 1) {
            return 'traffic';
        }
        if (str_contains($combined, 'TERRAIN')) {
            return 'terrain';
        }
        if (str_contains($combined, 'CAS') || str_contains($combined, 'ALERT') || str_contains($combined, 'ADVISORY')) {
            return 'system';
        }
        return 'unknown';
    }

    private function resolveStoragePath(string $storagePath): ?string
    {
        $storagePath = trim($storagePath);
        if ($storagePath === '') {
            return null;
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
        return null;
    }
}
