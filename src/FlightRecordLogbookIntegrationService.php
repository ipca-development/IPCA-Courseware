<?php
declare(strict_types=1);

require_once __DIR__ . '/FlightRecordLogbookProposalService.php';
require_once __DIR__ . '/flight_training/AdminLogbookService.php';

final class FlightRecordLogbookIntegrationService
{
    public function __construct(private PDO $pdo)
    {
    }

    public function acceptProposalToOfficialLogbook(int $proposalId, int $actorUserId): int
    {
        $proposal = $this->proposal($proposalId);
        if ($proposal === null) {
            throw new RuntimeException('Flight Record logbook proposal not found.');
        }
        if ((string)($proposal['status'] ?? '') === 'ACCEPTED' && !empty($proposal['target_entry_id'])) {
            return (int)$proposal['target_entry_id'];
        }

        $adminLogbook = new AdminLogbookService($this->pdo);
        $logbookId = $adminLogbook->getOrCreateLogbook((int)$proposal['owner_user_id'], null, $actorUserId);
        $entry = $this->entryPayload($proposal);

        $this->pdo->beginTransaction();
        try {
            $saved = $adminLogbook->saveEntry($logbookId, $entry, $actorUserId);
            $entryId = (int)($saved['id'] ?? 0);
            if ($entryId <= 0) {
                throw new RuntimeException('Could not create official logbook entry from Flight Record proposal.');
            }
            $adminLogbook->acceptEntries($logbookId, array($entryId), $actorUserId);
            (new FlightRecordLogbookProposalService($this->pdo))->acceptProposalWithinTransaction($proposalId, $entryId, $actorUserId);
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return $entryId;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function proposal(int $proposalId): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT
                p.*,
                g.session_id,
                s.aircraft_registration,
                lv.departure_airport_code,
                lv.arrival_airport_code,
                lv.administrative_departure_utc,
                lv.administrative_arrival_utc,
                lv.takeoff_utc,
                lv.landing_utc,
                lv.night_duration_ms,
                lv.cross_country_easa_qualified,
                lv.landing_event_count
            FROM ipca_flight_record_logbook_proposals p
            INNER JOIN ipca_logbook_proposal_groups g ON g.id = p.proposal_group_id
            INNER JOIN ipca_flight_sessions s ON s.id = g.session_id
            LEFT JOIN ipca_operational_flight_leg_versions lv ON lv.id = p.leg_version_id
            WHERE p.id = ?
            LIMIT 1
            FOR UPDATE
        ");
        $stmt->execute(array($proposalId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string,mixed> $proposal
     * @return array<string,mixed>
     */
    private function entryPayload(array $proposal): array
    {
        $values = json_decode((string)($proposal['proposed_values_json'] ?? '{}'), true);
        $values = is_array($values) ? $values : array();
        $durationHours = round(((int)$proposal['proposed_duration_ms']) / 3600000, 2);
        $departureUtc = (string)($proposal['administrative_departure_utc'] ?: $proposal['takeoff_utc'] ?: '');
        $arrivalUtc = (string)($proposal['administrative_arrival_utc'] ?: $proposal['landing_utc'] ?: '');
        return array_merge(array(
            'external_system' => 'IPCA_FLIGHT_RECORD',
            'external_id' => (string)$proposal['proposal_uuid'],
            'import_profile' => 'flight_record_proposal',
            'source_hash' => hash('sha256', (string)$proposal['proposal_uuid']),
            'sync_status' => 'accepted_from_flight_record',
            'entry_date' => $departureUtc !== '' ? substr($departureUtc, 0, 10) : gmdate('Y-m-d'),
            'departure_airport' => (string)($proposal['departure_airport_code'] ?? ''),
            'departure_time' => $departureUtc !== '' ? substr($departureUtc, 11, 5) : null,
            'arrival_airport' => (string)($proposal['arrival_airport_code'] ?? ''),
            'arrival_time' => $arrivalUtc !== '' ? substr($arrivalUtc, 11, 5) : null,
            'aircraft_registration' => (string)($proposal['aircraft_registration'] ?? ''),
            'total_flight_time' => $durationHours,
            'single_engine_time' => $durationHours,
            'cross_country_time' => !empty($proposal['cross_country_easa_qualified']) ? $durationHours : 0,
            'night_time' => is_numeric($proposal['night_duration_ms'] ?? null) ? round(((float)$proposal['night_duration_ms']) / 3600000, 2) : 0,
            'day_landings' => (int)($proposal['landing_event_count'] ?? 0),
            'review_status' => 'needs_review',
            'remarks' => 'Imported from IPCA Flight Record proposal.',
            'metadata' => array(
                'source' => 'ipca_flight_record_proposal',
                'proposal_uuid' => (string)$proposal['proposal_uuid'],
                'flight_record_version_id' => (int)$proposal['flight_record_version_id'],
                'leg_version_id' => isset($proposal['leg_version_id']) ? (int)$proposal['leg_version_id'] : null,
                'duration_source' => 'allocated_hobbs_duration_ms',
            ),
        ), $values);
    }
}
