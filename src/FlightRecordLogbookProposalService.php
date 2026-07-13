<?php
declare(strict_types=1);

require_once __DIR__ . '/AuditEventService.php';

final class FlightRecordLogbookProposalService
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @param array<string,mixed> $values
     * @return array<string,mixed>
     */
    public function createProposal(
        int $sessionId,
        int $flightRecordVersionId,
        ?int $legVersionId,
        int $ownerUserId,
        int $durationMs,
        array $values,
        string $entryType = 'student_flight'
    ): array {
        if ($durationMs < 0) {
            throw new RuntimeException('Proposal duration cannot be negative.');
        }
        $this->pdo->beginTransaction();
        try {
            $groupId = $this->ensureGroup($sessionId, $flightRecordVersionId, $ownerUserId, $entryType, $durationMs);
            $stmt = $this->pdo->prepare("
                INSERT INTO ipca_flight_record_logbook_proposals
                  (proposal_uuid, proposal_group_id, flight_record_version_id, leg_version_id, owner_user_id,
                   entry_type, proposed_duration_ms, proposed_values_json)
                VALUES
                  (:proposal_uuid, :proposal_group_id, :flight_record_version_id, :leg_version_id, :owner_user_id,
                   :entry_type, :proposed_duration_ms, :proposed_values_json)
            ");
            $stmt->execute(array(
                ':proposal_uuid' => AuditEventService::uuid(),
                ':proposal_group_id' => $groupId,
                ':flight_record_version_id' => $flightRecordVersionId,
                ':leg_version_id' => $legVersionId,
                ':owner_user_id' => $ownerUserId,
                ':entry_type' => substr($entryType, 0, 64),
                ':proposed_duration_ms' => $durationMs,
                ':proposed_values_json' => AuditEventService::jsonEncode($values),
            ));
            $proposalId = (int)$this->pdo->lastInsertId();
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        return $this->proposalById($proposalId) ?? array();
    }

    public function acceptProposal(int $proposalId, int $logbookEntryId, int $acceptedBy): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->acceptProposalWithinTransaction($proposalId, $logbookEntryId, $acceptedBy);
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function acceptProposalWithinTransaction(int $proposalId, int $logbookEntryId, int $acceptedBy): void
    {
        $stmt = $this->pdo->prepare("
            SELECT p.*, g.session_id, g.allowed_duration_ms, g.accepted_duration_ms
            FROM ipca_flight_record_logbook_proposals p
            INNER JOIN ipca_logbook_proposal_groups g ON g.id = p.proposal_group_id
            WHERE p.id = ?
            LIMIT 1
            FOR UPDATE
        ");
        $stmt->execute(array($proposalId));
        $proposal = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($proposal)) {
            throw new RuntimeException('Logbook proposal not found.');
        }
        $groupId = (int)$proposal['proposal_group_id'];
        $this->pdo->prepare('SELECT id FROM ipca_logbook_proposal_groups WHERE id = ? FOR UPDATE')->execute(array($groupId));
        $duration = (int)$proposal['proposed_duration_ms'];
        $acceptedTotal = (int)$proposal['accepted_duration_ms'] + $duration;
        if ($acceptedTotal > (int)$proposal['allowed_duration_ms']) {
            throw new RuntimeException('Accepted logbook proposal duration would exceed the Flight Record allocation.');
        }
        $identity = hash('sha256', implode('|', array(
            (int)$proposal['owner_user_id'],
            (int)$proposal['session_id'],
            (int)($proposal['leg_version_id'] ?? 0),
            (string)$proposal['entry_type'],
        )));
        $this->pdo->prepare("
            INSERT INTO ipca_accepted_logbook_proposal_links
              (accepted_link_uuid, accepted_identity_key, proposal_group_id, proposal_id, logbook_entry_id, accepted_duration_ms, accepted_by)
            VALUES
              (?, ?, ?, ?, ?, ?, ?)
        ")->execute(array(AuditEventService::uuid(), $identity, $groupId, $proposalId, $logbookEntryId, $duration, $acceptedBy));
        $this->pdo->prepare("
            UPDATE ipca_flight_record_logbook_proposals
            SET status = 'ACCEPTED', target_entry_id = ?, updated_at = CURRENT_TIMESTAMP(3)
            WHERE id = ?
        ")->execute(array($logbookEntryId, $proposalId));
        $this->pdo->prepare("
            UPDATE ipca_logbook_proposal_groups
            SET accepted_duration_ms = accepted_duration_ms + ?, updated_at = CURRENT_TIMESTAMP(3)
            WHERE id = ?
        ")->execute(array($duration, $groupId));
    }

    private function ensureGroup(int $sessionId, int $flightRecordVersionId, int $ownerUserId, string $entryType, int $allowedDurationMs): int
    {
        $stmt = $this->pdo->prepare("
            SELECT id
            FROM ipca_logbook_proposal_groups
            WHERE session_id = ? AND flight_record_version_id = ? AND owner_user_id = ? AND entry_type = ?
            LIMIT 1
            FOR UPDATE
        ");
        $stmt->execute(array($sessionId, $flightRecordVersionId, $ownerUserId, $entryType));
        $existing = (int)($stmt->fetchColumn() ?: 0);
        if ($existing > 0) {
            return $existing;
        }
        $stmt = $this->pdo->prepare("
            INSERT INTO ipca_logbook_proposal_groups
              (proposal_group_uuid, session_id, flight_record_version_id, owner_user_id, entry_type, allowed_duration_ms)
            VALUES
              (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute(array(AuditEventService::uuid(), $sessionId, $flightRecordVersionId, $ownerUserId, $entryType, $allowedDurationMs));
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * @return array<string,mixed>|null
     */
    private function proposalById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ipca_flight_record_logbook_proposals WHERE id = ? LIMIT 1');
        $stmt->execute(array($id));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }
}
