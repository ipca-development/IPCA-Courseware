<?php
declare(strict_types=1);

require_once __DIR__ . '/AuditEventService.php';

final class FlightDebriefService
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @param array<string,mixed> $manifest
     */
    public function createEvidencePackage(int $flightRecordVersionId, array $manifest, ?int $actorUserId = null): int
    {
        $version = $this->nextPackageVersion($flightRecordVersionId);
        $encoded = AuditEventService::jsonEncode($manifest);
        $stmt = $this->pdo->prepare("
            INSERT INTO ipca_flight_evidence_packages
              (evidence_package_uuid, flight_record_version_id, package_version, evidence_manifest_json, sha256, created_by)
            VALUES
              (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute(array(AuditEventService::uuid(), $flightRecordVersionId, $version, $encoded, hash('sha256', $encoded), $actorUserId));
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * @param array<string,mixed> $evidenceRefs
     */
    public function addInstructorNote(int $flightRecordVersionId, int $authorUserId, string $noteText, array $evidenceRefs = array(), string $visibility = 'instructor_private'): int
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO ipca_instructor_debrief_notes
              (note_uuid, flight_record_version_id, author_user_id, visibility, note_text, evidence_refs_json)
            VALUES
              (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute(array(AuditEventService::uuid(), $flightRecordVersionId, $authorUserId, substr($visibility, 0, 32), $noteText, AuditEventService::jsonEncode($evidenceRefs)));
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function createAiDebriefVersion(int $flightRecordVersionId, ?int $evidencePackageId, array $payload): int
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO ipca_ai_debrief_versions
              (debrief_uuid, flight_record_version_id, evidence_package_id, provider, model, prompt_template_key,
               prompt_template_version, status, summary_text, strengths_text, improvement_text, action_items_json,
               evidence_refs_json, uncertainty_json)
            VALUES
              (:debrief_uuid, :flight_record_version_id, :evidence_package_id, :provider, :model, :prompt_template_key,
               :prompt_template_version, :status, :summary_text, :strengths_text, :improvement_text, :action_items_json,
               :evidence_refs_json, :uncertainty_json)
        ");
        $stmt->execute(array(
            ':debrief_uuid' => AuditEventService::uuid(),
            ':flight_record_version_id' => $flightRecordVersionId,
            ':evidence_package_id' => $evidencePackageId,
            ':provider' => substr((string)($payload['provider'] ?? 'openai'), 0, 64),
            ':model' => substr((string)($payload['model'] ?? ''), 0, 128),
            ':prompt_template_key' => substr((string)($payload['prompt_template_key'] ?? ''), 0, 128),
            ':prompt_template_version' => (int)($payload['prompt_template_version'] ?? 1),
            ':status' => substr((string)($payload['status'] ?? 'draft'), 0, 32),
            ':summary_text' => $payload['summary_text'] ?? null,
            ':strengths_text' => $payload['strengths_text'] ?? null,
            ':improvement_text' => $payload['improvement_text'] ?? null,
            ':action_items_json' => AuditEventService::jsonEncode(is_array($payload['action_items'] ?? null) ? $payload['action_items'] : array()),
            ':evidence_refs_json' => AuditEventService::jsonEncode(is_array($payload['evidence_refs'] ?? null) ? $payload['evidence_refs'] : array()),
            ':uncertainty_json' => AuditEventService::jsonEncode(is_array($payload['uncertainty'] ?? null) ? $payload['uncertainty'] : array()),
        ));
        return (int)$this->pdo->lastInsertId();
    }

    public function approveAiDebrief(int $debriefId, int $actorUserId): void
    {
        $this->pdo->prepare("
            UPDATE ipca_ai_debrief_versions
            SET status = 'approved', approved_by = ?, approved_at = CURRENT_TIMESTAMP(3), updated_at = CURRENT_TIMESTAMP(3)
            WHERE id = ?
        ")->execute(array($actorUserId, $debriefId));
    }

    /**
     * @param array<string,bool> $controls
     */
    public function setReleaseControls(int $flightRecordVersionId, int $recipientUserId, array $controls, int $actorUserId): void
    {
        $columns = array('summary_released', 'replay_released', 'transcript_released', 'debrief_released', 'audio_released');
        $values = array();
        foreach ($columns as $column) {
            $values[$column] = !empty($controls[$column]) ? 1 : 0;
        }
        $stmt = $this->pdo->prepare("
            INSERT INTO ipca_flight_record_release_controls
              (release_uuid, flight_record_version_id, recipient_user_id, summary_released, replay_released,
               transcript_released, debrief_released, audio_released, released_by, released_at)
            VALUES
              (:release_uuid, :flight_record_version_id, :recipient_user_id, :summary_released, :replay_released,
               :transcript_released, :debrief_released, :audio_released, :released_by, CURRENT_TIMESTAMP(3))
            ON DUPLICATE KEY UPDATE
              summary_released = VALUES(summary_released),
              replay_released = VALUES(replay_released),
              transcript_released = VALUES(transcript_released),
              debrief_released = VALUES(debrief_released),
              audio_released = VALUES(audio_released),
              released_by = VALUES(released_by),
              released_at = VALUES(released_at),
              updated_at = CURRENT_TIMESTAMP(3)
        ");
        $stmt->execute(array(
            ':release_uuid' => AuditEventService::uuid(),
            ':flight_record_version_id' => $flightRecordVersionId,
            ':recipient_user_id' => $recipientUserId,
            ':summary_released' => $values['summary_released'],
            ':replay_released' => $values['replay_released'],
            ':transcript_released' => $values['transcript_released'],
            ':debrief_released' => $values['debrief_released'],
            ':audio_released' => $values['audio_released'],
            ':released_by' => $actorUserId,
        ));
    }

    private function nextPackageVersion(int $flightRecordVersionId): int
    {
        $stmt = $this->pdo->prepare('SELECT COALESCE(MAX(package_version), 0) + 1 FROM ipca_flight_evidence_packages WHERE flight_record_version_id = ?');
        $stmt->execute(array($flightRecordVersionId));
        return (int)$stmt->fetchColumn();
    }
}
