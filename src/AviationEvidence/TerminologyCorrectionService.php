<?php
declare(strict_types=1);

require_once __DIR__ . '/EvidenceSchema.php';
require_once __DIR__ . '/TerminologyCorrectionRepository.php';

final class TerminologyCorrectionService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly TerminologyCorrectionRepository $corrections,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function propose(
        int $recordingId,
        string $rawText,
        string $correctedText,
        ?int $speechSegmentId = null,
        ?int $startTimeMs = null,
        ?int $endTimeMs = null,
        ?int $reviewerUserId = null
    ): array {
        EvidenceSchema::requireTables($this->pdo, array(EvidenceSchema::TABLE_KNOWLEDGE_CORRECTIONS));

        $rawText = trim($rawText);
        $correctedText = trim($correctedText);
        if ($rawText === '' || $correctedText === '') {
            throw new RuntimeException('Both raw and corrected text are required.');
        }
        if ($rawText === $correctedText) {
            throw new RuntimeException('Corrected text must differ from raw text.');
        }

        return $this->corrections->create(array(
            'recording_id' => $recordingId,
            'speech_segment_id' => $speechSegmentId,
            'raw_text' => $rawText,
            'corrected_text' => $correctedText,
            'scope_type' => 'recording_segment',
            'scope_ref' => $speechSegmentId !== null ? (string)$speechSegmentId : null,
            'start_time_ms' => $startTimeMs,
            'end_time_ms' => $endTimeMs,
            'reviewer_user_id' => $reviewerUserId,
            'status' => 'proposed',
        ));
    }

    /**
     * @return array<string,mixed>
     */
    public function updateStatus(string $correctionUuid, string $status, ?int $reviewerUserId = null): array
    {
        if (!in_array($status, array('proposed', 'accepted', 'rejected'), true)) {
            throw new RuntimeException('Invalid correction status.');
        }
        $row = $this->corrections->updateStatus($correctionUuid, $status, $reviewerUserId);
        if ($row === null) {
            throw new RuntimeException('Correction not found.');
        }
        return $row;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listForRecording(int $recordingId, ?string $status = null): array
    {
        return $this->corrections->listForRecording($recordingId, $status);
    }

    public static function fromPdo(PDO $pdo): self
    {
        return new self($pdo, new TerminologyCorrectionRepository($pdo));
    }
}
