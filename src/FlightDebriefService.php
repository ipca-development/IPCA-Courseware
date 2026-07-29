<?php
declare(strict_types=1);

require_once __DIR__ . '/AuditEventService.php';
require_once __DIR__ . '/openai.php';

final class FlightDebriefService
{
    private const PROMPT_VERSION = '1-4-9-v4-supportive-instructor';
    private const LOGIC_VERSION = 'grading-v1';
    private const ALLOWED_EVIDENCE = array('transcript', 'event_marker', 'garmin', 'adsb', 'audio');

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

    /** @return array<string,mixed> */
    public function generateStructuredDebrief(int $bundleId, ?int $actorUserId = null): array
    {
        $bundle = $this->structuredBundle($bundleId);
        if (!$bundle) {
            throw new RuntimeException('Reconstruction bundle not found.');
        }
        if ((int)($bundle['transcript_snapshot_id'] ?? 0) <= 0) {
            throw new RuntimeException('Generate Debrief is disabled until the raw transcript is Ready, non-empty, and version-locked.');
        }
        $snapshot = $this->row('ipca_cockpit_transcript_snapshots', (int)$bundle['transcript_snapshot_id']);
        if (trim((string)($snapshot['transcript_text'] ?? '')) === '') {
            throw new RuntimeException('Locked transcript snapshot is empty.');
        }
        $missionVersion = $this->missionVersion((string)$bundle['mission_code']);
        $exercise = json_decode((string)($missionVersion['exercise_json'] ?? ''), true);
        if (!is_array($exercise['scenario_plan'] ?? null) || !is_array($exercise['evaluation_rubric'] ?? null)) {
            throw new RuntimeException('Mission requires a canonical scenario_plan and evaluation_rubric.');
        }
        $evidence = $this->structuredEvidence($bundle, $snapshot);
        $encodedEvidence = AuditEventService::jsonEncode($evidence);
        if (str_contains(strtolower($encodedEvidence), 'flightcircle')) {
            throw new RuntimeException('FlightCircle evidence is prohibited from AI debrief generation.');
        }
        $prompt = $this->structuredPrompt($exercise, $evidence);
        $request = array(
            'model' => cw_openai_model(),
            'input' => array(
                array('role' => 'system', 'content' => array(array('type' => 'input_text', 'text' => $this->structuredSystemPrompt()))),
                array('role' => 'user', 'content' => array(array('type' => 'input_text', 'text' => $prompt))),
            ),
        );
        $response = cw_openai_responses($request, 600);
        $rawText = $this->responseText($response);
        $decoded = $this->decodeModelJson($rawText);
        $normalized = $this->normalizeStructuredDebrief($decoded, $exercise['evaluation_rubric']);
        $overall = $this->calculateSuggestedOverall($normalized['evaluations']);
        $normalized['suggested_overall'] = $overall['result'];

        $this->pdo->beginTransaction();
        try {
            $previous = $this->pdo->prepare(
                'SELECT id FROM ipca_structured_debriefs WHERE bundle_id = ? ORDER BY id DESC LIMIT 1 FOR UPDATE'
            );
            $previous->execute(array($bundleId));
            $supersedes = (int)$previous->fetchColumn() ?: null;
            $insert = $this->pdo->prepare(
                'INSERT INTO ipca_structured_debriefs
                 (debrief_uuid, bundle_id, mission_version_id, transcript_snapshot_id, supersedes_debrief_id,
                  status, provider, model, prompt_version, logic_version, prompt_sha256, request_sha256,
                  response_sha256, raw_response_json, general_text, chronological_review_json,
                  mission_assessment_text, summary_next_steps_text, suggested_overall,
                  overall_calculation_json, uncertainty_json, created_by)
                 VALUES (?, ?, ?, ?, ?, \'ai_draft\', \'openai\', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $insert->execute(array(
                AuditEventService::uuid(), $bundleId, (int)$missionVersion['id'], (int)$snapshot['id'], $supersedes,
                cw_openai_model(), self::PROMPT_VERSION, self::LOGIC_VERSION,
                hash('sha256', $prompt),
                hash('sha256', AuditEventService::jsonEncode($request)),
                hash('sha256', AuditEventService::jsonEncode($response)),
                AuditEventService::jsonEncode($response),
                $normalized['general'],
                AuditEventService::jsonEncode($normalized['chronological_review']),
                $normalized['mission_standards_assessment'],
                $normalized['summary_next_steps'],
                $overall['result'],
                AuditEventService::jsonEncode($overall),
                AuditEventService::jsonEncode($normalized['uncertainties']),
                $actorUserId,
            ));
            $debriefId = (int)$this->pdo->lastInsertId();
            $evaluationInsert = $this->pdo->prepare(
                'INSERT INTO ipca_structured_debrief_evaluations
                 (evaluation_uuid, debrief_id, rubric_type, rubric_item_id, title, required_standard,
                  suggested_grade, evidence_status, completion_status, rationale, confidence,
                  evidence_refs_json, instructor_prompting_json, main_issue, improvement_suggestion)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            foreach ($normalized['evaluations'] as $evaluation) {
                $evaluationInsert->execute(array(
                    AuditEventService::uuid(), $debriefId, $evaluation['rubric_type'],
                    $evaluation['rubric_item_id'], $evaluation['title'], $evaluation['required_standard'],
                    $evaluation['suggested_grade'], $evaluation['evidence_status'], $evaluation['completion_status'],
                    $evaluation['rationale'], $evaluation['confidence'],
                    AuditEventService::jsonEncode($evaluation['evidence_refs']),
                    AuditEventService::jsonEncode($evaluation['instructor_prompting']),
                    $evaluation['main_issue'], $evaluation['improvement_suggestion'],
                ));
            }
            $this->structuredAudit($debriefId, 'ai_draft_generated', $actorUserId, null, array(
                'bundle_id' => $bundleId,
                'suggested_overall' => $overall['result'],
                'model' => cw_openai_model(),
            ), 'Evidence-backed AI suggestions generated; instructor review required.');
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
        return $this->structuredDebrief($debriefId);
    }

    /** @return list<array<string,mixed>> */
    public function structuredDebriefsForBundle(int $bundleId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM ipca_structured_debriefs WHERE bundle_id = ? ORDER BY id DESC'
        );
        $statement->execute(array($bundleId));
        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: array();
    }

    /** @return array<string,mixed> */
    public function structuredDebrief(int $debriefId): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM ipca_structured_debriefs WHERE id = ? LIMIT 1');
        $statement->execute(array($debriefId));
        $debrief = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($debrief)) {
            throw new RuntimeException('Debrief not found.');
        }
        $evaluations = $this->pdo->prepare(
            'SELECT * FROM ipca_structured_debrief_evaluations
             WHERE debrief_id = ? ORDER BY rubric_type, id'
        );
        $evaluations->execute(array($debriefId));
        $debrief['evaluations'] = $evaluations->fetchAll(PDO::FETCH_ASSOC) ?: array();
        $context = $this->pdo->prepare(
            'SELECT b.bundle_uuid, b.version_number, b.aircraft_registration, b.mission_code,
                    COALESCE(b.operational_flight_record_version_id, gr.current_version_id) AS operational_flight_record_version_id,
                    d.scheduled_date, d.crew_json, d.aircraft_id,
                    d.starting_hobbs, d.starting_tacho,
                    c.ending_hobbs, c.ending_tacho,
                    COALESCE(NULLIF(a.aircraft_type, \'\'), NULLIF(a.display_name, \'\'), \'\') AS aircraft_type,
                    v.exact_hobbs_duration_ms, v.exact_tacho_duration_ms,
                    v.hobbs_start_hours, v.hobbs_end_hours, v.tacho_start_hours, v.tacho_end_hours,
                    v.total_night_duration_ms, v.cross_country_easa_qualified,
                    v.cross_country_faa_qualified, v.landing_event_count, v.summary_json,
                    COALESCE(
                      (SELECT MIN(fe.timestamp_utc) FROM ipca_cvr_flight_events fe
                       WHERE fe.workflow_flight_record_uuid = b.workflow_flight_record_uuid
                         AND fe.event_type = \'engine_start_off_block\'),
                      s.engine_start_utc, gs.engine_start_utc) AS engine_start_utc,
                    (SELECT MAX(fe.timestamp_utc) FROM ipca_cvr_flight_events fe
                     WHERE fe.workflow_flight_record_uuid = b.workflow_flight_record_uuid
                       AND fe.event_type = \'engine_shutdown_on_block\') AS app_engine_stop_utc,
                    COALESCE(
                      (SELECT MAX(fe.timestamp_utc) FROM ipca_cvr_flight_events fe
                       WHERE fe.workflow_flight_record_uuid = b.workflow_flight_record_uuid
                         AND fe.event_type = \'engine_shutdown_on_block\'),
                      s.engine_stop_utc, gs.engine_stop_utc) AS engine_stop_utc,
                    COALESCE(s.avionics_on_utc, gs.avionics_on_utc, g.first_valid_sample_utc) AS avionics_on_utc,
                    COALESCE(s.avionics_off_utc, gs.avionics_off_utc, g.last_valid_sample_utc) AS avionics_off_utc,
                    g.first_valid_sample_utc AS garmin_start_utc,
                    g.last_valid_sample_utc AS garmin_end_utc,
                    cr.started_at AS recording_start_utc, cr.duration_seconds AS recording_duration_seconds,
                    (SELECT COUNT(*) FROM ipca_cvr_flight_events fl
                     WHERE fl.workflow_flight_record_uuid = b.workflow_flight_record_uuid
                       AND fl.event_type = \'gps_landing_provisional\') AS gps_landing_count
             FROM ipca_manual_intake_bundles b
             INNER JOIN ipca_cvr_dispatches d ON d.id = b.dispatch_id
             INNER JOIN ipca_cockpit_recordings cr ON cr.id = b.cockpit_recording_id
             INNER JOIN ipca_garmin_csv_files g ON g.id = b.garmin_csv_file_id
             LEFT JOIN ipca_aircraft_devices a ON a.id = d.aircraft_id
             LEFT JOIN ipca_flight_sessions gs ON gs.id = g.session_id
             LEFT JOIN ipca_operational_flight_records gr ON gr.id = gs.current_flight_record_id
             LEFT JOIN ipca_cvr_flight_closures c ON c.id = (
               SELECT fc.id FROM ipca_cvr_flight_closures fc
               WHERE fc.workflow_flight_record_uuid = b.workflow_flight_record_uuid
               ORDER BY fc.id DESC LIMIT 1
             )
             LEFT JOIN ipca_operational_flight_record_versions v
               ON v.id = COALESCE(b.operational_flight_record_version_id, gr.current_version_id)
             LEFT JOIN ipca_operational_flight_records r ON r.id = v.flight_record_id
             LEFT JOIN ipca_flight_sessions s ON s.id = r.session_id
             WHERE b.id = ? LIMIT 1'
        );
        $context->execute(array((int)$debrief['bundle_id']));
        $debrief['context'] = $context->fetch(PDO::FETCH_ASSOC) ?: array();
        $debrief['context']['block_time_discrepancies'] = array();
        $offBlock = strtotime((string)($debrief['context']['engine_start_utc'] ?? ''));
        $hobbsDurationMs = $debrief['context']['exact_hobbs_duration_ms'] ?? null;
        if ($offBlock !== false && is_numeric($hobbsDurationMs) && (int)$hobbsDurationMs >= 0) {
            $computedOnBlock = $offBlock + ((int)$hobbsDurationMs / 1000);
            $observedEngineStop = strtotime((string)($debrief['context']['app_engine_stop_utc'] ?? ''));
            $debrief['context']['engine_stop_utc'] = gmdate('Y-m-d H:i:s', (int)round($computedOnBlock));
            $debrief['context']['on_block_derivation'] = 'off_block_plus_crew_hobbs_delta';
            if ($observedEngineStop !== false && abs($observedEngineStop - $computedOnBlock) > 60) {
                $debrief['context']['block_time_discrepancies'][] = sprintf(
                    'ON Block discrepancy: App Engine Stop was %s UTC, while OFF Block plus Hobbs END minus START gives %s UTC.',
                    gmdate('H:i:s', $observedEngineStop),
                    gmdate('H:i:s', (int)round($computedOnBlock))
                );
            }
        }
        $flightRecordVersionId = (int)($debrief['context']['operational_flight_record_version_id'] ?? 0);
        $debrief['context']['legs'] = array();
        $debrief['context']['logbook_proposal'] = array();
        if ($flightRecordVersionId > 0) {
            $legs = $this->pdo->prepare(
                'SELECT leg_index, departure_airport_code, arrival_airport_code,
                        COALESCE(administrative_departure_utc, takeoff_utc) AS departure_utc,
                        COALESCE(administrative_arrival_utc, landing_utc) AS arrival_utc,
                        allocated_hobbs_duration_ms, night_duration_ms,
                        cross_country_easa_qualified, cross_country_faa_qualified, landing_event_count
                 FROM ipca_operational_flight_leg_versions
                 WHERE flight_record_version_id = ? ORDER BY leg_index'
            );
            $legs->execute(array($flightRecordVersionId));
            $debrief['context']['legs'] = $legs->fetchAll(PDO::FETCH_ASSOC) ?: array();
            $proposal = $this->pdo->prepare(
                'SELECT entry_type, proposed_duration_ms, proposed_values_json, status
                 FROM ipca_flight_record_logbook_proposals
                 WHERE flight_record_version_id = ? ORDER BY id DESC LIMIT 1'
            );
            $proposal->execute(array($flightRecordVersionId));
            $proposalRow = $proposal->fetch(PDO::FETCH_ASSOC);
            if (is_array($proposalRow)) {
                $proposalValues = json_decode((string)$proposalRow['proposed_values_json'], true);
                $proposalRow['proposed_values'] = is_array($proposalValues) ? $proposalValues : array();
                $debrief['context']['logbook_proposal'] = $proposalRow;
            }
        }
        return $debrief;
    }

    /** @param array<int,array<string,mixed>> $reviews */
    public function saveInstructorReview(
        int $debriefId,
        array $reviews,
        string $overall,
        string $comments,
        int $actorUserId
    ): void {
        $debrief = $this->structuredDebrief($debriefId);
        if (in_array((string)$debrief['status'], array('approved', 'released'), true)) {
            throw new RuntimeException('Approved debrief versions are immutable. Regenerate a superseding version.');
        }
        $overall = strtoupper(trim($overall));
        if ($overall === '') {
            $overall = strtoupper(trim((string)$debrief['suggested_overall']));
        }
        if (!in_array($overall, array('BLUE', 'GREEN', 'YELLOW', 'RED', 'INCOMPLETE'), true)) {
            throw new RuntimeException('Select a valid instructor overall result.');
        }
        $taskScale = array('DE', 'EX', 'PR', 'PE', 'NO');
        $srmScale = array('EX', 'PR', 'MD', 'NO');
        $this->pdo->beginTransaction();
        try {
            foreach ($reviews as $evaluationId => $review) {
                $evaluationId = (int)$evaluationId;
                $rubricType = (string)($review['rubric_type'] ?? '');
                $grade = strtoupper(trim((string)($review['grade'] ?? '')));
                $allowed = $rubricType === 'srm' ? $srmScale : $taskScale;
                if ($grade !== '' && !in_array($grade, $allowed, true)) {
                    throw new RuntimeException('Invalid instructor grade.');
                }
                $this->pdo->prepare(
                    'UPDATE ipca_structured_debrief_evaluations
                     SET instructor_grade = ?, instructor_comment = ?, reviewed_by = ?,
                         reviewed_at = CURRENT_TIMESTAMP(3)
                     WHERE id = ? AND debrief_id = ?'
                )->execute(array(
                    $grade === '' ? null : $grade,
                    trim((string)($review['comment'] ?? '')),
                    $actorUserId, $evaluationId, $debriefId,
                ));
            }
            $this->pdo->prepare(
                'UPDATE ipca_structured_debriefs
                 SET status = \'instructor_draft\', instructor_overall = ?, instructor_comments = ?
                 WHERE id = ?'
            )->execute(array($overall, trim($comments), $debriefId));
            $this->structuredAudit($debriefId, 'instructor_review_saved', $actorUserId, null, array(
                'instructor_overall' => $overall,
                'review_count' => count($reviews),
            ), 'Instructor draft review saved.');
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function approveStructuredDebrief(int $debriefId, int $actorUserId): void
    {
        $debrief = $this->structuredDebrief($debriefId);
        if (!in_array((string)$debrief['status'], array('ai_draft', 'instructor_draft'), true)) {
            throw new RuntimeException('Only an unverified Debriefing Sheet can be verified.');
        }
        $acceptedSuggestions = 0;
        $unassessedItems = 0;
        foreach ($debrief['evaluations'] as $evaluation) {
            if (trim((string)($evaluation['instructor_grade'] ?? '')) !== '') {
                continue;
            }
            if (trim((string)($evaluation['suggested_grade'] ?? '')) !== '') {
                $acceptedSuggestions++;
            } else {
                $unassessedItems++;
            }
        }
        $finalOverall = trim((string)($debrief['instructor_overall'] ?? ''));
        if ($finalOverall === '') {
            $finalOverall = trim((string)$debrief['suggested_overall']);
        }
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare(
                'UPDATE ipca_structured_debrief_evaluations
                 SET instructor_grade = suggested_grade, reviewed_by = ?,
                     reviewed_at = CURRENT_TIMESTAMP(3)
                 WHERE debrief_id = ? AND instructor_grade IS NULL AND suggested_grade IS NOT NULL'
            )->execute(array($actorUserId, $debriefId));
            $this->pdo->prepare(
                'UPDATE ipca_structured_debriefs
                 SET status = \'approved\', instructor_overall = ?,
                     approved_by = ?, approved_at = CURRENT_TIMESTAMP(3)
                 WHERE id = ? AND status IN (\'ai_draft\', \'instructor_draft\')'
            )->execute(array($finalOverall, $actorUserId, $debriefId));
            $this->structuredAudit($debriefId, 'instructor_verified', $actorUserId, null, array(
                'instructor_overall' => $finalOverall,
                'accepted_ai_suggestions' => $acceptedSuggestions,
                'unassessed_items' => $unassessedItems,
            ), 'Instructor verified the generated Debriefing Sheet; accepted suggestions are now authoritative.');
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function rejectStructuredDebrief(int $debriefId, string $reason, int $actorUserId): void
    {
        $debrief = $this->structuredDebrief($debriefId);
        if (in_array((string)$debrief['status'], array('approved', 'released'), true)) {
            throw new RuntimeException('Approved debrief versions cannot be rejected.');
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw new RuntimeException('A rejection reason is required.');
        }
        $this->pdo->prepare(
            'UPDATE ipca_structured_debriefs SET status = \'rejected\', instructor_comments = ? WHERE id = ?'
        )->execute(array($reason, $debriefId));
        $this->structuredAudit($debriefId, 'ai_draft_rejected', $actorUserId, null, array(
            'reason' => $reason,
        ), 'Instructor rejected the AI draft; regeneration creates a superseding version.');
    }

    public function releaseStructuredDebrief(int $debriefId, int $recipientUserId, int $actorUserId): void
    {
        $statement = $this->pdo->prepare(
            'SELECT d.*, b.operational_flight_record_version_id
             FROM ipca_structured_debriefs d
             INNER JOIN ipca_manual_intake_bundles b ON b.id = d.bundle_id
             WHERE d.id = ? LIMIT 1'
        );
        $statement->execute(array($debriefId));
        $debrief = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($debrief) || (string)$debrief['status'] !== 'approved') {
            throw new RuntimeException('Only an instructor-approved debrief can be released.');
        }
        $flightRecordVersionId = (int)($debrief['operational_flight_record_version_id'] ?? 0);
        if ($flightRecordVersionId <= 0 || $recipientUserId <= 0) {
            throw new RuntimeException('Canonical Flight Record version and recipient are required for release.');
        }
        $this->setReleaseControls($flightRecordVersionId, $recipientUserId, array(
            'debrief_released' => true,
        ), $actorUserId);
        $this->pdo->prepare(
            'UPDATE ipca_structured_debriefs SET status = \'released\', released_at = CURRENT_TIMESTAMP(3)
             WHERE id = ? AND status = \'approved\''
        )->execute(array($debriefId));
        $this->structuredAudit($debriefId, 'debrief_released', $actorUserId, null, array(
            'recipient_user_id' => $recipientUserId,
            'flight_record_version_id' => $flightRecordVersionId,
        ), 'Approved debrief explicitly released to recipient.');
    }

    /** @param array<string,mixed> $bundle @param array<string,mixed> $snapshot @return array<string,mixed> */
    private function structuredEvidence(array $bundle, array $snapshot): array
    {
        $events = array();
        if ($this->tableExists('ipca_cvr_flight_events')) {
            $statement = $this->pdo->prepare(
                'SELECT event_uuid, event_type, timestamp_utc, audio_offset_seconds, source, confidence
                 FROM ipca_cvr_flight_events WHERE workflow_flight_record_uuid = ? ORDER BY timestamp_utc'
            );
            $statement->execute(array($bundle['workflow_flight_record_uuid']));
            $events = $statement->fetchAll(PDO::FETCH_ASSOC) ?: array();
        }
        $chunks = array();
        if ($this->tableExists('ipca_cockpit_recording_transcription_chunks')) {
            $statement = $this->pdo->prepare(
                'SELECT chunk_index, start_seconds, end_seconds, transcript_text
                 FROM ipca_cockpit_recording_transcription_chunks
                 WHERE recording_id = ? AND status = \'ready\' ORDER BY chunk_index'
            );
            $statement->execute(array((int)$bundle['cockpit_recording_id']));
            $chunks = $statement->fetchAll(PDO::FETCH_ASSOC) ?: array();
        }
        $timeline = array();
        if ($this->tableExists('ipca_cockpit_timeline_events')) {
            $statement = $this->pdo->prepare(
                'SELECT event_type, start_seconds, end_seconds, confidence, evidence_json, notes
                 FROM ipca_cockpit_timeline_events WHERE recording_id = ? ORDER BY start_seconds'
            );
            $statement->execute(array((int)$bundle['cockpit_recording_id']));
            $timeline = $statement->fetchAll(PDO::FETCH_ASSOC) ?: array();
        }
        $adsb = array();
        if ($this->tableExists('ipca_cockpit_adsb_enrichments')) {
            $statement = $this->pdo->prepare(
                'SELECT status, ownship_sample_count, traffic_sample_count, aircraft_hex
                 FROM ipca_cockpit_adsb_enrichments WHERE recording_id = ? ORDER BY id DESC LIMIT 1'
            );
            $statement->execute(array((int)$bundle['cockpit_recording_id']));
            $adsb = $statement->fetch(PDO::FETCH_ASSOC) ?: array();
        }
        return array(
            'bundle_uuid' => $bundle['bundle_uuid'],
            'manifest_sha256' => $bundle['manifest_sha256'],
            'transcript_snapshot' => array(
                'snapshot_uuid' => $snapshot['snapshot_uuid'],
                'sha256' => $snapshot['transcript_sha256'],
                'text' => (string)$snapshot['transcript_text'],
                'chunks' => $chunks,
            ),
            'event_markers' => $events,
            'garmin_reconstruction_timeline' => $timeline,
            'adsb_context' => $adsb,
            'source_limitations' => array(
                'Garmin measures aircraft performance and flight path; it does not prove prompting or decision quality.',
                'ADS-B provides traffic context; it does not prove the student saw traffic.',
                'Transcript absence is insufficient evidence, not automatic NO.',
            ),
        );
    }

    /** @param array<string,mixed> $exercise @param array<string,mixed> $evidence */
    private function structuredPrompt(array $exercise, array $evidence): string
    {
        return "MISSION CANONICAL DATA:\n"
            . AuditEventService::jsonEncode($exercise)
            . "\n\nIMMUTABLE EVIDENCE:\n"
            . AuditEventService::jsonEncode($evidence)
            . "\n\nReturn JSON only using the requested structure. Evaluate every canonical task and SRM item.";
    }

    private function structuredSystemPrompt(): string
    {
        return implode("\n", array(
            'You create a professional, factual, motivational flight-training debrief draft for instructor review.',
            'The AI only suggests grades. Never claim authority over the instructor.',
            'Return JSON with: general (string), chronological_review (array of objects with title, narrative, evidence_refs), mission_standards_assessment (string), summary_next_steps (string), evaluations (array), uncertainties (array).',
            'Each evaluation requires rubric_type task|srm, rubric_item_id, suggested_grade or null, evidence_status supported|partial|insufficient_evidence, completion_status completed|not_completed|uncertain, rationale, confidence 0..1, evidence_refs, instructor_prompting, main_issue, improvement_suggestion.',
            'Evidence references must be arrays of objects with type, time or time_range, and a short plain-language description. Never put JSON syntax, array notation, hashes, database IDs, or internal field names inside narrative text.',
            'Use task grades DE, EX, PR, PE, NO exactly and SRM grades EX, PR, MD, NO exactly.',
            'Do not use NO merely because a task is absent from transcript. Use null grade and insufficient_evidence.',
            'Use insufficient_evidence only when no relevant transcript, marker, Garmin, replay, or ADS-B evidence exists. Partial evidence can support a grade. Do not discard usable evidence merely because marker boundaries are absent.',
            'When transcript shows coaching, prompting, a restart, or instructor assistance, normally suggest PR rather than returning no grade. When evidence supports independent, self-corrected performance, consider PE. Use Garmin/replay evidence for flight-path, timing, maneuver, altitude, speed, takeoff, landing, and approach-profile facts.',
            'Exercise markers define chronological segment boundaries. Training Remark markers prioritize the transcript/audio immediately following the marker. Safety markers create separate safety windows.',
            'Every factual claim and suggested grade must cite transcript chunk/time, marker, Garmin timeline, ADS-B context, or audio offset.',
            'Garmin supports performance; transcript supports instruction, prompting, checklists and decisions. ADS-B does not prove visual acquisition.',
            'Write the chronological review as detailed, copy-ready instructor prose. Use meaningful lettered section titles, numbered subsections when useful, and natural plain-text subheadings such as Key learning points, Main reminders, Main takeaway, and Next time. Keep citations outside the narrative.',
            'DEFAULT STUDENT VOICE: Write as an experienced, professional, supportive flight instructor speaking directly to the student after the lesson. The student should clearly understand what went well, what to improve, why it matters, and feel motivated to fly again.',
            'Lead with deserved strengths before improvements. Explicitly acknowledge improvement during the lesson with natural phrases such as “You’re making good progress”, “This became noticeably better during the lesson”, “With a little more practice”, “Once this becomes more consistent”, “The foundation is clearly there”, or “Keep building on”, without overpraising.',
            'Present weaknesses honestly as coaching opportunities. Explain what happened, why the correction matters, and finish each concern with a constructive next action. Focus on confidence, judgment, understanding, and development rather than judging isolated events.',
            'Student-facing prose must not sound like an audit, compliance report, checkride report, FAA evaluation, or examiner narrative. Avoid phrases including “the evidence suggests”, “supports coached execution”, “required prompting”, “root cause”, “overall assessment”, and “performance concern”. Evidence and confidence terminology belongs only in evaluation rationale and evidence_refs, never in general, chronological_review narratives, mission_standards_assessment, or summary_next_steps.',
            'Keep the tone conversational, natural, coherent, and human, as if instructor and student are sitting together in the briefing room. Vary section structure so the flight reads as one connected lesson rather than repeated independent reports.',
            'Reduce repetitive wording by approximately 20–30 percent while preserving every important learning point.',
            'Be specific and constructive. Do not fabricate events, measurements, dialogue, traffic awareness, or task completion.',
        ));
    }

    /** @param array<string,mixed> $decoded @param array<string,mixed> $rubric @return array<string,mixed> */
    private function normalizeStructuredDebrief(array $decoded, array $rubric): array
    {
        $provided = array();
        foreach (is_array($decoded['evaluations'] ?? null) ? $decoded['evaluations'] : array() as $evaluation) {
            if (is_array($evaluation) && trim((string)($evaluation['rubric_item_id'] ?? '')) !== '') {
                $provided[(string)$evaluation['rubric_item_id']] = $evaluation;
            }
        }
        $evaluations = array();
        foreach (array('task' => $rubric['tasks'] ?? array(), 'srm' => $rubric['srm_items'] ?? array()) as $type => $items) {
            foreach (is_array($items) ? $items : array() as $item) {
                $id = (string)($item['id'] ?? '');
                if ($id === '') {
                    continue;
                }
                $candidate = is_array($provided[$id] ?? null) ? $provided[$id] : array();
                $grade = strtoupper(trim((string)($candidate['suggested_grade'] ?? '')));
                $allowed = $type === 'srm' ? array('EX', 'PR', 'MD', 'NO') : array('DE', 'EX', 'PR', 'PE', 'NO');
                $refs = $this->sanitizeEvidenceRefs($candidate['evidence_refs'] ?? array());
                $status = strtolower(trim((string)($candidate['evidence_status'] ?? 'insufficient_evidence')));
                if (!in_array($status, array('supported', 'partial', 'insufficient_evidence'), true) || $refs === array()) {
                    $status = 'insufficient_evidence';
                }
                if (!in_array($grade, $allowed, true) || $status === 'insufficient_evidence') {
                    $grade = null;
                }
                $completion = strtolower(trim((string)($candidate['completion_status'] ?? 'uncertain')));
                if (!in_array($completion, array('completed', 'not_completed', 'uncertain'), true)) {
                    $completion = 'uncertain';
                }
                if ($status === 'insufficient_evidence') {
                    $completion = 'uncertain';
                }
                $evaluations[] = array(
                    'rubric_type' => $type,
                    'rubric_item_id' => $id,
                    'title' => (string)($item['title'] ?? $id),
                    'required_standard' => strtoupper((string)($item['required_standard'] ?? ($type === 'srm' ? 'MD' : 'PE'))),
                    'suggested_grade' => $grade,
                    'evidence_status' => $status,
                    'completion_status' => $completion,
                    'rationale' => trim((string)($candidate['rationale'] ?? 'Insufficient evidence for a reliable suggestion; instructor review required.')),
                    'confidence' => max(0, min(1, (float)($candidate['confidence'] ?? 0))),
                    'evidence_refs' => $refs,
                    'instructor_prompting' => is_array($candidate['instructor_prompting'] ?? null) ? $candidate['instructor_prompting'] : array(),
                    'main_issue' => trim((string)($candidate['main_issue'] ?? '')) ?: null,
                    'improvement_suggestion' => trim((string)($candidate['improvement_suggestion'] ?? '')) ?: null,
                    'required' => !empty($item['required']),
                );
            }
        }
        return array(
            'general' => trim((string)($decoded['general'] ?? 'Instructor review required.')),
            'chronological_review' => is_array($decoded['chronological_review'] ?? null) ? $decoded['chronological_review'] : array(),
            'mission_standards_assessment' => trim((string)($decoded['mission_standards_assessment'] ?? 'See task-level evidence suggestions.')),
            'summary_next_steps' => trim((string)($decoded['summary_next_steps'] ?? 'Review evidence and agree specific next steps with the student.')),
            'evaluations' => $evaluations,
            'uncertainties' => is_array($decoded['uncertainties'] ?? null) ? $decoded['uncertainties'] : array(),
        );
    }

    /** @param list<array<string,mixed>> $evaluations @return array<string,mixed> */
    private function calculateSuggestedOverall(array $evaluations): array
    {
        $taskScale = array('DE' => 1, 'EX' => 2, 'PR' => 3, 'PE' => 4);
        $srmScale = array('EX' => 1, 'PR' => 2, 'MD' => 3);
        $above = 0;
        $below = 0;
        $assessed = 0;
        $incomplete = false;
        $safetyInsufficient = false;
        foreach ($evaluations as $evaluation) {
            if (empty($evaluation['required'])) {
                continue;
            }
            if ($evaluation['completion_status'] === 'not_completed' || $evaluation['suggested_grade'] === 'NO') {
                $incomplete = true;
                continue;
            }
            $grade = (string)($evaluation['suggested_grade'] ?? '');
            $required = (string)$evaluation['required_standard'];
            $scale = $evaluation['rubric_type'] === 'srm' ? $srmScale : $taskScale;
            if (!isset($scale[$grade], $scale[$required])) {
                continue;
            }
            $assessed++;
            if ($scale[$grade] > $scale[$required]) {
                $above++;
            } elseif ($scale[$grade] < $scale[$required]) {
                $below++;
            }
            if ($evaluation['rubric_item_id'] === 'srm.safety_management' && $scale[$grade] < $scale[$required]) {
                $safetyInsufficient = true;
            }
        }
        $abovePct = $assessed > 0 ? $above / $assessed : 0;
        $belowPct = $assessed > 0 ? $below / $assessed : 0;
        if ($incomplete) {
            $result = 'INCOMPLETE';
        } elseif ($assessed === 0) {
            $result = 'PENDING INSTRUCTOR REVIEW';
        } elseif ($safetyInsufficient || $belowPct > 0.25) {
            $result = 'RED';
        } elseif ($below > 0) {
            $result = 'YELLOW';
        } elseif ($abovePct >= 0.25) {
            $result = 'BLUE';
        } else {
            $result = 'GREEN';
        }
        return array(
            'result' => $result,
            'assessed_required_count' => $assessed,
            'above_count' => $above,
            'below_count' => $below,
            'above_fraction' => $abovePct,
            'below_fraction' => $belowPct,
            'explicit_incomplete' => $incomplete,
            'safety_insufficient' => $safetyInsufficient,
            'unassessed_items_do_not_default_to_no' => true,
        );
    }

    /** @return list<array<string,mixed>> */
    private function sanitizeEvidenceRefs(mixed $refs): array
    {
        $result = array();
        foreach (is_array($refs) ? $refs : array() as $ref) {
            if (is_string($ref)) {
                $description = trim($ref);
                $type = $this->inferEvidenceType(strtolower($description));
                if ($description === '' || $type === null) {
                    continue;
                }
                $result[] = array('type' => $type, 'description' => $description);
                continue;
            }
            if (!is_array($ref)) {
                continue;
            }
            $encoded = strtolower(AuditEventService::jsonEncode($ref));
            if (str_contains($encoded, 'flightcircle')
                || str_contains($encoded, 'historical')) {
                continue;
            }
            $type = strtolower(trim((string)($ref['type'] ?? $ref['source_type'] ?? $ref['source'] ?? $ref['evidence_type'] ?? '')));
            $aliases = array(
                'transcript_chunk' => 'transcript',
                'cockpit_transcript' => 'transcript',
                'marker' => 'event_marker',
                'flight_event' => 'event_marker',
                'timeline' => 'garmin',
                'replay' => 'garmin',
                'g3x' => 'garmin',
                'garmin_csv' => 'garmin',
                'traffic' => 'adsb',
                'cockpit_audio' => 'audio',
            );
            $type = $aliases[$type] ?? $type;
            if (!in_array($type, self::ALLOWED_EVIDENCE, true)) {
                $type = $this->inferEvidenceType($encoded);
            }
            if ($type === null) {
                continue;
            }
            $normalized = $ref;
            $normalized['type'] = $type;
            unset($normalized['source_type'], $normalized['evidence_type']);
            $result[] = $normalized;
        }
        return $result;
    }

    private function inferEvidenceType(string $text): ?string
    {
        foreach (array(
            'transcript' => array('transcript', 'chunk', 'spoken', 'dialogue'),
            'event_marker' => array('marker', 'training remark', 'safety event', 'exercise start'),
            'garmin' => array('garmin', 'g3x', 'csv', 'replay', 'timeline', 'flight path'),
            'adsb' => array('ads-b', 'adsb', 'traffic'),
            'audio' => array('audio', 'recording'),
        ) as $type => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($text, $needle)) {
                    return $type;
                }
            }
        }
        return null;
    }

    /** @return array<string,mixed> */
    private function structuredBundle(int $bundleId): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM ipca_manual_intake_bundles WHERE id = ? LIMIT 1');
        $statement->execute(array($bundleId));
        return $statement->fetch(PDO::FETCH_ASSOC) ?: array();
    }

    /** @return array<string,mixed> */
    private function missionVersion(string $missionCode): array
    {
        $statement = $this->pdo->prepare(
            'SELECT v.* FROM ipca_missions m
             INNER JOIN ipca_mission_versions v ON v.id = m.current_version_id
             WHERE UPPER(m.code) = UPPER(?) LIMIT 1'
        );
        $statement->execute(array(trim($missionCode)));
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('Canonical mission version was not found.');
        }
        return $row;
    }

    /** @return array<string,mixed> */
    private function row(string $table, int $id): array
    {
        $allowed = array('ipca_cockpit_transcript_snapshots');
        if (!in_array($table, $allowed, true)) {
            throw new RuntimeException('Evidence table is not allowlisted.');
        }
        $statement = $this->pdo->prepare('SELECT * FROM `' . $table . '` WHERE id = ? LIMIT 1');
        $statement->execute(array($id));
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('Evidence record not found.');
        }
        return $row;
    }

    private function tableExists(string $table): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        $statement->execute(array($table));
        return (int)$statement->fetchColumn() === 1;
    }

    /** @return array<string,mixed> */
    private function decodeModelJson(string $text): array
    {
        $text = trim($text);
        $text = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $text) ?? $text;
        $decoded = json_decode($text, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('AI debrief response was not valid structured JSON.');
        }
        return $decoded;
    }

    /** @param array<string,mixed> $response */
    private function responseText(array $response): string
    {
        $parts = array();
        foreach (is_array($response['output'] ?? null) ? $response['output'] : array() as $output) {
            foreach (is_array($output['content'] ?? null) ? $output['content'] : array() as $content) {
                if (($content['type'] ?? '') === 'output_text' && isset($content['text'])) {
                    $parts[] = (string)$content['text'];
                }
            }
        }
        $text = trim(implode("\n", $parts));
        if ($text === '') {
            throw new RuntimeException('AI debrief response was empty.');
        }
        return $text;
    }

    /** @param array<string,mixed>|null $old @param array<string,mixed>|null $new */
    private function structuredAudit(
        int $debriefId,
        string $eventType,
        ?int $actorUserId,
        ?array $old,
        ?array $new,
        string $reason
    ): void {
        $this->pdo->prepare(
            'INSERT INTO ipca_structured_debrief_audit
             (event_uuid, debrief_id, event_type, actor_user_id, old_values_json, new_values_json, reason)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute(array(
            AuditEventService::uuid(), $debriefId, $eventType, $actorUserId,
            $old === null ? null : AuditEventService::jsonEncode($old),
            $new === null ? null : AuditEventService::jsonEncode($new),
            $reason,
        ));
        (new AuditEventService($this->pdo))->record(
            $eventType,
            'ipca_structured_debriefs',
            (string)$debriefId,
            $old,
            $new,
            $reason,
            $actorUserId === null ? 'system' : 'user',
            $actorUserId,
            null,
            null,
            1,
            'cvr_reconstruction'
        );
    }

    private function nextPackageVersion(int $flightRecordVersionId): int
    {
        $stmt = $this->pdo->prepare('SELECT COALESCE(MAX(package_version), 0) + 1 FROM ipca_flight_evidence_packages WHERE flight_record_version_id = ?');
        $stmt->execute(array($flightRecordVersionId));
        return (int)$stmt->fetchColumn();
    }
}
