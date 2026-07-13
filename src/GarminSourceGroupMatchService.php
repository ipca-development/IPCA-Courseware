<?php
declare(strict_types=1);

require_once __DIR__ . '/AuditEventService.php';
require_once __DIR__ . '/GarminProviderStateService.php';

final class GarminSourceGroupMatchService
{
    public function __construct(private PDO $pdo, private string $providerName = 'flygarmin_web')
    {
    }

    /**
     * @return array<string,mixed>
     */
    public function matchGroup(int $sourceGroupId): array
    {
        $group = $this->group($sourceGroupId);
        if ($group === null) {
            throw new RuntimeException('Garmin source group not found.');
        }
        $sources = $this->sources($sourceGroupId);
        $sessionId = $this->bestSessionFromCsvMatches($sources);
        $method = 'csv_source_matches';
        $confidence = $sessionId > 0 ? 0.9500 : 0.0000;
        if ($sessionId <= 0) {
            $sessionId = $this->bestSessionFromGroupWindow($group, $sources);
            $method = $sessionId > 0 ? 'group_aircraft_time_window' : 'review_required_no_candidate';
            $confidence = $sessionId > 0 ? 0.8800 : 0.0000;
        }
        $status = $sessionId > 0 ? 'matched' : 'needs_admin_review';
        $this->pdo->prepare("
            UPDATE ipca_garmin_source_groups
            SET matched_flight_session_id = ?,
                group_match_status = ?,
                group_match_confidence = ?,
                updated_at = CURRENT_TIMESTAMP(3)
            WHERE id = ?
        ")->execute(array($sessionId > 0 ? $sessionId : null, $status, $confidence, $sourceGroupId));
        $this->pdo->prepare("
            UPDATE ipca_garmin_flight_data_sources s
            INNER JOIN ipca_garmin_source_group_members m ON m.garmin_flight_data_source_id = s.id
            SET s.matched_flight_session_id = ?,
                s.match_status = ?,
                s.match_confidence = ?,
                s.updated_at = CURRENT_TIMESTAMP(3)
            WHERE m.source_group_id = ?
        ")->execute(array($sessionId > 0 ? $sessionId : null, $status, $confidence, $sourceGroupId));
        (new GarminProviderStateService($this->pdo, $this->providerName))
            ->markAcceptanceCheck('session_match_or_expected_review', true, $status === 'matched' ? 'Garmin source group matched to Flight Session.' : 'Garmin source group produced expected review-required status.');
        return array('status' => $status, 'session_id' => $sessionId, 'confidence' => $confidence, 'method' => $method, 'source_group_id' => $sourceGroupId);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function group(int $sourceGroupId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ipca_garmin_source_groups WHERE id = ? LIMIT 1');
        $stmt->execute(array($sourceGroupId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
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
        ");
        $stmt->execute(array($sourceGroupId));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : array();
    }

    /**
     * @param list<array<string,mixed>> $sources
     */
    private function bestSessionFromCsvMatches(array $sources): int
    {
        $csvFileIds = array_values(array_filter(array_map(static fn(array $source): int => (int)($source['garmin_csv_file_id'] ?? 0), $sources)));
        if (!$csvFileIds) {
            return 0;
        }
        $placeholders = implode(',', array_fill(0, count($csvFileIds), '?'));
        $stmt = $this->pdo->prepare("
            SELECT session_id, COUNT(*) AS votes, MAX(confidence) AS confidence
            FROM ipca_garmin_csv_session_matches
            WHERE csv_file_id IN ({$placeholders})
              AND match_status = 'matched'
              AND session_id IS NOT NULL
            GROUP BY session_id
            ORDER BY votes DESC, confidence DESC
            LIMIT 1
        ");
        $stmt->execute($csvFileIds);
        return (int)($stmt->fetchColumn() ?: 0);
    }

    /**
     * @param array<string,mixed> $group
     * @param list<array<string,mixed>> $sources
     */
    private function bestSessionFromGroupWindow(array $group, array $sources): int
    {
        $entry = $this->entryForGroup($group);
        $registration = strtoupper(trim((string)($entry['aircraft_registration'] ?? '')));
        $start = null;
        $end = null;
        foreach ($sources as $source) {
            $sourceStart = trim((string)($source['csv_first_timestamp_utc'] ?? ''));
            $sourceEnd = trim((string)($source['csv_last_timestamp_utc'] ?? ''));
            if ($sourceStart !== '' && ($start === null || $sourceStart < $start)) {
                $start = $sourceStart;
            }
            if ($sourceEnd !== '' && ($end === null || $sourceEnd > $end)) {
                $end = $sourceEnd;
            }
        }
        if ($registration === '' || $start === null || $end === null) {
            return 0;
        }
        $stmt = $this->pdo->prepare("
            SELECT id
            FROM ipca_flight_sessions
            WHERE UPPER(aircraft_registration) = ?
              AND (
                (avionics_on_utc IS NULL OR avionics_on_utc <= DATE_ADD(?, INTERVAL 15 MINUTE))
                AND (avionics_off_utc IS NULL OR avionics_off_utc >= DATE_SUB(?, INTERVAL 15 MINUTE))
              )
            ORDER BY ABS(TIMESTAMPDIFF(SECOND, COALESCE(avionics_on_utc, created_at), ?)) ASC, id ASC
            LIMIT 1
        ");
        $stmt->execute(array($registration, $end, $start, $start));
        return (int)($stmt->fetchColumn() ?: 0);
    }

    /**
     * @param array<string,mixed> $group
     * @return array<string,mixed>
     */
    private function entryForGroup(array $group): array
    {
        $entryId = (int)($group['garmin_logbook_entry_id'] ?? 0);
        if ($entryId <= 0) {
            return array();
        }
        $stmt = $this->pdo->prepare('SELECT * FROM ipca_garmin_logbook_entries WHERE id = ? LIMIT 1');
        $stmt->execute(array($entryId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : array();
    }
}
