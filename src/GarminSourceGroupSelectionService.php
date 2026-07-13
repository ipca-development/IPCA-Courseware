<?php
declare(strict_types=1);

require_once __DIR__ . '/AuditEventService.php';

final class GarminSourceGroupSelectionService
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return array<string,mixed>
     */
    public function selectForGroup(int $sourceGroupId): array
    {
        $sources = $this->sources($sourceGroupId);
        if (!$sources) {
            return array('source_group_id' => $sourceGroupId, 'roles' => array(), 'reason' => 'No Garmin sources in group.');
        }
        $primaryOperational = $this->bestOperationalSource($sources);
        $primaryReplay = $this->bestReplaySource($sources, $primaryOperational);
        $roles = array();
        foreach ($sources as $source) {
            $sourceId = (int)$source['id'];
            if ($primaryOperational !== null && $sourceId === (int)$primaryOperational['id']) {
                $roles[] = $this->assign($sourceGroupId, $sourceId, 'PRIMARY_OPERATIONAL', 'Highest-ranked valid source with operational capabilities.');
            }
            if ($primaryReplay !== null && $sourceId === (int)$primaryReplay['id']) {
                $roles[] = $this->assign($sourceGroupId, $sourceId, 'PRIMARY_REPLAY', 'Highest-ranked valid source with replay/route capabilities.');
            }
            if ((string)($source['data_log_type'] ?? '') === 'GPS_ONLY') {
                $roles[] = $this->assign($sourceGroupId, $sourceId, 'SUPPORTING_GPS', 'GPS-only source retained as complementary same-flight evidence.');
            } elseif ((string)($source['data_log_type'] ?? '') === 'PARTIAL_AVIONICS') {
                $roles[] = $this->assign($sourceGroupId, $sourceId, 'SUPPORTING_AVIONICS', 'Partial avionics source retained as supporting evidence.');
            } elseif (in_array((string)($source['data_log_type'] ?? ''), array('INVALID', 'UNSUPPORTED_FORMAT'), true)) {
                $roles[] = $this->assign($sourceGroupId, $sourceId, 'INVALID_EXCLUDED', 'Source cannot be used for calculations, but original evidence remains retained.');
            } elseif ($primaryOperational !== null && $sourceId !== (int)$primaryOperational['id']) {
                $roles[] = $this->assign($sourceGroupId, $sourceId, 'ALTERNATE', 'Additional supported source retained for comparison.');
            }
        }
        $this->updateGroup($sourceGroupId, $primaryOperational, $primaryReplay, $sources);
        return array(
            'source_group_id' => $sourceGroupId,
            'primary_operational_source_id' => $primaryOperational['id'] ?? null,
            'primary_replay_source_id' => $primaryReplay['id'] ?? null,
            'roles' => $roles,
            'reason' => 'Garmin source selection v1 completed.',
        );
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function sources(int $sourceGroupId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT s.*
            FROM ipca_garmin_source_group_members m
            INNER JOIN ipca_garmin_flight_data_sources s ON s.id = m.garmin_flight_data_source_id
            WHERE m.source_group_id = ?
            ORDER BY
              CASE s.data_log_type
                WHEN 'FULL_AVIONICS' THEN 1
                WHEN 'PARTIAL_AVIONICS' THEN 2
                WHEN 'GPS_ONLY' THEN 3
                WHEN 'UNKNOWN_SUPPORTED' THEN 4
                ELSE 99
              END ASC,
              COALESCE(s.valid_sample_count, 0) DESC,
              s.id ASC
        ");
        $stmt->execute(array($sourceGroupId));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : array();
    }

    /**
     * @param list<array<string,mixed>> $sources
     * @return array<string,mixed>|null
     */
    private function bestOperationalSource(array $sources): ?array
    {
        foreach ($sources as $source) {
            if ((int)($source['supports_operational_flight_record'] ?? 0) !== 1) {
                continue;
            }
            if (in_array((string)($source['data_log_type'] ?? ''), array('FULL_AVIONICS', 'PARTIAL_AVIONICS', 'UNKNOWN_SUPPORTED'), true)) {
                return $source;
            }
        }
        return null;
    }

    /**
     * @param list<array<string,mixed>> $sources
     * @param array<string,mixed>|null $primaryOperational
     * @return array<string,mixed>|null
     */
    private function bestReplaySource(array $sources, ?array $primaryOperational): ?array
    {
        if ($primaryOperational !== null && (int)($primaryOperational['supports_full_replay'] ?? 0) === 1) {
            return $primaryOperational;
        }
        foreach ($sources as $source) {
            if ((int)($source['supports_full_replay'] ?? 0) === 1 || (int)($source['supports_gps_replay'] ?? 0) === 1) {
                return $source;
            }
        }
        return null;
    }

    /**
     * @return array<string,mixed>
     */
    private function assign(int $sourceGroupId, int $sourceId, string $role, string $reason): array
    {
        $stmt = $this->pdo->prepare("
            INSERT IGNORE INTO ipca_garmin_source_role_assignments
              (role_assignment_uuid, source_group_id, garmin_flight_data_source_id, source_role, selection_reason)
            VALUES
              (?, ?, ?, ?, ?)
        ");
        $stmt->execute(array(AuditEventService::uuid(), $sourceGroupId, $sourceId, $role, $reason));
        $this->pdo->prepare('UPDATE ipca_garmin_flight_data_sources SET source_role = COALESCE(source_role, ?), updated_at = CURRENT_TIMESTAMP(3) WHERE id = ?')
            ->execute(array($role, $sourceId));
        return array('source_id' => $sourceId, 'role' => $role, 'reason' => $reason);
    }

    /**
     * @param array<string,mixed>|null $primaryOperational
     * @param array<string,mixed>|null $primaryReplay
     * @param list<array<string,mixed>> $sources
     */
    private function updateGroup(int $sourceGroupId, ?array $primaryOperational, ?array $primaryReplay, array $sources): void
    {
        $start = null;
        $end = null;
        foreach ($sources as $source) {
            $sourceStart = (string)($source['csv_first_timestamp_utc'] ?? '');
            $sourceEnd = (string)($source['csv_last_timestamp_utc'] ?? '');
            if ($sourceStart !== '' && ($start === null || $sourceStart < $start)) {
                $start = $sourceStart;
            }
            if ($sourceEnd !== '' && ($end === null || $sourceEnd > $end)) {
                $end = $sourceEnd;
            }
        }
        $this->pdo->prepare("
            UPDATE ipca_garmin_source_groups
            SET primary_operational_source_id = ?,
                primary_replay_source_id = ?,
                union_coverage_start_utc = ?,
                union_coverage_end_utc = ?,
                source_selection_reason = ?,
                updated_at = CURRENT_TIMESTAMP(3)
            WHERE id = ?
        ")->execute(array(
            $primaryOperational['id'] ?? null,
            $primaryReplay['id'] ?? null,
            $start,
            $end,
            'Source ranking: FULL_AVIONICS > PARTIAL_AVIONICS > GPS_ONLY for operations; replay may use best valid GPS coverage.',
            $sourceGroupId,
        ));
    }
}
