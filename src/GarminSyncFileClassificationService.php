<?php
declare(strict_types=1);

require_once __DIR__ . '/GarminCsvImportProfile.php';

/**
 * Lightweight, rebuildable classification for immutable Garmin Sync objects.
 *
 * This inspects only the Garmin metadata/header/sample prefix. It does not run
 * flight, leg, Hobbs, replay, reservation, or debrief analysis.
 */
final class GarminSyncFileClassificationService
{
    public const VERSION = 'garmin-prefix-v1';
    public const FLIGHT_CSV = 'GARMIN_FLIGHT_CSV';
    public const UNSUPPORTED_CSV = 'UNSUPPORTED_CSV';
    public const NON_CSV = 'NON_CSV';
    public const UNREADABLE = 'UNREADABLE';

    private string $projectRoot;

    public function __construct(private PDO $pdo, ?string $projectRoot = null)
    {
        $this->projectRoot = rtrim($projectRoot ?? dirname(__DIR__), DIRECTORY_SEPARATOR);
    }

    /**
     * @return array<string,mixed>
     */
    public function classifyObjectUuid(string $objectUuid): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM ipca_garmin_sync_archive_files WHERE object_uuid = ? LIMIT 1'
        );
        $stmt->execute(array(trim($objectUuid)));
        $archive = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($archive)) {
            throw new RuntimeException('Garmin Sync archive object was not found.');
        }
        return $this->classifyArchiveRow($archive);
    }

    /**
     * @return array<string,mixed>
     */
    public function classifyArchiveId(int $archiveFileId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM ipca_garmin_sync_archive_files WHERE id = ? LIMIT 1'
        );
        $stmt->execute(array($archiveFileId));
        $archive = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($archive)) {
            throw new RuntimeException('Garmin Sync archive file was not found.');
        }
        return $this->classifyArchiveRow($archive);
    }

    /**
     * @return array{processed:int,flight_csv:int,junk_or_unsupported:int,registrations:array<string,int>}
     */
    public function classifyPending(int $limit = 500, bool $reclassify = false): array
    {
        $limit = max(1, min(5000, $limit));
        $join = $reclassify ? '' : 'LEFT JOIN ipca_garmin_sync_file_classifications c ON c.archive_file_id = a.id';
        $where = $reclassify ? '' : 'WHERE c.id IS NULL';
        $stmt = $this->pdo->query(
            "SELECT a.*
             FROM ipca_garmin_sync_archive_files a
             {$join}
             {$where}
             ORDER BY a.id ASC
             LIMIT {$limit}"
        );
        $archives = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : array();
        $summary = array(
            'processed' => 0,
            'flight_csv' => 0,
            'junk_or_unsupported' => 0,
            'registrations' => array(),
        );
        foreach (is_array($archives) ? $archives : array() as $archive) {
            $classification = $this->classifyArchiveRow($archive);
            $summary['processed']++;
            if (($classification['source_kind'] ?? '') === self::FLIGHT_CSV) {
                $summary['flight_csv']++;
            } else {
                $summary['junk_or_unsupported']++;
            }
            $registration = trim((string)($classification['aircraft_registration'] ?? ''));
            if ($registration !== '') {
                $summary['registrations'][$registration] = ($summary['registrations'][$registration] ?? 0) + 1;
            }
        }
        ksort($summary['registrations']);
        return $summary;
    }

    /**
     * @return array<string,mixed>
     */
    public function inspectPath(string $path, string $originalFilename): array
    {
        $extension = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));
        if ($extension !== 'csv') {
            return $this->result(
                self::NON_CSV,
                false,
                null,
                'The original file does not have a .csv extension.'
            );
        }
        if (!is_file($path) || !is_readable($path)) {
            return $this->result(
                self::UNREADABLE,
                false,
                null,
                'The archived file is missing or unreadable.'
            );
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return $this->result(self::UNREADABLE, false, null, 'The archived file could not be opened.');
        }

        try {
            $metaLine = null;
            for ($lineNumber = 0; $lineNumber < 10; $lineNumber++) {
                $line = fgets($handle, 65536);
                if ($line === false) {
                    break;
                }
                if (stripos($line, '#airframe_info') !== false) {
                    $metaLine = $line;
                    break;
                }
            }
            if ($metaLine === null) {
                return $this->result(
                    self::UNSUPPORTED_CSV,
                    false,
                    null,
                    'CSV is not a Garmin flight log: #airframe_info is missing.'
                );
            }

            $headerLine = fgets($handle, 65536);
            $aliasLine = fgets($handle, 65536);
            $sampleLine = fgets($handle, 65536);
            if ($headerLine === false || $aliasLine === false || $sampleLine === false) {
                return $this->result(
                    self::UNSUPPORTED_CSV,
                    false,
                    null,
                    'Garmin metadata exists, but required header or sample rows are missing.'
                );
            }
        } finally {
            fclose($handle);
        }

        $metadata = $this->metadata($metaLine);
        $headers = $this->csvRow($headerLine);
        $aliases = $this->csvRow($aliasLine);
        $sample = $this->csvRow($sampleLine);
        $allHeaders = array_values(array_unique(array_merge($headers, $aliases)));
        $requiredGroups = array(
            array('Date (yyyy-mm-dd)', 'Lcl Date'),
            array('UTC Time (hh:mm:ss)', 'UTC Time'),
            array('Latitude (deg)', 'Latitude'),
            array('Longitude (deg)', 'Longitude'),
        );
        foreach ($requiredGroups as $alternatives) {
            if (count(array_intersect($alternatives, $allHeaders)) === 0) {
                return $this->result(
                    self::UNSUPPORTED_CSV,
                    false,
                    null,
                    'CSV has Garmin metadata but lacks required flight time/GPS columns.',
                    $metadata
                );
            }
        }
        if (count(array_filter($sample, static fn(string $value): bool => trim($value) !== '')) < 4) {
            return $this->result(
                self::UNSUPPORTED_CSV,
                false,
                null,
                'CSV has Garmin headers but no usable flight sample row.',
                $metadata
            );
        }

        $registration = $this->normalizeRegistration((string)($metadata['aircraft_ident'] ?? ''));
        $reason = $registration === null
            ? 'Valid Garmin flight CSV; aircraft_ident is missing or invalid.'
            : 'Valid Garmin flight CSV; registration read from #airframe_info aircraft_ident.';
        $result = $this->result(self::FLIGHT_CSV, true, $registration, $reason, $metadata);
        $result['product'] = $this->nullableString($metadata['product'] ?? null, 128);
        $result['system_identifier'] = $this->nullableString($metadata['system_id'] ?? null, 128);
        $result['import_profile'] = GarminCsvImportProfile::detectFromHeaders($headers, $aliases);
        return $result;
    }

    /**
     * @param array<string,mixed> $archive
     * @return array<string,mixed>
     */
    private function classifyArchiveRow(array $archive): array
    {
        $archiveId = (int)($archive['id'] ?? 0);
        if ($archiveId <= 0) {
            throw new RuntimeException('Garmin Sync archive identity is invalid.');
        }
        $path = $this->archivePath((string)($archive['storage_path'] ?? ''));
        $result = $this->inspectPath($path, (string)($archive['original_filename'] ?? ''));
        $metadataJson = json_encode(
            $result['metadata'] ?? array(),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
        $stmt = $this->pdo->prepare(
            'INSERT INTO ipca_garmin_sync_file_classifications
              (archive_file_id, source_kind, analysis_eligible, aircraft_registration,
               product, system_identifier, import_profile, classification_reason,
               metadata_json, classifier_version, classified_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP(3))
             ON DUPLICATE KEY UPDATE
               source_kind = VALUES(source_kind),
               analysis_eligible = VALUES(analysis_eligible),
               aircraft_registration = VALUES(aircraft_registration),
               product = VALUES(product),
               system_identifier = VALUES(system_identifier),
               import_profile = VALUES(import_profile),
               classification_reason = VALUES(classification_reason),
               metadata_json = VALUES(metadata_json),
               classifier_version = VALUES(classifier_version),
               classified_at = VALUES(classified_at)'
        );
        $stmt->execute(array(
            $archiveId,
            (string)$result['source_kind'],
            !empty($result['analysis_eligible']) ? 1 : 0,
            $result['aircraft_registration'],
            $result['product'],
            $result['system_identifier'],
            $result['import_profile'],
            (string)$result['classification_reason'],
            $metadataJson,
            self::VERSION,
        ));
        $result['archive_file_id'] = $archiveId;
        return $result;
    }

    /**
     * @param array<string,string> $metadata
     * @return array<string,mixed>
     */
    private function result(
        string $sourceKind,
        bool $analysisEligible,
        ?string $registration,
        string $reason,
        array $metadata = array()
    ): array {
        return array(
            'source_kind' => $sourceKind,
            'analysis_eligible' => $analysisEligible,
            'aircraft_registration' => $registration,
            'product' => null,
            'system_identifier' => null,
            'import_profile' => null,
            'classification_reason' => $reason,
            'metadata' => $metadata,
            'classifier_version' => self::VERSION,
        );
    }

    /**
     * @return array<string,string>
     */
    private function metadata(string $line): array
    {
        $metadata = array();
        foreach (str_getcsv($line, ',', '"', '\\') as $part) {
            if (!is_string($part) || !str_contains($part, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $part, 2);
            $key = trim($key);
            if ($key !== '') {
                $metadata[$key] = trim(trim($value), '"');
            }
        }
        return $metadata;
    }

    /**
     * @return list<string>
     */
    private function csvRow(string $line): array
    {
        return array_values(array_map(
            static fn($value): string => ltrim(trim((string)$value), '#'),
            str_getcsv($line, ',', '"', '\\')
        ));
    }

    private function archivePath(string $storagePath): string
    {
        if ($storagePath === '') {
            return '';
        }
        if (str_starts_with($storagePath, DIRECTORY_SEPARATOR)) {
            return $storagePath;
        }
        return $this->projectRoot . DIRECTORY_SEPARATOR . ltrim($storagePath, DIRECTORY_SEPARATOR);
    }

    private function normalizeRegistration(string $value): ?string
    {
        $normalized = strtoupper(trim($value));
        $normalized = preg_replace('/[^A-Z0-9-]/', '', $normalized) ?? '';
        if ($normalized === '' || preg_match('/^[A-Z0-9][A-Z0-9-]{2,15}$/', $normalized) !== 1) {
            return null;
        }
        return $normalized;
    }

    private function nullableString(mixed $value, int $maximumLength): ?string
    {
        $value = trim((string)$value);
        return $value === '' ? null : mb_substr($value, 0, $maximumLength);
    }
}
