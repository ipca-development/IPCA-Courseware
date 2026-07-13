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
}
