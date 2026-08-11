<?php
declare(strict_types=1);

require_once __DIR__ . '/AuditEventService.php';
require_once __DIR__ . '/openai.php';
require_once __DIR__ . '/time.php';

final class FlightDebriefService
{
    private const PROMPT_VERSION = '1-4-9-v5-chief-instructor-voice';
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
    /**
     * @param (callable(int,string):void)|null $onProgress
     */
    public function generateStructuredDebrief(int $bundleId, ?int $actorUserId = null, ?callable $onProgress = null): array
    {
        $report = static function (int $progress, string $message) use ($onProgress): void {
            if ($onProgress === null) {
                return;
            }
            try {
                $onProgress($progress, $message);
            } catch (Throwable) {
                // Progress reporting must never fail generation.
            }
        };

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
        $usesGenericMissionRubric = false;
        if (!is_array($exercise['scenario_plan'] ?? null) || !is_array($exercise['evaluation_rubric'] ?? null)) {
            $exercise = $this->genericExercise((string)($bundle['mission_code'] ?? ''));
            $usesGenericMissionRubric = true;
        }
        $evidence = $this->structuredEvidence($bundle, $snapshot);
        $report(20, 'Preparing evidence');
        $encodedEvidence = AuditEventService::jsonEncode($evidence);
        if (str_contains(strtolower($encodedEvidence), 'flightcircle')) {
            throw new RuntimeException('FlightCircle evidence is prohibited from AI debrief generation.');
        }
        $prompt = $this->structuredPrompt($exercise, $evidence);
        $report(35, 'Building prompt');
        $request = array(
            'model' => cw_openai_model(),
            'input' => array(
                array('role' => 'system', 'content' => array(array('type' => 'input_text', 'text' => $this->structuredSystemPrompt()))),
                array('role' => 'user', 'content' => array(array('type' => 'input_text', 'text' => $prompt))),
            ),
        );
        $report(45, 'Calling AI model');
        $response = cw_openai_responses($request, 600);
        $report(75, 'Normalizing draft');
        $rawText = $this->responseText($response);
        $decoded = $this->decodeModelJson($rawText);
        $normalized = $this->normalizeStructuredDebrief($decoded, $exercise['evaluation_rubric']);
        $overall = $this->calculateSuggestedOverall($normalized['evaluations']);
        $normalized['suggested_overall'] = $overall['result'];

        $report(95, 'Saving draft');
        $this->pdo->beginTransaction();
        try {
            $previous = $this->pdo->prepare(
                'SELECT d.id
                 FROM ipca_structured_debriefs d
                 INNER JOIN ipca_manual_intake_bundles b ON b.id = d.bundle_id
                 WHERE b.workflow_flight_record_uuid = ?
                 ORDER BY d.id DESC LIMIT 1 FOR UPDATE'
            );
            $previous->execute(array((string)$bundle['workflow_flight_record_uuid']));
            $supersedes = (int)$previous->fetchColumn() ?: null;
            $insert = $this->pdo->prepare(
                'INSERT INTO ipca_structured_debriefs
                 (debrief_uuid, bundle_id, mission_version_id, transcript_snapshot_id, supersedes_debrief_id,
                  status, evidence_stage, provider, model, prompt_version, logic_version, prompt_sha256, request_sha256,
                  response_sha256, raw_response_json, general_text, chronological_review_json,
                  mission_assessment_text, summary_next_steps_text, suggested_overall,
                  overall_calculation_json, uncertainty_json, created_by)
                 VALUES (?, ?, ?, ?, ?, \'ai_draft\', ?, \'openai\', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $insert->execute(array(
                AuditEventService::uuid(), $bundleId,
                isset($missionVersion['id']) && (int)$missionVersion['id'] > 0 ? (int)$missionVersion['id'] : null,
                (int)$snapshot['id'], $supersedes,
                (string)($bundle['evidence_stage'] ?? 'final_enriched'),
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
                'uses_generic_mission_rubric' => $usesGenericMissionRubric,
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
                    COALESCE(fla.starting_hobbs, d.starting_hobbs) AS starting_hobbs,
                    COALESCE(fla.starting_tacho, d.starting_tacho) AS starting_tacho,
                    COALESCE(fla.ending_hobbs, c.ending_hobbs) AS ending_hobbs,
                    COALESCE(fla.ending_tacho, c.ending_tacho) AS ending_tacho,
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
                    g.airframe_hours_start AS garmin_hobbs_start_hours,
                    g.engine_hours_start AS garmin_tacho_start_hours,
                    cr.started_at AS recording_start_utc, cr.duration_seconds AS recording_duration_seconds,
                    (SELECT COUNT(*) FROM ipca_cvr_flight_events fl
                     WHERE fl.workflow_flight_record_uuid = b.workflow_flight_record_uuid
                       AND fl.event_type = \'gps_landing_provisional\') AS gps_landing_count
             FROM ipca_manual_intake_bundles b
             INNER JOIN ipca_cvr_dispatches d ON d.id = b.dispatch_id
             INNER JOIN ipca_cockpit_recordings cr ON cr.id = b.cockpit_recording_id
             LEFT JOIN ipca_garmin_csv_files g ON g.id = b.garmin_csv_file_id
             LEFT JOIN ipca_aircraft_devices a ON a.id = d.aircraft_id
             LEFT JOIN ipca_flight_sessions gs ON gs.id = g.session_id
             LEFT JOIN ipca_operational_flight_records gr ON gr.id = gs.current_flight_record_id
             LEFT JOIN ipca_cvr_flight_closures c ON c.id = (
               SELECT fc.id FROM ipca_cvr_flight_closures fc
               WHERE fc.workflow_flight_record_uuid = b.workflow_flight_record_uuid
               ORDER BY fc.id DESC LIMIT 1
             )
             LEFT JOIN ipca_cvr_flight_log_adjustments fla ON fla.id = (
               SELECT adj.id FROM ipca_cvr_flight_log_adjustments adj
               WHERE adj.workflow_flight_record_uuid = b.workflow_flight_record_uuid
               ORDER BY adj.created_at DESC, adj.id DESC LIMIT 1
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
        $flightRecordVersionId = (int)($debrief['context']['operational_flight_record_version_id'] ?? 0);
        $debrief['context']['legs'] = array();
        $debrief['context']['logbook_proposal'] = array();
        if ($flightRecordVersionId > 0) {
            $legs = $this->pdo->prepare(
                'SELECT leg_index, departure_airport_code, arrival_airport_code,
                        allocation_start_utc, allocation_end_utc,
                        COALESCE(administrative_departure_utc, takeoff_utc, allocation_start_utc) AS departure_utc,
                        COALESCE(administrative_arrival_utc, landing_utc, allocation_end_utc) AS arrival_utc,
                        allocated_hobbs_duration_ms, allocated_tacho_duration_ms,
                        fuel_start_usg, fuel_end_usg, fuel_used_usg, night_duration_ms,
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
        $this->enrichLogbookContext($debrief['context']);
        return $debrief;
    }

    /** @param array<string,mixed> $context */
    private function enrichLogbookContext(array &$context): void
    {
        $legs = is_array($context['legs'] ?? null) ? $context['legs'] : array();
        $firstLeg = $legs[0] ?? null;
        $lastLeg = $legs !== array() ? $legs[array_key_last($legs)] : null;
        $context['operational_timezone'] = cw_logbook_display_timezone(
            $this->pdo,
            (int)($context['aircraft_id'] ?? 0) > 0 ? (int)$context['aircraft_id'] : null,
            is_array($firstLeg) ? (string)($firstLeg['departure_airport_code'] ?? '') : null,
            is_array($lastLeg) ? (string)($lastLeg['arrival_airport_code'] ?? '') : null
        );
        $flightRecordVersionId = (int)($context['operational_flight_record_version_id'] ?? 0);
        $logbookTimezone = (string)($context['operational_timezone'] ?? 'UTC');
        $recordEngineStart = $this->flightRecordEventUtc($flightRecordVersionId, 'ENGINE_START', false);
        $recordEngineStop = $this->flightRecordEventUtc($flightRecordVersionId, 'ENGINE_STOP', true);
        $appEngineStart = $this->firstNonEmptyString(
            $context['engine_start_utc'] ?? null,
            $context['app_engine_start_utc'] ?? null
        );
        $appEngineStop = $this->firstNonEmptyString($context['app_engine_stop_utc'] ?? null);

        $offBlockUtc = $this->firstNonEmptyString(
            $appEngineStart,
            $recordEngineStart,
            is_array($firstLeg) ? ($firstLeg['allocation_start_utc'] ?? null) : null,
            is_array($firstLeg) ? ($firstLeg['departure_utc'] ?? null) : null,
            $context['avionics_on_utc'] ?? null,
            $context['garmin_start_utc'] ?? null,
            $context['recording_start_utc'] ?? null
        );

        $hobbsDurationMs = $context['exact_hobbs_duration_ms'] ?? null;
        if (!is_numeric($hobbsDurationMs) && is_array($firstLeg) && is_numeric($firstLeg['allocated_hobbs_duration_ms'] ?? null)) {
            $hobbsDurationMs = (int)$firstLeg['allocated_hobbs_duration_ms'];
        }
        $computedOnFromHobbs = null;
        if ($offBlockUtc !== null && is_numeric($hobbsDurationMs) && (int)$hobbsDurationMs >= 0) {
            $offBlockTs = strtotime($offBlockUtc);
            if ($offBlockTs !== false) {
                $computedOnFromHobbs = gmdate('Y-m-d H:i:s', (int)round($offBlockTs + ((int)$hobbsDurationMs / 1000)));
            }
        }

        $onBlockUtc = $this->firstNonEmptyString(
            $computedOnFromHobbs,
            $appEngineStop,
            $context['engine_stop_utc'] ?? null,
            $recordEngineStop,
            is_array($lastLeg) ? ($lastLeg['allocation_end_utc'] ?? null) : null,
            is_array($lastLeg) ? ($lastLeg['arrival_utc'] ?? null) : null,
            $context['avionics_off_utc'] ?? null,
            $context['garmin_end_utc'] ?? null
        );

        if ($offBlockUtc !== null) {
            $context['engine_start_utc'] = $offBlockUtc;
        }
        if ($onBlockUtc !== null) {
            $context['engine_stop_utc'] = $onBlockUtc;
            if ($computedOnFromHobbs !== null) {
                $context['on_block_derivation'] = 'off_block_plus_hobbs_delta';
            }
        }

        if ($computedOnFromHobbs !== null && $appEngineStop !== null) {
            $observedEngineStop = strtotime($appEngineStop);
            $computedTs = strtotime($computedOnFromHobbs);
            if ($observedEngineStop !== false && $computedTs !== false && abs($observedEngineStop - $computedTs) > 60) {
                $context['block_time_discrepancies'][] = sprintf(
                    'ON Block discrepancy: App Engine Stop was %s LT, while OFF Block plus Hobbs END minus START gives %s LT.',
                    cw_logbook_time(gmdate('Y-m-d H:i:s', $observedEngineStop), $logbookTimezone),
                    cw_logbook_time(gmdate('Y-m-d H:i:s', $computedTs), $logbookTimezone)
                );
            }
        }

        $summary = json_decode((string)($context['summary_json'] ?? '{}'), true);
        $crew = is_array($summary['preview']['calculations']['crew_reconciliation'] ?? null)
            ? $summary['preview']['calculations']['crew_reconciliation']
            : array();

        $hobbsStart = $this->firstNumeric(
            $context['starting_hobbs'] ?? null,
            $crew['crew_provided_hobbs_start'] ?? null,
            $crew['crew_hobbs_start'] ?? null,
            $context['hobbs_start_hours'] ?? null
        );
        $tachoStart = $this->firstNumeric(
            $context['starting_tacho'] ?? null,
            $crew['crew_provided_tacho_start'] ?? null,
            $crew['crew_tacho_start'] ?? null,
            $context['tacho_start_hours'] ?? null
        );
        $hobbsEnd = $this->firstNumeric(
            $context['ending_hobbs'] ?? null,
            $crew['crew_hobbs_end'] ?? null,
            $context['hobbs_end_hours'] ?? null
        );
        $tachoEnd = $this->firstNumeric(
            $context['ending_tacho'] ?? null,
            $crew['crew_tacho_end'] ?? null,
            $context['tacho_end_hours'] ?? null
        );

        $this->appendDispatchStartVerification(
            $context,
            $hobbsStart,
            $tachoStart,
            $crew,
            0.1
        );

        if ($hobbsEnd === null && $hobbsStart !== null && is_numeric($hobbsDurationMs)) {
            $hobbsEnd = $hobbsStart + ((int)$hobbsDurationMs / 3600000);
        }
        if ($tachoEnd === null && $tachoStart !== null && is_numeric($context['exact_tacho_duration_ms'] ?? null)) {
            $tachoEnd = $tachoStart + ((int)$context['exact_tacho_duration_ms'] / 3600000);
        }

        if ($hobbsStart !== null) {
            $context['hobbs_start_hours'] = $this->roundLogbookMeter($hobbsStart);
        }
        if ($tachoStart !== null) {
            $context['tacho_start_hours'] = $this->roundLogbookMeter($tachoStart);
        }
        if ($hobbsEnd !== null) {
            $context['hobbs_end_hours'] = $this->roundLogbookMeter($hobbsEnd);
        }
        if ($tachoEnd !== null) {
            $context['tacho_end_hours'] = $this->roundLogbookMeter($tachoEnd);
        }
    }

    private function roundLogbookMeter(float $value): float
    {
        return round($value, 1);
    }

    /** @param array<string,mixed> $context @param array<string,mixed> $crew */
    private function appendDispatchStartVerification(array &$context, ?float $hobbsStart, ?float $tachoStart, array $crew, float $toleranceHours): void
    {
        $garminHobbsStart = $this->firstNumeric(
            $crew['garmin_hobbs_start'] ?? null,
            $context['garmin_hobbs_start_hours'] ?? null
        );
        $garminTachoStart = $this->firstNumeric(
            $crew['garmin_tacho_start'] ?? null,
            $context['garmin_tacho_start_hours'] ?? null
        );
        if ($hobbsStart !== null && $garminHobbsStart !== null && abs($hobbsStart - $garminHobbsStart) > $toleranceHours) {
            $context['block_time_discrepancies'][] = sprintf(
                'Dispatch Hobbs start %.1f differs from Garmin airframe_hours %.1f; verify the dispatch entry.',
                $hobbsStart,
                $garminHobbsStart
            );
        }
        if ($tachoStart !== null && $garminTachoStart !== null && abs($tachoStart - $garminTachoStart) > $toleranceHours) {
            $context['block_time_discrepancies'][] = sprintf(
                'Dispatch Tacho start %.1f differs from Garmin engine_hours %.1f; verify the dispatch entry.',
                $tachoStart,
                $garminTachoStart
            );
        }
    }

    private function flightRecordEventUtc(int $flightRecordVersionId, string $eventType, bool $latest): ?string
    {
        if ($flightRecordVersionId <= 0 || !$this->tableExists('ipca_flight_airport_event_versions')) {
            return null;
        }
        $order = $latest ? 'DESC' : 'ASC';
        $statement = $this->pdo->prepare(
            'SELECT event_time_utc
             FROM ipca_flight_airport_event_versions
             WHERE flight_record_version_id = ? AND event_type = ?
             ORDER BY event_time_utc ' . $order . ' LIMIT 1'
        );
        $statement->execute(array($flightRecordVersionId, $eventType));
        $value = trim((string)$statement->fetchColumn());
        return $value !== '' ? $value : null;
    }

    private function firstNonEmptyString(mixed ...$values): ?string
    {
        foreach ($values as $value) {
            $text = trim((string)($value ?? ''));
            if ($text !== '' && $text !== '0000-00-00 00:00:00') {
                return $text;
            }
        }
        return null;
    }

    private function firstNumeric(mixed ...$values): ?float
    {
        foreach ($values as $value) {
            if (is_numeric($value)) {
                return (float)$value;
            }
        }
        return null;
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
        $evidenceStage = (string)($bundle['evidence_stage'] ?? 'final_enriched');
        $limitations = array(
            'Garmin measures aircraft performance and flight path; it does not prove prompting or decision quality.',
            'ADS-B provides traffic context; it does not prove the student saw traffic.',
            'Transcript absence is insufficient evidence, not automatic NO.',
        );
        if ($evidenceStage === 'preliminary') {
            array_unshift(
                $limitations,
                'This is a PRELIMINARY shutdown debrief. Garmin evidence is not available yet; do not infer Garmin-derived performance facts.'
            );
        }
        return array(
            'bundle_uuid' => $bundle['bundle_uuid'],
            'manifest_sha256' => $bundle['manifest_sha256'],
            'evidence_stage' => $evidenceStage,
            'garmin_available' => $evidenceStage !== 'preliminary' && $timeline !== array(),
            'transcript_snapshot' => array(
                'snapshot_uuid' => $snapshot['snapshot_uuid'],
                'sha256' => $snapshot['transcript_sha256'],
                'text' => (string)$snapshot['transcript_text'],
                'chunks' => $chunks,
            ),
            'event_markers' => $events,
            'garmin_reconstruction_timeline' => $timeline,
            'adsb_context' => $adsb,
            'source_limitations' => $limitations,
        );
    }

    /** @return array<string,mixed> */
    private function genericExercise(string $missionCode): array
    {
        $missionLabel = trim($missionCode) !== '' ? trim($missionCode) : 'unspecified mission';
        $taskScale = array(
            'DE' => 'Describe at rote level.',
            'EX' => 'Explain underlying concepts, principles and procedures.',
            'PR' => 'Plan and execute with coaching, instruction or assistance.',
            'PE' => 'Perform without instructor assistance; identify and correct deviations expeditiously.',
            'NO' => 'Not observed or not required.',
        );
        $srmScale = array(
            'EX' => 'Verbally identify relevant risks.',
            'PR' => 'Identify and understand risks; prompting may be needed.',
            'MD' => 'Gather key data, evaluate options and decide appropriately without instructor intervention.',
            'NO' => 'Not observed or not required.',
        );
        return array(
            'metadata' => array(
                'mission_code' => $missionLabel,
                'canonical' => false,
                'rubric_source' => 'generic_evidence_led_fallback',
            ),
            'scenario_plan' => array(
                'objective' => 'Produce an evidence-led post-flight review without assuming mission-specific maneuvers or standards.',
                'type' => 'GENERIC',
                'phases' => array(
                    array('id' => 'preflight_departure', 'title' => 'Preparation and departure'),
                    array('id' => 'flight_execution', 'title' => 'Flight execution and operational decisions'),
                    array('id' => 'arrival_postflight', 'title' => 'Arrival, landing and post-flight review'),
                ),
            ),
            'evaluation_rubric' => array(
                'task_scale' => $taskScale,
                'srm_scale' => $srmScale,
                'tasks' => array(
                    array('id' => 'generic.procedural_discipline', 'title' => 'Procedural Discipline', 'required' => true, 'required_standard' => 'PR', 'grade_scale' => array_keys($taskScale)),
                    array('id' => 'generic.aircraft_control', 'title' => 'Aircraft Control and Flight Execution', 'required' => true, 'required_standard' => 'PR', 'grade_scale' => array_keys($taskScale)),
                    array('id' => 'generic.communication_coordination', 'title' => 'Communication and Crew Coordination', 'required' => true, 'required_standard' => 'PR', 'grade_scale' => array_keys($taskScale)),
                    array('id' => 'generic.takeoff_arrival_landing', 'title' => 'Takeoff, Arrival and Landing', 'required' => true, 'required_standard' => 'PR', 'grade_scale' => array_keys($taskScale)),
                ),
                'srm_items' => array(
                    array('id' => 'srm.safety_management', 'title' => 'Safety Management', 'required' => true, 'required_standard' => 'PR', 'grade_scale' => array_keys($srmScale)),
                    array('id' => 'generic.srm.risk_management', 'title' => 'Risk Management', 'required' => true, 'required_standard' => 'PR', 'grade_scale' => array_keys($srmScale)),
                    array('id' => 'generic.srm.decision_making', 'title' => 'Aeronautical Decision Making', 'required' => true, 'required_standard' => 'PR', 'grade_scale' => array_keys($srmScale)),
                    array('id' => 'generic.srm.situational_awareness', 'title' => 'Situational Awareness', 'required' => true, 'required_standard' => 'PR', 'grade_scale' => array_keys($srmScale)),
                ),
                'overall_rules' => array(
                    'BLUE' => 'At least 25% above required, none below.',
                    'GREEN' => 'All assessed items meet or exceed required, fewer than 25% above.',
                    'YELLOW' => 'Up to 25% of assessed items are below required.',
                    'RED' => 'More than 25% are below required or safety management is insufficient.',
                    'INCOMPLETE' => 'Any required item is explicitly not completed.',
                ),
                'evidence_rules' => array(
                    'This is a generic fallback rubric, not canonical mission data.',
                    'Do not infer mission-specific maneuvers, tolerances, or completion requirements.',
                    'Absence from transcript is insufficient evidence, never automatic NO.',
                    'Instructor grading and approval remain authoritative.',
                ),
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
            'You draft a post-flight debrief for instructor review—as if handwritten by a senior Part 141 Chief Flight Instructor with 15,000+ hours who genuinely enjoys teaching and is sitting with the student immediately after the lesson.',
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
            'CHRONOLOGICAL REVIEW — titles: Put only the descriptive segment name in title. Never prefix titles with letters or numbers (no "A.", "B.", "1."). Examples: "Preflight, Cockpit Setup & Taxi", "Takeoff & Departure", "Training Area – Slow Flight". The application adds lettering.',
            'CHRONOLOGICAL REVIEW — structure: Vary each segment naturally. Do NOT use the same subsection template every time. Not every segment needs bullet lists or subheadings.',
            'Some segments may be a short conversational paragraph only. Some may include a few coaching bullets. Some may end with one encouraging sentence. Some need no bullets at all.',
            'Avoid repeating subsection labels such as "Key learning points", "Main takeaway", "Next time", "Main reminders" in every segment. If you use a subheading once, skip it elsewhere. Prefer flowing prose.',
            'CHRONOLOGICAL REVIEW — voice: Write as the instructor speaking directly to the student ("you/your"), not about the student ("the customer", "the pilot in training"). Sound like a briefing-room conversation, not an evaluator report or FAA audit.',
            'Prefer: "During slow flight you needed a bit of coaching with the setup, which is completely normal at this stage. Once the airplane was stabilized your control became much smoother."',
            'Avoid: "The slow-flight sequence needed some coaching." / "The lesson showed readiness in some areas." / "Performance concern." / "The evidence suggests." / "Required prompting." / "Root cause."',
            'Explain WHY corrections matter—not just what to do. Example: "Think of the runway centerline as something you protect continuously. Small corrections early are almost invisible; larger corrections later create unnecessary workload."',
            'When the student improves during the lesson, acknowledge it in that same segment—not only at the end. Example: "After we discussed the correction, your very next approach was noticeably better. That is exactly what we want."',
            'Connect segments so the student relives the flight chronologically. Each segment should flow naturally from the previous one.',
            'Use human instructor phrasing naturally and sparingly, e.g. "One thing I would like you to pay attention to…", "The good news is…", "What impressed me here was…", "This became much better later in the lesson.", "Do not overthink this…", "Give yourself another second before…", "You are closer than you probably think.", "This will come with repetition."',
            'Reduce repetitive stock phrases: avoid overusing "Next time", "Main takeaway", "Key learning points", "You needed coaching", "Continue to", and "Focus on". Vary wording.',
            'GENERAL: Open with a warm, specific overview of the flight as a whole—what the lesson was about and the overall tone of progress.',
            'MISSION_STANDARDS_ASSESSMENT: Honest, balanced, encouraging summary of scenario progress. Lead with strengths. Never read like a checkride sheet.',
            'SUMMARY_NEXT_STEPS: End the entire debrief on encouragement. Highlight progress, what improved during the lesson, and confidence for the next flight while staying honest. The student should finish thinking: "I know what to improve, but I am excited to go fly again." Never end with a list of deficiencies alone.',
            'Student-facing prose must not sound like an audit, compliance report, checkride report, FAA evaluation, or examiner narrative. Evidence and confidence terminology belongs only in evaluation rationale and evidence_refs—not in general, chronological_review narratives, mission_standards_assessment, or summary_next_steps.',
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
            'chronological_review' => $this->sanitizeChronologicalReview(
                is_array($decoded['chronological_review'] ?? null) ? $decoded['chronological_review'] : array()
            ),
            'mission_standards_assessment' => trim((string)($decoded['mission_standards_assessment'] ?? 'See task-level evidence suggestions.')),
            'summary_next_steps' => trim((string)($decoded['summary_next_steps'] ?? 'Review evidence and agree specific next steps with the student.')),
            'evaluations' => $evaluations,
            'uncertainties' => is_array($decoded['uncertainties'] ?? null) ? $decoded['uncertainties'] : array(),
        );
    }

    /**
     * @param list<array<string,mixed>> $segments
     * @return list<array<string,mixed>>
     */
    private function sanitizeChronologicalReview(array $segments): array
    {
        $sanitized = array();
        foreach ($segments as $segment) {
            if (!is_array($segment)) {
                continue;
            }
            $title = $this->sanitizeChronologicalTitle((string)($segment['title'] ?? 'Flight Segment'));
            $sanitized[] = array_merge($segment, array(
                'title' => $title !== '' ? $title : 'Flight Segment',
                'narrative' => trim((string)($segment['narrative'] ?? '')),
            ));
        }
        return $sanitized;
    }

    private function sanitizeChronologicalTitle(string $title): string
    {
        $title = trim($title);
        while ($title !== '' && preg_match('/^[A-Z]\.\s*/', $title)) {
            $title = preg_replace('/^[A-Z]\.\s*/', '', $title, 1) ?? $title;
            $title = trim($title);
        }
        return trim($title, " \t\n\r\0\x0B.-");
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
    private function missionVersion(string $missionCode): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT v.* FROM ipca_missions m
             INNER JOIN ipca_mission_versions v ON v.id = m.current_version_id
             WHERE UPPER(m.code) = UPPER(?) LIMIT 1'
        );
        $statement->execute(array(trim($missionCode)));
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
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
