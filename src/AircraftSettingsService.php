<?php
declare(strict_types=1);

require_once __DIR__ . '/PfdProfileService.php';

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
                WHERE aircraft_type IN ("", ?)
                ORDER BY aircraft_type DESC, severity ASC, display_text ASC, alert_key ASC
            ');
            $stmt->execute(array($aircraftType));
        } else {
            $stmt = $this->pdo->query('
                SELECT *
                FROM ipca_garmin_alert_catalog
                ORDER BY aircraft_type ASC, severity ASC, display_text ASC, alert_key ASC
            ');
        }
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : array();
        return is_array($rows) ? $rows : array();
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
