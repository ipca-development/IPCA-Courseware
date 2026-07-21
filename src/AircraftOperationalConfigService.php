<?php
declare(strict_types=1);

require_once __DIR__ . '/AuditEventService.php';

final class AircraftOperationalConfigService
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return array<string,mixed>
     */
    public function configForAircraft(?int $aircraftId): array
    {
        if ($aircraftId !== null && $aircraftId > 0) {
            $stmt = $this->pdo->prepare("
                SELECT v.*
                FROM ipca_aircraft_operational_configs c
                INNER JOIN ipca_aircraft_operational_config_versions v
                    ON v.id = c.current_version_id OR (c.current_version_id IS NULL AND v.config_id = c.id)
                WHERE c.aircraft_id = ?
                ORDER BY v.version_number DESC
                LIMIT 1
            ");
            $stmt->execute(array($aircraftId));
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($row)) {
                return $this->normalize($row);
            }
        }
        return $this->defaults();
    }

    /**
     * @return array<string,mixed>
     */
    public function ensureDefaultVersion(int $aircraftId, ?int $changedBy = null): array
    {
        if ($aircraftId <= 0) {
            throw new RuntimeException('Aircraft id is required.');
        }
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('SELECT id, current_version_id FROM ipca_aircraft_operational_configs WHERE aircraft_id = ? LIMIT 1 FOR UPDATE');
            $stmt->execute(array($aircraftId));
            $config = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($config)) {
                $this->pdo->prepare('INSERT INTO ipca_aircraft_operational_configs (aircraft_id) VALUES (?)')->execute(array($aircraftId));
                $config = array('id' => (int)$this->pdo->lastInsertId(), 'current_version_id' => null);
            }
            if (!empty($config['current_version_id'])) {
                $this->pdo->commit();
                return $this->configForAircraft($aircraftId);
            }
            $uuid = AuditEventService::uuid();
            $this->pdo->prepare("
                INSERT INTO ipca_aircraft_operational_config_versions
                  (config_id, config_version_uuid, version_number, effective_from_utc, changed_by, change_reason)
                VALUES
                  (?, ?, 1, CURRENT_TIMESTAMP(3), ?, 'Phase 1 default operational thresholds')
            ")->execute(array((int)$config['id'], $uuid, $changedBy));
            $versionId = (int)$this->pdo->lastInsertId();
            $this->pdo->prepare('UPDATE ipca_aircraft_operational_configs SET current_version_id = ?, updated_at = CURRENT_TIMESTAMP(3) WHERE id = ?')
                ->execute(array($versionId, (int)$config['id']));
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        return $this->configForAircraft($aircraftId);
    }

    /**
     * @param array<string,mixed> $values
     * @return array<string,mixed>
     */
    public function saveVersion(int $aircraftId, array $values, ?int $changedBy = null): array
    {
        if ($aircraftId <= 0) {
            throw new RuntimeException('Aircraft id is required.');
        }

        $current = $this->configForAircraft($aircraftId);
        $next = array_merge($current, array(
            'hobbs_engine_on_rpm_threshold' => $this->floatValue($values['hobbs_engine_on_rpm_threshold'] ?? null, (float)$current['hobbs_engine_on_rpm_threshold']),
            'hobbs_start_confirm_ms' => $this->intValue($values['hobbs_start_confirm_ms'] ?? null, (int)$current['hobbs_start_confirm_ms']),
            'hobbs_stop_confirm_ms' => $this->intValue($values['hobbs_stop_confirm_ms'] ?? null, (int)$current['hobbs_stop_confirm_ms']),
            'tacho_rpm_threshold' => $this->nullableFloatValue($values['tacho_rpm_threshold'] ?? null),
            'movement_groundspeed_kt' => $this->floatValue($values['movement_groundspeed_kt'] ?? null, (float)$current['movement_groundspeed_kt']),
            'movement_confirm_ms' => $this->intValue($values['movement_confirm_ms'] ?? null, (int)$current['movement_confirm_ms']),
            'fuel_discrepancy_usg' => $this->floatValue($values['fuel_discrepancy_usg'] ?? null, (float)$current['fuel_discrepancy_usg']),
            'timezone_identifier' => trim((string)($values['timezone_identifier'] ?? $current['timezone_identifier'])) ?: 'UTC',
            'change_reason' => substr(trim((string)($values['change_reason'] ?? 'Operational thresholds update')), 0, 512),
        ));

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('SELECT id, current_version_id FROM ipca_aircraft_operational_configs WHERE aircraft_id = ? LIMIT 1 FOR UPDATE');
            $stmt->execute(array($aircraftId));
            $config = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($config)) {
                $this->pdo->prepare('INSERT INTO ipca_aircraft_operational_configs (aircraft_id) VALUES (?)')->execute(array($aircraftId));
                $config = array('id' => (int)$this->pdo->lastInsertId(), 'current_version_id' => null);
            }

            $versionStmt = $this->pdo->prepare('SELECT COALESCE(MAX(version_number), 0) + 1 FROM ipca_aircraft_operational_config_versions WHERE config_id = ?');
            $versionStmt->execute(array((int)$config['id']));
            $nextVersionNumber = max(1, (int)$versionStmt->fetchColumn());
            if (!empty($config['current_version_id'])) {
                $this->pdo->prepare('UPDATE ipca_aircraft_operational_config_versions SET effective_to_utc = CURRENT_TIMESTAMP(3) WHERE id = ?')
                    ->execute(array((int)$config['current_version_id']));
            }

            $uuid = AuditEventService::uuid();
            $insert = $this->pdo->prepare("
                INSERT INTO ipca_aircraft_operational_config_versions
                  (config_id, config_version_uuid, version_number, effective_from_utc,
                   hobbs_engine_on_rpm_threshold, hobbs_start_confirm_ms, hobbs_stop_confirm_ms,
                   tacho_rpm_threshold, movement_groundspeed_kt, movement_confirm_ms,
                   fuel_discrepancy_usg, timezone_identifier, changed_by, change_reason)
                VALUES
                  (?, ?, ?, CURRENT_TIMESTAMP(3), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $insert->execute(array(
                (int)$config['id'],
                $uuid,
                $nextVersionNumber,
                (float)$next['hobbs_engine_on_rpm_threshold'],
                (int)$next['hobbs_start_confirm_ms'],
                (int)$next['hobbs_stop_confirm_ms'],
                $next['tacho_rpm_threshold'],
                (float)$next['movement_groundspeed_kt'],
                (int)$next['movement_confirm_ms'],
                (float)$next['fuel_discrepancy_usg'],
                (string)$next['timezone_identifier'],
                $changedBy,
                (string)$next['change_reason'],
            ));
            $versionId = (int)$this->pdo->lastInsertId();
            $this->pdo->prepare('UPDATE ipca_aircraft_operational_configs SET current_version_id = ?, updated_at = CURRENT_TIMESTAMP(3) WHERE id = ?')
                ->execute(array($versionId, (int)$config['id']));
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return $this->configForAircraft($aircraftId);
    }

    /**
     * @return array<string,mixed>
     */
    private function defaults(): array
    {
        return array(
            'id' => null,
            'config_version_uuid' => 'phase1-default',
            'hobbs_engine_on_rpm_threshold' => 1000.0,
            'hobbs_start_confirm_ms' => 1000,
            'hobbs_stop_confirm_ms' => 5000,
            'tacho_rpm_threshold' => null,
            'movement_groundspeed_kt' => 3.0,
            'movement_confirm_ms' => 3000,
            'fuel_discrepancy_usg' => 1.0,
            'timezone_identifier' => 'UTC',
        );
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function normalize(array $row): array
    {
        return array_merge($this->defaults(), array(
            'id' => (int)$row['id'],
            'config_version_uuid' => (string)$row['config_version_uuid'],
            'hobbs_engine_on_rpm_threshold' => (float)$row['hobbs_engine_on_rpm_threshold'],
            'hobbs_start_confirm_ms' => (int)$row['hobbs_start_confirm_ms'],
            'hobbs_stop_confirm_ms' => (int)$row['hobbs_stop_confirm_ms'],
            'tacho_rpm_threshold' => $row['tacho_rpm_threshold'] !== null ? (float)$row['tacho_rpm_threshold'] : null,
            'movement_groundspeed_kt' => (float)$row['movement_groundspeed_kt'],
            'movement_confirm_ms' => (int)$row['movement_confirm_ms'],
            'fuel_discrepancy_usg' => (float)$row['fuel_discrepancy_usg'],
            'timezone_identifier' => (string)($row['timezone_identifier'] ?? 'UTC'),
        ));
    }

    private function floatValue(mixed $value, float $fallback): float
    {
        return is_numeric($value) ? (float)$value : $fallback;
    }

    private function intValue(mixed $value, int $fallback): int
    {
        return is_numeric($value) ? max(0, (int)$value) : $fallback;
    }

    private function nullableFloatValue(mixed $value): ?float
    {
        $value = trim((string)($value ?? ''));
        return $value === '' || !is_numeric($value) ? null : (float)$value;
    }
}
