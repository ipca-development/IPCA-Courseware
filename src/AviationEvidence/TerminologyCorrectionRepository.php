<?php
declare(strict_types=1);

require_once __DIR__ . '/EvidenceSchema.php';

final class TerminologyCorrectionRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    public function create(array $params): array
    {
        EvidenceSchema::requireTables($this->pdo, array(EvidenceSchema::TABLE_KNOWLEDGE_CORRECTIONS));

        $uuid = $this->uuid();
        $stmt = $this->pdo->prepare(
            'INSERT INTO ' . EvidenceSchema::TABLE_KNOWLEDGE_CORRECTIONS
            . ' (correction_uuid, recording_id, speech_segment_id, raw_text, corrected_text, scope_type, scope_ref,'
            . ' start_time_ms, end_time_ms, audio_reviewed, status, reviewer_user_id)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute(array(
            $uuid,
            (int)$params['recording_id'],
            isset($params['speech_segment_id']) ? (int)$params['speech_segment_id'] : null,
            (string)$params['raw_text'],
            (string)$params['corrected_text'],
            (string)($params['scope_type'] ?? 'recording_segment'),
            isset($params['scope_ref']) ? (string)$params['scope_ref'] : null,
            isset($params['start_time_ms']) ? (int)$params['start_time_ms'] : null,
            isset($params['end_time_ms']) ? (int)$params['end_time_ms'] : null,
            !empty($params['audio_reviewed']) ? 1 : 0,
            (string)($params['status'] ?? 'proposed'),
            isset($params['reviewer_user_id']) ? (int)$params['reviewer_user_id'] : null,
        ));

        return $this->findByUuid($uuid) ?? array('correction_uuid' => $uuid);
    }

    /**
     * @return array<string,mixed>|null
     */
    public function updateStatus(string $correctionUuid, string $status, ?int $reviewerUserId = null): ?array
    {
        if (!EvidenceSchema::tablePresent($this->pdo, EvidenceSchema::TABLE_KNOWLEDGE_CORRECTIONS)) {
            return null;
        }
        $stmt = $this->pdo->prepare(
            'UPDATE ' . EvidenceSchema::TABLE_KNOWLEDGE_CORRECTIONS
            . ' SET status = ?, reviewer_user_id = COALESCE(?, reviewer_user_id) WHERE correction_uuid = ?'
        );
        $stmt->execute(array($status, $reviewerUserId, $correctionUuid));
        return $this->findByUuid($correctionUuid);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listForRecording(int $recordingId, ?string $status = null): array
    {
        if (!EvidenceSchema::tablePresent($this->pdo, EvidenceSchema::TABLE_KNOWLEDGE_CORRECTIONS)) {
            return array();
        }
        if ($status !== null && $status !== '') {
            $stmt = $this->pdo->prepare(
                'SELECT * FROM ' . EvidenceSchema::TABLE_KNOWLEDGE_CORRECTIONS
                . ' WHERE recording_id = ? AND status = ? ORDER BY created_at DESC, id DESC'
            );
            $stmt->execute(array($recordingId, $status));
        } else {
            $stmt = $this->pdo->prepare(
                'SELECT * FROM ' . EvidenceSchema::TABLE_KNOWLEDGE_CORRECTIONS
                . ' WHERE recording_id = ? ORDER BY created_at DESC, id DESC'
            );
            $stmt->execute(array($recordingId));
        }
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: array();
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findByUuid(string $uuid): ?array
    {
        if (!EvidenceSchema::tablePresent($this->pdo, EvidenceSchema::TABLE_KNOWLEDGE_CORRECTIONS)) {
            return null;
        }
        $stmt = $this->pdo->prepare(
            'SELECT * FROM ' . EvidenceSchema::TABLE_KNOWLEDGE_CORRECTIONS . ' WHERE correction_uuid = ? LIMIT 1'
        );
        $stmt->execute(array($uuid));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
