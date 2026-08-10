<?php
declare(strict_types=1);

require_once __DIR__ . '/MasterLogbookLogbookProposalService.php';
require_once __DIR__ . '/flight_training/AdminLogbookService.php';

/**
 * Accept a Master Logbook CVR logbook proposal into the owner's official individual logbook.
 */
final class CvrLogbookProposalAcceptService
{
    public function __construct(private PDO $pdo)
    {
    }

    public function accept(int $proposalId, int $actorUserId, bool $actorIsAdmin = false): int
    {
        if ($proposalId <= 0) {
            throw new RuntimeException('Proposal id is required.');
        }
        if ($actorUserId <= 0) {
            throw new RuntimeException('Actor is required to accept a proposal.');
        }

        $proposals = new MasterLogbookLogbookProposalService($this->pdo);
        if (!$proposals->schemaAvailable()) {
            throw new RuntimeException('Apply scripts/sql/2026_08_08_cvr_master_logbook_proposals.sql first.');
        }

        $proposal = $proposals->proposalById($proposalId);
        if ($proposal === null) {
            throw new RuntimeException('Logbook proposal not found.');
        }

        $ownerUserId = (int)($proposal['owner_user_id'] ?? 0);
        if ($ownerUserId <= 0) {
            throw new RuntimeException('Proposal owner is missing.');
        }
        if (!$actorIsAdmin && $actorUserId !== $ownerUserId) {
            throw new RuntimeException('Only the proposal owner (or an admin) can accept this entry.');
        }

        $status = strtoupper(trim((string)($proposal['status'] ?? '')));
        if ($status === 'ACCEPTED' && !empty($proposal['target_entry_id'])) {
            return (int)$proposal['target_entry_id'];
        }

        $values = json_decode((string)($proposal['proposed_values_json'] ?? '{}'), true);
        $values = is_array($values) ? $values : array();
        $entry = $this->entryPayload($proposal, $values);

        $adminLogbook = new AdminLogbookService($this->pdo);
        $logbookId = $adminLogbook->getOrCreateLogbook($ownerUserId, null, $actorUserId);

        $this->pdo->beginTransaction();
        try {
            $saved = $adminLogbook->saveEntry($logbookId, $entry, $actorUserId);
            $entryId = (int)($saved['id'] ?? 0);
            if ($entryId <= 0) {
                throw new RuntimeException('Could not create official logbook entry from proposal.');
            }
            $adminLogbook->acceptEntries($logbookId, array($entryId), $actorUserId);

            $update = $this->pdo->prepare(
                'UPDATE ipca_cvr_logbook_proposals
                 SET status = \'ACCEPTED\',
                     target_entry_id = ?,
                     accepted_at = CURRENT_TIMESTAMP(3),
                     accepted_by = ?,
                     updated_at = CURRENT_TIMESTAMP(3)
                 WHERE id = ?'
            );
            $update->execute(array($entryId, $actorUserId, $proposalId));
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return $entryId;
    }

    /**
     * @param array<string,mixed> $proposal
     * @param array<string,mixed> $values
     * @return array<string,mixed>
     */
    private function entryPayload(array $proposal, array $values): array
    {
        $durationHours = round(((int)($proposal['proposed_duration_ms'] ?? 0)) / 3600000, 2);
        $metadata = is_array($values['metadata'] ?? null) ? $values['metadata'] : array();
        $metadata['source'] = 'ipca_cvr_master_logbook_proposal';
        $metadata['proposal_uuid'] = (string)($proposal['proposal_uuid'] ?? '');
        $metadata['dispatch_id'] = (int)($proposal['dispatch_id'] ?? 0);
        $metadata['workflow_flight_record_uuid'] = (string)($proposal['workflow_flight_record_uuid'] ?? '');

        return array_merge(array(
            'external_system' => 'IPCA_CVR_MASTER_LOGBOOK',
            'external_id' => (string)($proposal['proposal_uuid'] ?? ''),
            'import_profile' => 'cvr_master_logbook_proposal',
            'source_hash' => hash('sha256', (string)($proposal['proposal_uuid'] ?? '')),
            'sync_status' => 'accepted_from_cvr_master_logbook',
            'entry_date' => (string)($values['entry_date'] ?? gmdate('Y-m-d')),
            'departure_airport' => (string)($values['departure_airport'] ?? ''),
            'departure_time' => $values['departure_time'] ?? null,
            'arrival_airport' => (string)($values['arrival_airport'] ?? ''),
            'arrival_time' => $values['arrival_time'] ?? null,
            'aircraft_registration' => (string)($values['aircraft_registration'] ?? ''),
            'total_flight_time' => (float)($values['total_flight_time'] ?? $durationHours),
            'single_engine_time' => (float)($values['single_engine_time'] ?? $durationHours),
            'dual_received_time' => (float)($values['dual_received_time'] ?? 0),
            'pic_time' => (float)($values['pic_time'] ?? 0),
            'instructor_name' => (string)($values['instructor_name'] ?? ''),
            'review_status' => 'accepted',
            'remarks' => (string)($values['remarks'] ?? 'Accepted from Master Logbook CVR proposal.'),
            'metadata' => $metadata,
        ), array_diff_key($values, array('metadata' => true)));
    }
}
