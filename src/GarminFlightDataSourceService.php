<?php
declare(strict_types=1);

require_once __DIR__ . '/AuditEventService.php';

final class GarminFlightDataSourceService
{
    public function __construct(private PDO $pdo, private string $providerName = 'flygarmin_web')
    {
    }

    /**
     * @param array<string,mixed> $entry
     * @return array{entry_id:int,source_ids:list<int>,group_id:int}
     */
    public function upsertEntryWithSources(array $entry): array
    {
        $entryUuid = $this->extractUuid($entry, array('uuid', 'id', 'entryUUID', 'logbookEntryUUID'));
        if ($entryUuid === '') {
            throw new RuntimeException('Garmin entry is missing a stable UUID.');
        }
        $sources = $this->extractFlightDataLogUuids($entry);
        $this->pdo->beginTransaction();
        try {
            $entryId = $this->upsertEntry($entryUuid, $entry);
            $sourceIds = array();
            foreach ($sources as $index => $sourceUuid) {
                $sourceId = $this->upsertSource($sourceUuid, $entryId, $entry);
                $sourceIds[] = $sourceId;
                $this->linkEntrySource($entryId, $sourceId, $index);
            }
            $groupId = $this->ensureSourceGroup($entryId, $entryUuid);
            foreach ($sourceIds as $sourceId) {
                $this->linkGroupSource($groupId, $sourceId);
            }
            $this->pdo->commit();
            return array('entry_id' => $entryId, 'source_ids' => $sourceIds, 'group_id' => $groupId);
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function pendingDownloads(int $limit = 20): array
    {
        $stmt = $this->pdo->prepare("
            SELECT *
            FROM ipca_garmin_flight_data_sources
            WHERE provider_name = ?
              AND download_status IN ('pending','failed')
              AND (next_retry_at IS NULL OR next_retry_at <= CURRENT_TIMESTAMP(3))
            ORDER BY created_at ASC
            LIMIT " . max(1, min(100, $limit))
        );
        $stmt->execute(array($this->providerName));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : array();
    }

    /**
     * @return array<string,mixed>|null
     */
    public function sourceByUuid(string $flightDataLogUuid): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ipca_garmin_flight_data_sources WHERE provider_name = ? AND flight_data_log_uuid = ? LIMIT 1');
        $stmt->execute(array($this->providerName, $flightDataLogUuid));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function recentEntries(int $limit = 30): array
    {
        $stmt = $this->pdo->prepare("
            SELECT e.*,
                   g.id AS source_group_id,
                   g.source_group_uuid,
                   g.group_match_status,
                   g.group_match_confidence,
                   g.matched_flight_session_id,
                   g.primary_operational_source_id,
                   g.primary_replay_source_id
            FROM ipca_garmin_logbook_entries e
            LEFT JOIN ipca_garmin_source_groups g ON g.garmin_logbook_entry_id = e.id
            WHERE e.provider_name = ?
            ORDER BY COALESCE(e.generated_track_start_utc, e.created_at) DESC, e.id DESC
            LIMIT " . max(1, min(100, $limit))
        );
        $stmt->execute(array($this->providerName));
        $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($entries) || !$entries) {
            return array();
        }
        $entryIds = array_map(static fn(array $row): int => (int)$row['id'], $entries);
        $sourcesByEntry = $this->sourcesForEntryIds($entryIds);
        foreach ($entries as &$entry) {
            $entry['sources'] = $sourcesByEntry[(int)$entry['id']] ?? array();
        }
        unset($entry);
        return $entries;
    }

    /**
     * @param list<int> $entryIds
     * @return array<int,list<array<string,mixed>>>
     */
    private function sourcesForEntryIds(array $entryIds): array
    {
        if (!$entryIds) {
            return array();
        }
        $placeholders = implode(',', array_fill(0, count($entryIds), '?'));
        $stmt = $this->pdo->prepare("
            SELECT l.garmin_logbook_entry_id, s.*, r.source_role AS assigned_role
            FROM ipca_garmin_logbook_entry_data_logs l
            INNER JOIN ipca_garmin_flight_data_sources s ON s.id = l.garmin_flight_data_source_id
            LEFT JOIN ipca_garmin_source_groups g ON g.garmin_logbook_entry_id = l.garmin_logbook_entry_id
            LEFT JOIN ipca_garmin_source_role_assignments r ON r.source_group_id = g.id AND r.garmin_flight_data_source_id = s.id
            WHERE l.garmin_logbook_entry_id IN ({$placeholders})
            ORDER BY l.garmin_logbook_entry_id ASC, l.position_index ASC, s.id ASC
        ");
        $stmt->execute($entryIds);
        $grouped = array();
        while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            $grouped[(int)$row['garmin_logbook_entry_id']][] = $row;
        }
        return $grouped;
    }

    /**
     * @param array<string,mixed> $entry
     */
    private function upsertEntry(string $entryUuid, array $entry): int
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO ipca_garmin_logbook_entries
              (provider_name, garmin_entry_uuid, garmin_version, entry_date, aircraft_registration,
               aircraft_type_uuid, generated_track_start_utc, generated_track_stop_utc, generating_device_name,
               canonical_track_uuid, provisional, locked_at, deleted_at, raw_entry_json, first_seen_at, last_seen_at)
            VALUES
              (:provider_name, :garmin_entry_uuid, :garmin_version, :entry_date, :aircraft_registration,
               :aircraft_type_uuid, :generated_track_start_utc, :generated_track_stop_utc, :generating_device_name,
               :canonical_track_uuid, :provisional, :locked_at, :deleted_at, :raw_entry_json, CURRENT_TIMESTAMP(3), CURRENT_TIMESTAMP(3))
            ON DUPLICATE KEY UPDATE
              garmin_version = VALUES(garmin_version),
              entry_date = VALUES(entry_date),
              aircraft_registration = VALUES(aircraft_registration),
              aircraft_type_uuid = VALUES(aircraft_type_uuid),
              generated_track_start_utc = VALUES(generated_track_start_utc),
              generated_track_stop_utc = VALUES(generated_track_stop_utc),
              generating_device_name = VALUES(generating_device_name),
              canonical_track_uuid = VALUES(canonical_track_uuid),
              provisional = VALUES(provisional),
              locked_at = VALUES(locked_at),
              deleted_at = VALUES(deleted_at),
              raw_entry_json = VALUES(raw_entry_json),
              last_seen_at = CURRENT_TIMESTAMP(3),
              updated_at = CURRENT_TIMESTAMP(3)
        ");
        $stmt->execute(array(
            ':provider_name' => $this->providerName,
            ':garmin_entry_uuid' => $entryUuid,
            ':garmin_version' => isset($entry['version']) ? (string)$entry['version'] : null,
            ':entry_date' => $this->dateOnly($entry['date'] ?? ($entry['entryDate'] ?? null)),
            ':aircraft_registration' => $this->registration($entry),
            ':aircraft_type_uuid' => $this->extractUuid($entry, array('aircraftTypeUUID', 'aircraftTypeUuid')),
            ':generated_track_start_utc' => $this->dateTime($entry['generatedTrackStart'] ?? ($entry['trackStart'] ?? null)),
            ':generated_track_stop_utc' => $this->dateTime($entry['generatedTrackStop'] ?? ($entry['trackStop'] ?? null)),
            ':generating_device_name' => isset($entry['generatingDeviceName']) ? (string)$entry['generatingDeviceName'] : null,
            ':canonical_track_uuid' => $this->extractUuid($entry, array('canonicalTrackUUID', 'canonicalTrackUuid')),
            ':provisional' => !empty($entry['provisional']) ? 1 : 0,
            ':locked_at' => $this->dateTime($entry['lockedAt'] ?? null),
            ':deleted_at' => $this->dateTime($entry['deletedAt'] ?? null),
            ':raw_entry_json' => AuditEventService::jsonEncode($entry),
        ));
        $stmt = $this->pdo->prepare('SELECT id FROM ipca_garmin_logbook_entries WHERE provider_name = ? AND garmin_entry_uuid = ? LIMIT 1');
        $stmt->execute(array($this->providerName, $entryUuid));
        $entryId = (int)$stmt->fetchColumn();
        $this->insertEntryVersion($entryId, $entry);
        return $entryId;
    }

