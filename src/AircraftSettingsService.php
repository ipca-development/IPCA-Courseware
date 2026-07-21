<?php
declare(strict_types=1);

require_once __DIR__ . '/PfdProfileService.php';
require_once __DIR__ . '/G3XFlightStreamParser.php';

final class AircraftSettingsService
{
    public const SCHEMA_VERSION = 1;

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @param array<string,mixed> $recording
     * @return array<string,mixed>
     */
    public function resolvedForRecording(array $recording): array
    {
        $aircraft = null;
        $aircraftId = (int)($recording['aircraft_id'] ?? 0);
        if ($aircraftId > 0) {
            $aircraft = $this->aircraftById($aircraftId);
        }
        if ($aircraft === null) {
            $registration = trim((string)($recording['aircraft_registration'] ?? ''));
            if ($registration !== '') {
                $aircraft = $this->aircraftByRegistration($registration);
            }
        }

        $identity = $this->identityFromAircraftAndRecording($aircraft, $recording);
        return $this->resolve($identity, $aircraft);
    }

    /**
     * @return array<string,mixed>
     */
    public function resolvedForAircraftId(int $aircraftId): array
    {
        $aircraft = $this->aircraftById($aircraftId);
        if ($aircraft === null) {
            return $this->resolve($this->emptyIdentity(), null);
        }
        return $this->resolve($this->identityFromAircraftAndRecording($aircraft, array()), $aircraft);
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function saveReplayProfile(int $aircraftId, array $payload, ?int $actorUserId = null): void
    {
        $aircraft = $this->aircraftById($aircraftId);
        if ($aircraft === null) {
            throw new RuntimeException('Aircraft not found.');
        }
        if (!$this->tablePresent('ipca_aircraft_replay_profiles')) {
            throw new RuntimeException('Apply scripts/sql/2026_07_20_aircraft_replay_profiles.sql first.');
        }

        $layout = $this->decodeInputJson((string)($payload['layout_config_json'] ?? '{}'), 'Replay layout JSON');
        $instrument = $this->decodeInputJson((string)($payload['instrument_override_json'] ?? '{}'), 'Instrument override JSON');
        $trim = $this->decodeInputJson((string)($payload['trim_config_json'] ?? '{}'), 'Trim config JSON');
        $layout = $this->ensureSchemaVersion($layout);
        $instrument = $this->ensureSchemaVersion($instrument);
        $trim = $this->normalizeTrimConfig($this->ensureSchemaVersion($trim));
        $reason = substr(trim((string)($payload['change_reason'] ?? 'Aircraft settings update')), 0, 512);
        $modelCode = $this->modelCodeFromIdentity($this->identityFromAircraftAndRecording($aircraft, array()));

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('
                UPDATE ipca_aircraft_replay_profiles
                SET active = 0, effective_to_utc = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
                WHERE aircraft_id = ? AND active = 1
            ')->execute(array($aircraftId));
            $nextVersion = $this->nextReplayProfileVersion($aircraftId);
            $stmt = $this->pdo->prepare('
                INSERT INTO ipca_aircraft_replay_profiles (
                    aircraft_id, aircraft_model_code, profile_name, version_number, active,
                    effective_from_utc, layout_config_json, instrument_override_json, trim_config_json,
                    schema_version, changed_by, change_reason, created_at, updated_at
                ) VALUES (?, ?, ?, ?, 1, CURRENT_TIMESTAMP, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
            ');
            $stmt->execute(array(
                $aircraftId,
                $modelCode,
                trim((string)($payload['profile_name'] ?? 'Default')) ?: 'Default',
                $nextVersion,
                json_encode($layout, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                json_encode($instrument, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                json_encode($trim, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                self::SCHEMA_VERSION,
                $actorUserId,
                $reason,
            ));
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function saveAlertSeverity(int $id, string $severity, string $displayText, string $notes): void
    {
        if (!$this->tablePresent('ipca_garmin_alert_catalog')) {
            throw new RuntimeException('Alert catalog table is unavailable.');
        }
        $severity = strtolower(trim($severity));
        if (!in_array($severity, array('warning', 'caution', 'info'), true)) {
            throw new RuntimeException('Invalid alert severity.');
        }
        $stmt = $this->pdo->prepare('
            UPDATE ipca_garmin_alert_catalog
            SET severity = ?, display_text = ?, notes = ?, updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ');
        $stmt->execute(array($severity, substr(trim($displayText), 0, 255), trim($notes), $id));
    }

    /**
     * Scans every stored Garmin CSV evidence file and builds a canonical alert catalog.
     *
     * @return array<string,mixed>
     */
    public function rebuildAlertCatalogFromStoredCsvs(string $localCsvRoot = ''): array
    {
        if (!$this->tablePresent('ipca_garmin_alert_catalog')) {
            throw new RuntimeException('Alert catalog table is unavailable.');
        }
        if (!$this->tablePresent('ipca_garmin_csv_files')) {
            throw new RuntimeException('Garmin CSV file table is unavailable.');
        }

        $rows = $this->storedGarminCsvFiles();
        $observed = array();
        $filesScanned = 0;
        $filesFailed = 0;
        $rowCount = 0;
        $failures = array();
        $localCsvIndex = $localCsvRoot !== '' ? $this->localCsvFileIndex($localCsvRoot) : array();
        $localCsvMatches = 0;

        foreach ($rows as $csv) {
            $path = trim((string)($csv['storage_path'] ?? ''));
            $matchedLocalPath = $this->matchingLocalCsvPath($csv, $localCsvIndex);
            if ($matchedLocalPath !== null) {
                $path = $matchedLocalPath;
                $localCsvMatches++;
            }
            if ($path === '') {
                continue;
            }
            try {
                if ($matchedLocalPath !== null) {
                    $parsed = $this->scanAlertRowsFromCsvFile($path, (string)($csv['import_profile'] ?? ''));
                } else {
                    $parsed = G3XFlightStreamParser::parseFile($path, (string)($csv['import_profile'] ?? ''));
                }
            } catch (Throwable $e) {
                $filesFailed++;
                if (count($failures) < 10) {
                    $failures[] = array(
                        'id' => (int)($csv['id'] ?? 0),
                        'filename' => (string)($csv['original_filename'] ?? ''),
                        'error' => $e->getMessage(),
                    );
                }
                continue;
            }

            $filesScanned++;
            $aircraftType = $this->catalogAircraftTypeForCsv($csv);
            foreach ($parsed['rows'] as $row) {
                $rowCount++;
                foreach ($this->alertsFromCsvRow($row) as $alert) {
                    $source = $alert['source_column'];
                    $key = $alert['key'];
                    $mapKey = $aircraftType . "\n" . $source . "\n" . $key;
                    if (!isset($observed[$mapKey])) {
                        $observed[$mapKey] = array(
                            'aircraft_type' => $aircraftType,
                            'source_column' => $source,
                            'alert_key' => $key,
                            'display_text' => $alert['text'],
                            'count' => 0,
                        );
                    }
                    $observed[$mapKey]['count']++;
                }
            }
        }

        $replaySummary = $this->scanReplaySampleAlerts($observed);
        $rowCount += (int)$replaySummary['rows_scanned'];

        $this->upsertObservedAlerts(array_values($observed));
        $removedCompositeRows = $this->removeCompositeAlertRows();

        return array(
            'files_total' => count($rows),
            'files_scanned' => $filesScanned,
            'files_failed' => $filesFailed,
            'local_csv_root' => $localCsvRoot,
            'local_csv_files_indexed' => count($localCsvIndex),
            'local_csv_matches' => $localCsvMatches,
            'rows_scanned' => $rowCount,
            'replay_alert_rows_scanned' => (int)$replaySummary['rows_scanned'],
            'canonical_alert_count' => count($observed),
            'removed_composite_rows' => $removedCompositeRows,
            'failures' => $failures,
        );
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function alertCatalogRows(string $aircraftType = ''): array
    {
        if (!$this->tablePresent('ipca_garmin_alert_catalog')) {
            return array();
        }
        $aircraftType = strtoupper(trim($aircraftType));
        if ($aircraftType !== '') {
            $stmt = $this->pdo->prepare('
                SELECT *
                FROM ipca_garmin_alert_catalog
                WHERE aircraft_type IN (?, ?)
                  AND display_text NOT LIKE ?
                ORDER BY aircraft_type DESC, severity ASC, display_text ASC, alert_key ASC
            ');
            $stmt->execute(array('', $aircraftType, '%/%'));
        } else {
            $stmt = $this->pdo->prepare('
                SELECT *
                FROM ipca_garmin_alert_catalog
                WHERE display_text NOT LIKE ?
                ORDER BY aircraft_type ASC, severity ASC, display_text ASC, alert_key ASC
            ');
            $stmt->execute(array('%/%'));
        }
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : array();
        return is_array($rows) ? $rows : array();
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function storedGarminCsvFiles(): array
    {
        $hasAircraftDevices = $this->tablePresent('ipca_aircraft_devices');
        $sql = $hasAircraftDevices
            ? 'SELECT c.*, a.aircraft_type AS resolved_aircraft_type
               FROM ipca_garmin_csv_files c
               LEFT JOIN ipca_aircraft_devices a ON a.id = c.aircraft_id
               WHERE c.storage_path <> ?
               ORDER BY c.id ASC'
            : 'SELECT c.*, ? AS resolved_aircraft_type
               FROM ipca_garmin_csv_files c
               WHERE c.storage_path <> ?
               ORDER BY c.id ASC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($hasAircraftDevices ? array('') : array('', ''));
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : array();
        return is_array($rows) ? $rows : array();
    }

    /**
     * @param array<string,mixed> $csv
     */
    private function catalogAircraftTypeForCsv(array $csv): string
    {
        $type = strtoupper(trim((string)($csv['resolved_aircraft_type'] ?? '')));
        if ($type !== '') {
            return $type;
        }
        $registration = strtoupper(trim((string)($csv['aircraft_registration'] ?? '')));
        return $registration;
    }

    /**
     * @return array<string,string>
     */
    private function localCsvFileIndex(string $root): array
    {
        $root = rtrim($root, '/');
        if ($root === '' || !is_dir($root)) {
            return array();
        }
        $index = array();
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile()) {
                continue;
            }
            if (strtolower($file->getExtension()) !== 'csv') {
                continue;
            }
            $index[$file->getBasename()] = $file->getPathname();
        }
        return $index;
    }

    /**
     * @param array<string,mixed> $csv
     * @param array<string,string> $localCsvIndex
     */
    private function matchingLocalCsvPath(array $csv, array $localCsvIndex): ?string
    {
        if ($localCsvIndex === array()) {
            return null;
        }
        $candidates = array_filter(array_unique(array(
            basename((string)($csv['storage_path'] ?? '')),
            (string)($csv['original_filename'] ?? ''),
        )));
        foreach ($candidates as $candidate) {
            if (isset($localCsvIndex[$candidate])) {
                return $localCsvIndex[$candidate];
            }
        }
        return null;
    }

    /**
     * Streams a Garmin CSV and returns only rows containing relevant alert columns.
     *
     * @return array{rows:list<array<string,string>>, row_count:int}
     */
    private function scanAlertRowsFromCsvFile(string $path, string $expectedImportProfile = ''): array
    {
        if (!is_file($path)) {
            throw new RuntimeException('G3X CSV file is missing.');
        }
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Could not open G3X CSV file.');
        }
        try {
            while (($line = fgets($handle)) !== false) {
                if (stripos($line, '#airframe_info') !== false) {
                    break;
                }
            }
            $headerLine = fgets($handle);
            if ($headerLine === false) {
                throw new RuntimeException('G3X CSV header row is missing.');
            }
            $firstHeaders = array_map(static fn(string $header): string => ltrim(trim($header), '#'), str_getcsv($headerLine, ',', '"', '\\'));
            $aliasLine = fgets($handle);
            if ($aliasLine === false) {
                throw new RuntimeException('Garmin CSV alias row is missing.');
            }
            $aliasHeaders = array_map(static fn(string $header): string => ltrim(trim($header), '#'), str_getcsv($aliasLine, ',', '"', '\\'));
            $importProfile = GarminCsvImportProfile::detectFromHeaders($firstHeaders, $aliasHeaders);
            if ($expectedImportProfile !== '') {
                GarminCsvImportProfile::assertMatches($expectedImportProfile, $importProfile);
            }
            $headers = $importProfile === GarminCsvImportProfile::G1000_NXI ? $aliasHeaders : $firstHeaders;
            $alertIndexes = array();
            foreach ($headers as $index => $header) {
                $source = $this->alertSourceColumn((string)$header);
                if ($source !== '') {
                    $alertIndexes[$index] = $source;
                }
            }
            if ($alertIndexes === array()) {
                return array('rows' => array(), 'row_count' => 0);
            }
            $rows = array();
            $rowCount = 0;
            while (($values = fgetcsv($handle, null, ',', '"', '\\')) !== false) {
                $rowCount++;
                $row = array();
                foreach ($alertIndexes as $index => $sourceColumn) {
                    $value = trim((string)($values[$index] ?? ''));
                    if ($value !== '') {
                        $row[$sourceColumn] = $value;
                    }
                }
                if ($row !== array()) {
                    $rows[] = $row;
                }
            }
            return array('rows' => $rows, 'row_count' => $rowCount);
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param array<string,array<string,mixed>> $observed
     * @return array{rows_scanned:int}
     */
    private function scanReplaySampleAlerts(array &$observed): array
    {
        if (!$this->tablePresent('ipca_cockpit_replay_samples') || !$this->tablePresent('ipca_cockpit_recordings')) {
            return array('rows_scanned' => 0);
        }

        $hasAircraftDevices = $this->tablePresent('ipca_aircraft_devices');
        $rowsScanned = 0;

        foreach (array('cas_alert' => 'CAS Alert', 'terrain_alert' => 'Terrain Alert') as $column => $sourceColumn) {
            $sql = $hasAircraftDevices
                ? 'SELECT COALESCE(a.aircraft_type, r.aircraft_type, ?) AS aircraft_type, COALESCE(a.registration, r.aircraft_registration, ?) AS aircraft_registration, rs.' . $column . ' AS alert_text, COUNT(*) AS observation_count
                   FROM ipca_cockpit_replay_samples rs
                   INNER JOIN ipca_cockpit_recordings r ON r.id = rs.recording_id
                   LEFT JOIN ipca_aircraft_devices a ON a.id = r.aircraft_id
                   WHERE rs.' . $column . ' IS NOT NULL AND rs.' . $column . ' <> ?
                   GROUP BY COALESCE(a.aircraft_type, r.aircraft_type, ?), COALESCE(a.registration, r.aircraft_registration, ?), rs.' . $column
                : 'SELECT COALESCE(r.aircraft_type, ?) AS aircraft_type, COALESCE(r.aircraft_registration, ?) AS aircraft_registration, rs.' . $column . ' AS alert_text, COUNT(*) AS observation_count
                   FROM ipca_cockpit_replay_samples rs
                   INNER JOIN ipca_cockpit_recordings r ON r.id = rs.recording_id
                   WHERE rs.' . $column . ' IS NOT NULL AND rs.' . $column . ' <> ?
                   GROUP BY COALESCE(r.aircraft_type, ?), COALESCE(r.aircraft_registration, ?), rs.' . $column;
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(array('', '', '', '', ''));
            while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
                $rowsScanned += (int)($row['observation_count'] ?? 0);
                $this->addObservedAlertText(
                    $observed,
                    (string)($row['aircraft_type'] ?? ''),
                    (string)($row['aircraft_registration'] ?? ''),
                    $sourceColumn,
                    (string)($row['alert_text'] ?? ''),
                    (int)($row['observation_count'] ?? 0)
                );
            }
        }

        $stmt = $this->pdo->prepare('
            SELECT rs.system_alerts_json, COALESCE(a.aircraft_type, r.aircraft_type, ?) AS aircraft_type, COALESCE(a.registration, r.aircraft_registration, ?) AS aircraft_registration
            FROM ipca_cockpit_replay_samples rs
            INNER JOIN ipca_cockpit_recordings r ON r.id = rs.recording_id
            LEFT JOIN ipca_aircraft_devices a ON a.id = r.aircraft_id
            WHERE rs.system_alerts_json IS NOT NULL
              AND JSON_LENGTH(rs.system_alerts_json) > 0
        ');
        $stmt->execute(array('', ''));
        while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            $decoded = json_decode((string)($row['system_alerts_json'] ?? ''), true);
            if (!is_array($decoded)) {
                continue;
            }
            $rowsScanned++;
            foreach ($decoded as $alert) {
                if (!is_array($alert)) {
                    continue;
                }
                $this->addObservedAlertText(
                    $observed,
                    (string)($row['aircraft_type'] ?? ''),
                    (string)($row['aircraft_registration'] ?? ''),
                    (string)($alert['source_column'] ?? 'CAS Alert'),
                    (string)($alert['text'] ?? ''),
                    1
                );
            }
        }

        return array('rows_scanned' => $rowsScanned);
    }

    /**
     * @param array<string,array<string,mixed>> $observed
     */
    private function addObservedAlertText(array &$observed, string $aircraftType, string $registration, string $sourceColumn, string $rawText, int $count): void
    {
        $aircraftType = strtoupper(trim($aircraftType));
        if ($aircraftType === '') {
            $aircraftType = strtoupper(trim($registration));
        }
        foreach ($this->splitAlertText($rawText) as $text) {
            $key = $this->alertKey($text);
            if ($key === '') {
                continue;
            }
            $mapKey = $aircraftType . "\n" . $sourceColumn . "\n" . $key;
            if (!isset($observed[$mapKey])) {
                $observed[$mapKey] = array(
                    'aircraft_type' => $aircraftType,
                    'source_column' => $sourceColumn,
                    'alert_key' => $key,
                    'display_text' => $text,
                    'count' => 0,
                );
            }
            $observed[$mapKey]['count'] += max(1, $count);
        }
    }

    /**
     * @param array<string,string> $row
     * @return list<array{source_column:string,text:string,key:string}>
     */
    private function alertsFromCsvRow(array $row): array
    {
        $alerts = array();
        foreach ($row as $column => $value) {
            $columnName = trim((string)$column);
            $sourceColumn = $this->alertSourceColumn($columnName);
            if ($sourceColumn === '') {
                continue;
            }
            foreach ($this->splitAlertText((string)$value) as $text) {
                $key = $this->alertKey($text);
                if ($key === '') {
                    continue;
                }
                $alerts[] = array(
                    'source_column' => $sourceColumn,
                    'text' => $text,
                    'key' => $key,
                );
            }
        }
        return $alerts;
    }

    private function alertSourceColumn(string $columnName): string
    {
        $columnName = trim($columnName);
        if (strcasecmp($columnName, 'CAS Alert') === 0) {
            return 'CAS Alert';
        }
        if (strcasecmp($columnName, 'Terrain Alert') === 0) {
            return 'Terrain Alert';
        }
        if (preg_match('/^WARNING\s*\d+$/i', $columnName) === 1) {
            return strtoupper(preg_replace('/\s+/', ' ', $columnName) ?? $columnName);
        }
        return '';
    }

    /**
     * @return list<string>
     */
    private function splitAlertText(string $value): array
    {
        $value = trim($value);
        if ($value === '') {
            return array();
        }
        $parts = preg_split('/(?:\r\n|\r|\n|[;|]|\s*\/\s*)+/', $value) ?: array($value);
        $out = array();
        foreach ($parts as $part) {
            $text = trim((string)$part);
            if ($text !== '') {
                $out[] = preg_replace('/\s+/', ' ', $text) ?? $text;
            }
        }
        return array_values(array_unique($out));
    }

    private function alertKey(string $text): string
    {
        $key = strtoupper(trim($text));
        $key = preg_replace('/[^A-Z0-9]+/', ' ', $key) ?? '';
        return trim(preg_replace('/\s+/', ' ', $key) ?? $key);
    }

    /**
     * @param list<array<string,mixed>> $observed
     */
    private function upsertObservedAlerts(array $observed): void
    {
        if ($observed === array()) {
            return;
        }
        $stmt = $this->pdo->prepare('
            INSERT INTO ipca_garmin_alert_catalog (
                aircraft_type, source_column, alert_key, display_text, severity,
                observation_count, first_seen_at, last_seen_at, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
            ON DUPLICATE KEY UPDATE
                display_text = IF(display_text = ?, VALUES(display_text), display_text),
                observation_count = VALUES(observation_count),
                last_seen_at = CURRENT_TIMESTAMP,
                updated_at = CURRENT_TIMESTAMP
        ');
        foreach ($observed as $alert) {
            $stmt->execute(array(
                (string)($alert['aircraft_type'] ?? ''),
                (string)($alert['source_column'] ?? ''),
                (string)($alert['alert_key'] ?? ''),
                substr((string)($alert['display_text'] ?? ''), 0, 255),
                'info',
                (int)($alert['count'] ?? 0),
                '',
            ));
        }
    }

    private function removeCompositeAlertRows(): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM ipca_garmin_alert_catalog WHERE display_text LIKE ?');
        $stmt->execute(array('%/%'));
        return $stmt->rowCount();
    }

    /**
     * @param array<string,mixed> $identity
     * @param array<string,mixed>|null $aircraft
     * @return array<string,mixed>
     */
    private function resolve(array $identity, ?array $aircraft): array
    {
        $modelCode = $this->modelCodeFromIdentity($identity);
        $modelDefaults = $this->modelInstrumentDefaults($modelCode);
        $legacyPfd = is_array($aircraft) ? PfdProfileService::fromStored((string)($aircraft['pfd_profile_json'] ?? '')) : PfdProfileService::defaults();
        $replayProfile = is_array($aircraft) ? $this->activeReplayProfile((int)($aircraft['id'] ?? 0)) : null;
        $operational = is_array($aircraft) ? $this->activeOperationalConfig((int)($aircraft['id'] ?? 0)) : null;
        $trim = $this->normalizeTrimConfig(
            is_array($replayProfile) ? $this->decodeJson((string)($replayProfile['trim_config_json'] ?? '{}')) : array()
        );
        $layout = $this->ensureSchemaVersion(
            is_array($replayProfile) ? $this->decodeJson((string)($replayProfile['layout_config_json'] ?? '{}')) : array()
        );
        $instrumentOverride = $this->ensureSchemaVersion(
            is_array($replayProfile) ? $this->decodeJson((string)($replayProfile['instrument_override_json'] ?? '{}')) : array()
        );

        return array(
            'schema_version' => self::SCHEMA_VERSION,
            'identity' => $identity,
            'presentation' => array(
                'layout' => $layout,
                'instruments' => array(
                    'model_defaults' => $modelDefaults,
                    'legacy_pfd_profile' => $legacyPfd,
                    'aircraft_override' => $instrumentOverride,
                ),
                'trim' => $trim,
                'alerts' => array(
                    'catalog' => $this->alertCatalogMap((string)($identity['aircraft_type'] ?? '')),
                ),
            ),
            'operational' => $operational,
            'sources' => array(
                'model_code' => $modelCode,
                'fallback_used' => $modelDefaults['fallback_used'],
                'fallback_reason' => $modelDefaults['fallback_reason'],
                'replay_profile_id' => is_array($replayProfile) ? (int)$replayProfile['id'] : null,
                'replay_profile_version' => is_array($replayProfile) ? (int)$replayProfile['version_number'] : null,
                'operational_config_version_id' => is_array($operational) ? ($operational['version_id'] ?? null) : null,
                'legacy_pfd_profile_used' => is_array($aircraft) && trim((string)($aircraft['pfd_profile_json'] ?? '')) !== '',
            ),
        );
    }

    /**
     * @return array<string,mixed>|null
     */
    private function aircraftById(int $id): ?array
    {
        if ($id <= 0 || !$this->tablePresent('ipca_aircraft_devices')) {
            return null;
        }
        $stmt = $this->pdo->prepare('SELECT * FROM ipca_aircraft_devices WHERE id = ? LIMIT 1');
        $stmt->execute(array($id));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function aircraftByRegistration(string $registration): ?array
    {
        if (!$this->tablePresent('ipca_aircraft_devices')) {
            return null;
        }
        $stmt = $this->pdo->prepare('SELECT * FROM ipca_aircraft_devices WHERE UPPER(registration) = UPPER(?) LIMIT 1');
        $stmt->execute(array($registration));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string,mixed>|null $aircraft
     * @param array<string,mixed> $recording
     * @return array<string,mixed>
     */
    private function identityFromAircraftAndRecording(?array $aircraft, array $recording): array
    {
        return array(
            'aircraft_id' => is_array($aircraft) ? (int)($aircraft['id'] ?? 0) : (int)($recording['aircraft_id'] ?? 0),
            'registration' => (string)($aircraft['registration'] ?? $recording['aircraft_registration'] ?? ''),
            'display_name' => (string)($aircraft['display_name'] ?? $recording['aircraft_display_name'] ?? ''),
            'aircraft_type' => strtoupper(trim((string)($aircraft['aircraft_type'] ?? $recording['aircraft_type'] ?? ''))),
            'adsb_hex' => strtolower(trim((string)($aircraft['adsb_hex'] ?? $recording['aircraft_adsb_hex'] ?? ''))),
            'home_airport' => strtoupper(trim((string)($aircraft['home_airport'] ?? ''))),
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function emptyIdentity(): array
    {
        return array('aircraft_id' => 0, 'registration' => '', 'display_name' => '', 'aircraft_type' => '', 'adsb_hex' => '', 'home_airport' => '');
    }

    /**
     * @param array<string,mixed> $identity
     */
    private function modelCodeFromIdentity(array $identity): string
    {
        $type = strtoupper(trim((string)($identity['aircraft_type'] ?? '')));
        return $type !== '' ? preg_replace('/[^A-Z0-9_-]+/', '', $type) ?: 'UNKNOWN' : 'UNKNOWN';
    }

    /**
     * @return array<string,mixed>
     */
    private function modelInstrumentDefaults(string $modelCode): array
    {
        if (!$this->tablePresent('ipca_aircraft_instrument_profiles')) {
            return array('schema_version' => self::SCHEMA_VERSION, 'fallback_used' => true, 'fallback_reason' => 'instrument_profile_table_missing');
        }
        $stmt = $this->pdo->prepare('
            SELECT *
            FROM ipca_aircraft_instrument_profiles
            WHERE aircraft_model_code = ? AND profile_code = ? AND active = 1
            LIMIT 1
        ');
        $stmt->execute(array($modelCode, 'default'));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) && $modelCode !== 'PIAT') {
            $stmt->execute(array('PIAT', 'default'));
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($row)) {
                return array(
                    'schema_version' => self::SCHEMA_VERSION,
                    'fallback_used' => true,
                    'fallback_reason' => 'model_profile_missing',
                    'fallback_model_code' => 'PIAT',
                    'airspeed' => $this->decodeJson((string)($row['airspeed_config_json'] ?? '{}')),
                    'engine' => $this->decodeJson((string)($row['engine_config_json'] ?? '{}')),
                );
            }
        }
        return array(
            'schema_version' => self::SCHEMA_VERSION,
            'fallback_used' => false,
            'fallback_reason' => null,
            'fallback_model_code' => null,
            'airspeed' => is_array($row) ? $this->decodeJson((string)($row['airspeed_config_json'] ?? '{}')) : array(),
            'engine' => is_array($row) ? $this->decodeJson((string)($row['engine_config_json'] ?? '{}')) : array(),
        );
    }

    /**
     * @return array<string,mixed>|null
     */
    private function activeReplayProfile(int $aircraftId): ?array
    {
        if ($aircraftId <= 0 || !$this->tablePresent('ipca_aircraft_replay_profiles')) {
            return null;
        }
        $stmt = $this->pdo->prepare('
            SELECT *
            FROM ipca_aircraft_replay_profiles
            WHERE aircraft_id = ? AND active = 1
            ORDER BY version_number DESC, id DESC
            LIMIT 1
        ');
        $stmt->execute(array($aircraftId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function activeOperationalConfig(int $aircraftId): ?array
    {
        if ($aircraftId <= 0 || !$this->tablePresent('ipca_aircraft_operational_configs') || !$this->tablePresent('ipca_aircraft_operational_config_versions')) {
            return null;
        }
        $stmt = $this->pdo->prepare('
            SELECT v.*
            FROM ipca_aircraft_operational_configs c
            LEFT JOIN ipca_aircraft_operational_config_versions v ON v.id = c.current_version_id
            WHERE c.aircraft_id = ?
            LIMIT 1
        ');
        $stmt->execute(array($aircraftId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || empty($row['id'])) {
            return null;
        }
        return array(
            'schema_version' => self::SCHEMA_VERSION,
            'version_id' => (int)$row['id'],
            'hobbs_engine_on_rpm_threshold' => (float)$row['hobbs_engine_on_rpm_threshold'],
            'movement_groundspeed_kt' => (float)$row['movement_groundspeed_kt'],
            'fuel_discrepancy_usg' => (float)$row['fuel_discrepancy_usg'],
            'oil_blocking_threshold_percent' => $row['oil_blocking_threshold_percent'] !== null ? (int)$row['oil_blocking_threshold_percent'] : null,
            'timezone_identifier' => $row['timezone_identifier'] ?? null,
            'config_json' => $this->decodeJson((string)($row['config_json'] ?? '{}')),
        );
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function alertCatalogMap(string $aircraftType): array
    {
        $map = array();
        foreach ($this->alertCatalogRows($aircraftType) as $row) {
            $source = (string)($row['source_column'] ?? '');
            $key = (string)($row['alert_key'] ?? '');
            if ($key === '') {
                continue;
            }
            $map[strtoupper(trim($source)) . "\n" . strtoupper(trim($key))] = array(
                'source_column' => $source,
                'key' => $key,
                'display_text' => (string)($row['display_text'] ?? ''),
                'severity' => (string)($row['severity'] ?? 'info'),
            );
        }
        return $map;
    }

    /**
     * @param array<string,mixed> $config
     * @return array<string,mixed>
     */
    private function normalizeTrimConfig(array $config): array
    {
        return array(
            'schema_version' => self::SCHEMA_VERSION,
            'min' => $this->numericOr($config['min'] ?? null, -100.0),
            'neutral' => $this->numericOr($config['neutral'] ?? null, 0.0),
            'max' => $this->numericOr($config['max'] ?? null, 100.0),
            'nose_down_value' => $this->numericOr($config['nose_down_value'] ?? null, -100.0),
            'nose_up_value' => $this->numericOr($config['nose_up_value'] ?? null, 100.0),
            'direction' => 'negative_nose_down_positive_nose_up',
            'source' => (string)($config['source'] ?? 'aircraft_settings_default'),
        );
    }

    private function numericOr(mixed $value, float $fallback): float
    {
        return is_numeric($value) ? (float)$value : $fallback;
    }

    /**
     * @return array<string,mixed>
     */
    private function ensureSchemaVersion(array $json): array
    {
        $json['schema_version'] = (int)($json['schema_version'] ?? self::SCHEMA_VERSION);
        return $json;
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeInputJson(string $raw, string $label): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return array();
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException($label . ' is invalid JSON.');
        }
        return $decoded;
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeJson(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return array();
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : array();
    }

    private function nextReplayProfileVersion(int $aircraftId): int
    {
        $stmt = $this->pdo->prepare('SELECT COALESCE(MAX(version_number), 0) + 1 FROM ipca_aircraft_replay_profiles WHERE aircraft_id = ?');
        $stmt->execute(array($aircraftId));
        return max(1, (int)$stmt->fetchColumn());
    }

    private function tablePresent(string $table): bool
    {
        $stmt = $this->pdo->prepare('SHOW TABLES LIKE ?');
        $stmt->execute(array($table));
        return $stmt->fetchColumn() !== false;
    }
}