    /**
     * @param array<string,mixed> $entry
     */
    private function insertEntryVersion(int $entryId, array $entry): void
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO ipca_garmin_logbook_entry_versions
              (garmin_logbook_entry_id, version_uuid, garmin_version, raw_entry_json, change_reason)
            VALUES
              (?, ?, ?, ?, 'sync')
        ");
        $stmt->execute(array($entryId, AuditEventService::uuid(), isset($entry['version']) ? (string)$entry['version'] : null, AuditEventService::jsonEncode($entry)));
    }

    /**
     * @param array<string,mixed> $entry
     */
    private function upsertSource(string $sourceUuid, int $entryId, array $entry): int
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO ipca_garmin_flight_data_sources
              (provider_name, flight_data_log_uuid, garmin_logbook_entry_id, download_status, import_status, match_status)
            VALUES
              (?, ?, ?, 'pending', 'pending', 'pending')
            ON DUPLICATE KEY UPDATE
              garmin_logbook_entry_id = VALUES(garmin_logbook_entry_id),
              updated_at = CURRENT_TIMESTAMP(3)
        ");
        $stmt->execute(array($this->providerName, $sourceUuid, $entryId));
        $stmt = $this->pdo->prepare('SELECT id FROM ipca_garmin_flight_data_sources WHERE provider_name = ? AND flight_data_log_uuid = ? LIMIT 1');
        $stmt->execute(array($this->providerName, $sourceUuid));
        return (int)$stmt->fetchColumn();
    }

    private function linkEntrySource(int $entryId, int $sourceId, int $position): void
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO ipca_garmin_logbook_entry_data_logs
              (garmin_logbook_entry_id, garmin_flight_data_source_id, position_index)
            VALUES
              (?, ?, ?)
            ON DUPLICATE KEY UPDATE position_index = VALUES(position_index)
        ");
        $stmt->execute(array($entryId, $sourceId, $position));
    }

    private function ensureSourceGroup(int $entryId, string $entryUuid): int
    {
        $lookup = $this->pdo->prepare('SELECT id FROM ipca_garmin_source_groups WHERE provider_name = ? AND garmin_logbook_entry_id = ? LIMIT 1');
        $lookup->execute(array($this->providerName, $entryId));
        $existingId = (int)$lookup->fetchColumn();
        if ($existingId > 0) {
            return $existingId;
        }
        $stmt = $this->pdo->prepare("
            INSERT INTO ipca_garmin_source_groups
              (source_group_uuid, provider_name, garmin_logbook_entry_id, garmin_entry_uuid)
            VALUES
              (?, ?, ?, ?)
        ");
        $stmt->execute(array(AuditEventService::uuid(), $this->providerName, $entryId, $entryUuid));
        $stmt = $this->pdo->prepare('SELECT id FROM ipca_garmin_source_groups WHERE provider_name = ? AND garmin_logbook_entry_id = ? LIMIT 1');
        $stmt->execute(array($this->providerName, $entryId));
        $groupId = (int)$stmt->fetchColumn();
        if ($groupId <= 0) {
            throw new RuntimeException('Could not create Garmin source group.');
        }
        return $groupId;
    }

    private function linkGroupSource(int $groupId, int $sourceId): void
    {
        $stmt = $this->pdo->prepare("
            INSERT IGNORE INTO ipca_garmin_source_group_members
              (source_group_id, garmin_flight_data_source_id, member_status)
            VALUES
              (?, ?, 'active')
        ");
        $stmt->execute(array($groupId, $sourceId));
    }

    /**
     * @param array<string,mixed> $entry
     * @return list<string>
     */
    private function extractFlightDataLogUuids(array $entry): array
    {
        $raw = $entry['flightDataLogUUIDs'] ?? ($entry['flightDataLogUuids'] ?? ($entry['flightDataLogs'] ?? array()));
        if (is_string($raw)) {
            $raw = array($raw);
        }
        $uuids = array();
        if (is_array($raw)) {
            foreach ($raw as $item) {
                if (is_string($item)) {
                    $uuid = $item;
                } elseif (is_array($item)) {
                    $uuid = $this->extractUuid($item, array('uuid', 'flightDataLogUUID', 'flightDataLogUuid', 'id'));
                } else {
                    $uuid = '';
                }
                $uuid = strtolower(trim($uuid));
                if ($uuid !== '' && preg_match('/^[a-f0-9-]{36}$/', $uuid) === 1) {
                    $uuids[$uuid] = $uuid;
                }
            }
        }
        return array_values($uuids);
    }

    /**
     * @param array<string,mixed> $row
     * @param list<string> $keys
     */
    private function extractUuid(array $row, array $keys): string
    {
        foreach ($keys as $key) {
            $value = strtolower(trim((string)($row[$key] ?? '')));
            if (preg_match('/^[a-f0-9-]{36}$/', $value) === 1) {
                return $value;
            }
        }
        return '';
    }

    /**
     * @param array<string,mixed> $entry
     */
    private function registration(array $entry): ?string
    {
        $value = trim((string)($entry['aircraftRegistration'] ?? ($entry['aircraftTailNumber'] ?? ($entry['aircraftIdent'] ?? ''))));
        return $value !== '' ? strtoupper($value) : null;
    }

    private function dateOnly(mixed $value): ?string
    {
        if ($value === null || trim((string)$value) === '') {
            return null;
        }
        try {
            return (new DateTimeImmutable((string)$value))->format('Y-m-d');
        } catch (Throwable) {
            return null;
        }
    }

    private function dateTime(mixed $value): ?string
    {
        if ($value === null || trim((string)$value) === '') {
            return null;
        }
        try {
            return (new DateTimeImmutable((string)$value))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
        } catch (Throwable) {
            return null;
        }
    }
}
