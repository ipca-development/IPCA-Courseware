<?php
declare(strict_types=1);

require_once __DIR__ . '/AuditEventService.php';

/**
 * Evidence-based flight exercise identification.
 *
 * Identifies which exercises occurred from crew markers, transcript chunks, and CSV/telemetry.
 * ACS and SOP bindings are loaded for foresight/reference only — evaluation_enabled stays off in v1.
 */
final class FlightExerciseIdentificationService
{
    private const CATALOG_TABLE = 'ipca_flight_exercise_catalog';
    private const DETECTED_TABLE = 'ipca_detected_flight_exercises';
    private const ACS_TABLE = 'ipca_flight_exercise_acs_bindings';
    private const SOP_TABLE = 'ipca_flight_exercise_sop_bindings';
    private const DETECTOR_VERSION = 'v1';

    public function __construct(private PDO $pdo)
    {
    }

    public function tablesPresent(): bool
    {
        return $this->tableExists(self::CATALOG_TABLE) && $this->tableExists(self::DETECTED_TABLE);
    }

    /**
     * Identify exercises for a recording and replace prior detector rows for this recording/version.
     *
     * @param array<string,mixed> $recording
     * @param list<array<string,mixed>> $replaySamples Slim/public replay samples with t, rpm, aoa, etc.
     * @return list<array<string,mixed>>
     */
    public function identifyForRecording(array $recording, array $replaySamples = array()): array
    {
        if (!$this->tablesPresent()) {
            return array();
        }

        $recordingId = (int)($recording['id'] ?? 0);
        if ($recordingId <= 0) {
            return array();
        }

        $catalog = $this->activeCatalog();
        if ($catalog === array()) {
            return array();
        }

        $crewEvents = $this->crewEventsForRecording($recording);
        $chunks = $this->readyTranscriptChunks($recordingId);
        $samples = $replaySamples !== array() ? $replaySamples : $this->loadReplaySamples($recordingId);
        $acsByExercise = $this->acsTaskCodesByExercise();

        $candidates = array();
        foreach ($catalog as $exercise) {
            $found = $this->detectExercise($exercise, $crewEvents, $chunks, $samples, $acsByExercise);
            foreach ($found as $instance) {
                $candidates[] = $instance;
            }
        }

        $workflowUuid = strtolower(trim((string)($recording['flight_session_uid'] ?? '')));
        if ($workflowUuid === '') {
            $workflowUuid = null;
        }
        foreach ($candidates as &$candidate) {
            $candidate['workflow_flight_record_uuid'] = $workflowUuid;
        }
        unset($candidate);

        $candidates = $this->dedupeCandidates($candidates);
        $candidates = $this->dedupeAcrossExercises($candidates);
        $this->replaceDetectedForRecording($recordingId, $candidates);
        return $candidates;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function detectedRowsForRecording(int $recordingId): array
    {
        if (!$this->tablesPresent() || $recordingId <= 0) {
            return array();
        }
        $stmt = $this->pdo->prepare(
            'SELECT * FROM ' . self::DETECTED_TABLE . '
              WHERE recording_id = ?
              ORDER BY t_start_seconds ASC, id ASC'
        );
        $stmt->execute(array($recordingId));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            return array();
        }
        return array_map(fn(array $row): array => $this->publicDetected($row), $rows);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function activeCatalog(): array
    {
        $stmt = $this->pdo->query(
            'SELECT * FROM ' . self::CATALOG_TABLE . '
              WHERE is_active = 1
              ORDER BY sort_order ASC, id ASC'
        );
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : array();
        if (!is_array($rows)) {
            return array();
        }
        $out = array();
        foreach ($rows as $row) {
            $out[] = array(
                'exercise_code' => (string)$row['exercise_code'],
                'display_name' => (string)$row['display_name'],
                'aliases' => $this->decodeStringList((string)($row['transcript_aliases_json'] ?? '[]')),
                'rules' => $this->decodeObject((string)($row['detection_rules_json'] ?? '{}')),
                'detector_version' => (string)($row['detector_version'] ?? self::DETECTOR_VERSION),
            );
        }
        return $out;
    }

    /**
     * @return array<string,list<string>>
     */
    private function acsTaskCodesByExercise(): array
    {
        if (!$this->tableExists(self::ACS_TABLE)) {
            return array();
        }
        $stmt = $this->pdo->query(
            'SELECT exercise_code, acs_task_code
               FROM ' . self::ACS_TABLE . '
              WHERE is_active = 1
              ORDER BY qualification_code ASC, acs_task_code ASC'
        );
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : array();
        $map = array();
        foreach (is_array($rows) ? $rows : array() as $row) {
            $code = (string)$row['exercise_code'];
            $map[$code] ??= array();
            $map[$code][] = (string)$row['acs_task_code'];
        }
        return $map;
    }

    /**
     * @param array<string,mixed> $exercise
     * @param list<array<string,mixed>> $crewEvents
     * @param list<array<string,mixed>> $chunks
     * @param list<array<string,mixed>> $samples
     * @param array<string,list<string>> $acsByExercise
     * @return list<array<string,mixed>>
     */
    private function detectExercise(
        array $exercise,
        array $crewEvents,
        array $chunks,
        array $samples,
        array $acsByExercise
    ): array {
        $code = (string)$exercise['exercise_code'];
        $rules = is_array($exercise['rules'] ?? null) ? $exercise['rules'] : array();
        $aliases = is_array($exercise['aliases'] ?? null) ? $exercise['aliases'] : array();
        $markerWindow = max(15.0, (float)($rules['marker_window_sec'] ?? 90));
        $transcriptWindow = max(15.0, (float)($rules['transcript_window_sec'] ?? 90));
        $crewTypes = array_map('strval', is_array($rules['crew_event_types'] ?? null) ? $rules['crew_event_types'] : array('exercise_marker'));

        $markerHits = array();
        foreach ($crewEvents as $event) {
            $type = (string)($event['event_type'] ?? '');
            if (!in_array($type, $crewTypes, true)) {
                continue;
            }
            $markerHits[] = $event;
        }

        $transcriptHits = $this->transcriptAliasHits($chunks, $aliases);
        $telemetryHits = $this->telemetryHits($code, $rules, $samples);

        $seeds = array();
        foreach ($markerHits as $marker) {
            $seeds[] = array(
                't' => (float)$marker['t'],
                'kind' => 'marker',
                'marker' => $marker,
            );
        }
        foreach ($transcriptHits as $hit) {
            $seeds[] = array(
                't' => (float)$hit['t'],
                'kind' => 'transcript',
                'transcript' => $hit,
            );
        }
        foreach ($telemetryHits as $hit) {
            $seeds[] = array(
                't' => (float)$hit['t_start'],
                'kind' => 'telemetry',
                'telemetry' => $hit,
            );
        }
        if ($seeds === array()) {
            return array();
        }

        usort($seeds, static fn(array $a, array $b): int => ($a['t'] <=> $b['t']));
        $instances = array();
        $usedSeedIndexes = array();

        for ($i = 0; $i < count($seeds); $i++) {
            if (isset($usedSeedIndexes[$i])) {
                continue;
            }
            $seed = $seeds[$i];
            $t0 = (float)$seed['t'];
            $cluster = array($seed);
            $usedSeedIndexes[$i] = true;
            for ($j = $i + 1; $j < count($seeds); $j++) {
                if (isset($usedSeedIndexes[$j])) {
                    continue;
                }
                if (abs((float)$seeds[$j]['t'] - $t0) <= max($markerWindow, $transcriptWindow)) {
                    $cluster[] = $seeds[$j];
                    $usedSeedIndexes[$j] = true;
                }
            }

            $hasMarker = false;
            $hasTranscript = false;
            $hasTelemetry = false;
            $markerUuid = null;
            $tStart = $t0;
            $tEnd = $t0;
            $evidence = array(
                'signals' => array(),
                'aliases_matched' => array(),
                'telemetry' => null,
            );

            foreach ($cluster as $item) {
                $tStart = min($tStart, (float)$item['t']);
                $tEnd = max($tEnd, (float)$item['t']);
                if ($item['kind'] === 'marker') {
                    $hasMarker = true;
                    $markerUuid = (string)($item['marker']['event_uuid'] ?? '') ?: $markerUuid;
                    $evidence['signals'][] = 'crew_marker';
                } elseif ($item['kind'] === 'transcript') {
                    $hasTranscript = true;
                    $evidence['signals'][] = 'transcript';
                    $alias = (string)($item['transcript']['alias'] ?? '');
                    if ($alias !== '') {
                        $evidence['aliases_matched'][] = $alias;
                    }
                    if (isset($item['transcript']['end'])) {
                        $tEnd = max($tEnd, (float)$item['transcript']['end']);
                    }
                } elseif ($item['kind'] === 'telemetry') {
                    $hasTelemetry = true;
                    $evidence['signals'][] = 'telemetry';
                    $evidence['telemetry'] = $item['telemetry'];
                    $tStart = min($tStart, (float)$item['telemetry']['t_start']);
                    $tEnd = max($tEnd, (float)$item['telemetry']['t_end']);
                }
            }

            // Require meaningful evidence: not telemetry-only weak heuristic alone without alias/marker,
            // except steep_turn/power_off which can stand on strong telemetry.
            $confidence = 0.0;
            if ($hasMarker && $hasTranscript) {
                $confidence = 0.88;
            } elseif ($hasMarker && $hasTelemetry) {
                $confidence = 0.78;
            } elseif ($hasTranscript && $hasTelemetry) {
                $confidence = 0.72;
            } elseif ($hasTranscript) {
                $confidence = 0.62;
            } elseif ($hasMarker) {
                // Marker alone is not enough to name the exercise.
                continue;
            } elseif ($hasTelemetry) {
                // Telemetry-only is intentionally conservative and must look airborne.
                $telemetry = is_array($evidence['telemetry'] ?? null) ? $evidence['telemetry'] : array();
                $airborneOk = $this->telemetryLooksAirborne($samples, (float)($telemetry['t_start'] ?? $tStart), (float)($telemetry['t_end'] ?? $tEnd));
                $confidence = ($airborneOk && in_array($code, array('power_off_stall', 'steep_turn'), true)) ? 0.55 : 0.0;
                if ($confidence < 0.5) {
                    continue;
                }
            } else {
                continue;
            }

            $evidence['signals'] = array_values(array_unique($evidence['signals']));
            $evidence['aliases_matched'] = array_values(array_unique($evidence['aliases_matched']));

            $instances[] = array(
                'detection_uuid' => AuditEventService::uuid(),
                'exercise_code' => $code,
                'display_name' => (string)$exercise['display_name'],
                't_start_seconds' => round($tStart, 3),
                't_end_seconds' => round(max($tEnd, $tStart), 3),
                'confidence' => round($confidence, 4),
                'detector_version' => (string)($exercise['detector_version'] ?? self::DETECTOR_VERSION),
                'source_marker_event_uuid' => $markerUuid,
                'evidence' => $evidence,
                'matched_acs_task_codes' => $acsByExercise[$code] ?? array(),
                'status' => 'identified',
            );
        }

        return $instances;
    }

    /**
     * @param list<array<string,mixed>> $chunks
     * @param list<string> $aliases
     * @return list<array<string,mixed>>
     */
    private function transcriptAliasHits(array $chunks, array $aliases): array
    {
        if ($chunks === array() || $aliases === array()) {
            return array();
        }
        $normalizedAliases = array();
        foreach ($aliases as $alias) {
            $n = $this->normalizeText((string)$alias);
            if ($n !== '') {
                $normalizedAliases[] = array('raw' => (string)$alias, 'norm' => $n);
            }
        }
        $hits = array();
        foreach ($chunks as $chunk) {
            $text = $this->normalizeText((string)($chunk['transcript_text'] ?? ''));
            if ($text === '') {
                continue;
            }
            foreach ($normalizedAliases as $alias) {
                if (!str_contains($text, $alias['norm'])) {
                    continue;
                }
                $hits[] = array(
                    't' => (float)($chunk['start_seconds'] ?? 0),
                    'end' => isset($chunk['end_seconds']) ? (float)$chunk['end_seconds'] : null,
                    'alias' => $alias['raw'],
                    'chunk_index' => (int)($chunk['chunk_index'] ?? 0),
                );
                break;
            }
        }
        return $hits;
    }

    /**
     * @param array<string,mixed> $rules
     * @param list<array<string,mixed>> $samples
     * @return list<array<string,mixed>>
     */
    private function telemetryHits(string $exerciseCode, array $rules, array $samples): array
    {
        if ($samples === array()) {
            return array();
        }
        $telemetry = is_array($rules['telemetry'] ?? null) ? $rules['telemetry'] : array();
        if ($telemetry === array()) {
            return array();
        }
        $minSeconds = max(1.0, (float)($telemetry['min_seconds'] ?? 2.0));

        if ($exerciseCode === 'power_off_stall') {
            return $this->runWindowHits($samples, $minSeconds, static function (array $s) use ($telemetry): bool {
                $rpm = isset($s['rpm']) ? (float)$s['rpm'] : null;
                $aoa = isset($s['aoa_cp']) ? (float)$s['aoa_cp'] : (isset($s['aoa']) ? (float)$s['aoa'] : null);
                $pitch = isset($s['pitch_deg']) ? (float)$s['pitch_deg'] : null;
                $gs = isset($s['groundspeed_kt']) ? (float)$s['groundspeed_kt'] : (isset($s['ground_speed_kt']) ? (float)$s['ground_speed_kt'] : null);
                $rpmMax = (float)($telemetry['rpm_max'] ?? 2000);
                $aoaMin = (float)($telemetry['aoa_cp_min'] ?? 0.75);
                $pitchMin = (float)($telemetry['pitch_min_deg'] ?? 12.0);
                $gsMax = (float)($telemetry['groundspeed_max_kt'] ?? 55.0);
                $rpmOk = $rpm !== null && $rpm < $rpmMax;
                $aoaOk = $aoa !== null && $aoa >= $aoaMin;
                $legacyOk = $pitch !== null && $pitch >= $pitchMin && $gs !== null && $gs <= $gsMax;
                return ($rpmOk && $aoaOk) || ($rpmOk && $legacyOk) || ($aoaOk && $legacyOk);
            }, 'power_off_stall_telemetry');
        }

        if ($exerciseCode === 'power_on_stall') {
            return $this->runWindowHits($samples, $minSeconds, static function (array $s) use ($telemetry): bool {
                $rpm = isset($s['rpm']) ? (float)$s['rpm'] : null;
                $aoa = isset($s['aoa_cp']) ? (float)$s['aoa_cp'] : (isset($s['aoa']) ? (float)$s['aoa'] : null);
                $pitch = isset($s['pitch_deg']) ? (float)$s['pitch_deg'] : null;
                $rpmMin = (float)($telemetry['rpm_min'] ?? 2000);
                $aoaMin = (float)($telemetry['aoa_cp_min'] ?? 0.75);
                $pitchMin = (float)($telemetry['pitch_min_deg'] ?? 15.0);
                $rpmOk = $rpm !== null && $rpm >= $rpmMin;
                $aoaOk = $aoa !== null && $aoa >= $aoaMin;
                $pitchOk = $pitch !== null && $pitch >= $pitchMin;
                return $rpmOk && ($aoaOk || $pitchOk);
            }, 'power_on_stall_telemetry');
        }

        if ($exerciseCode === 'steep_turn') {
            $bankMin = (float)($telemetry['bank_abs_min_deg'] ?? 40.0);
            return $this->runWindowHits($samples, $minSeconds, static function (array $s) use ($bankMin): bool {
                $bank = isset($s['roll_deg']) ? abs((float)$s['roll_deg']) : (isset($s['bank_deg']) ? abs((float)$s['bank_deg']) : null);
                return $bank !== null && $bank >= $bankMin;
            }, 'steep_turn_telemetry');
        }

        if ($exerciseCode === 'slow_flight') {
            $iasMax = (float)($telemetry['ias_max_kt'] ?? 55.0);
            return $this->runWindowHits($samples, max(5.0, $minSeconds), static function (array $s) use ($iasMax): bool {
                $ias = isset($s['ias_kt']) ? (float)$s['ias_kt'] : null;
                $gs = isset($s['groundspeed_kt']) ? (float)$s['groundspeed_kt'] : (isset($s['ground_speed_kt']) ? (float)$s['ground_speed_kt'] : null);
                $speed = $ias ?? $gs;
                return $speed !== null && $speed > 20.0 && $speed <= $iasMax;
            }, 'slow_flight_telemetry');
        }

        return array();
    }

    /**
     * @param list<array<string,mixed>> $samples
     */
    private function telemetryLooksAirborne(array $samples, float $tStart, float $tEnd): bool
    {
        if ($samples === array()) {
            return false;
        }
        $speeds = array();
        foreach ($samples as $sample) {
            $t = isset($sample['t']) ? (float)$sample['t'] : null;
            if ($t === null || $t < ($tStart - 1.0) || $t > ($tEnd + 1.0)) {
                continue;
            }
            $ias = isset($sample['ias_kt']) ? (float)$sample['ias_kt'] : null;
            $gs = isset($sample['groundspeed_kt']) ? (float)$sample['groundspeed_kt'] : (isset($sample['ground_speed_kt']) ? (float)$sample['ground_speed_kt'] : null);
            $speed = $ias ?? $gs;
            if ($speed !== null && is_finite($speed)) {
                $speeds[] = $speed;
            }
        }
        if ($speeds === array()) {
            return false;
        }
        $avg = array_sum($speeds) / max(1, count($speeds));
        return $avg >= 40.0;
    }

    /**
     * @param list<array<string,mixed>> $samples
     * @return list<array<string,mixed>>
     */
    private function runWindowHits(array $samples, float $minSeconds, callable $predicate, string $label): array
    {
        $hits = array();
        $run = array();
        foreach ($samples as $sample) {
            $t = isset($sample['t']) ? (float)$sample['t'] : (isset($sample['seconds_since_start']) ? (float)$sample['seconds_since_start'] : null);
            if ($t === null) {
                continue;
            }
            $row = $sample;
            $row['t'] = $t;
            if ($predicate($row)) {
                $run[] = $row;
                continue;
            }
            if ($run !== array()) {
                $start = (float)$run[0]['t'];
                $end = (float)$run[count($run) - 1]['t'];
                if (($end - $start) >= $minSeconds || count($run) >= 5) {
                    $hits[] = array(
                        't_start' => $start,
                        't_end' => $end,
                        'label' => $label,
                        'sample_count' => count($run),
                    );
                }
                $run = array();
            }
        }
        if ($run !== array()) {
            $start = (float)$run[0]['t'];
            $end = (float)$run[count($run) - 1]['t'];
            if (($end - $start) >= $minSeconds || count($run) >= 5) {
                $hits[] = array(
                    't_start' => $start,
                    't_end' => $end,
                    'label' => $label,
                    'sample_count' => count($run),
                );
            }
        }
        return $hits;
    }

    /**
     * @param list<array<string,mixed>> $candidates
     * @return list<array<string,mixed>>
     */
    private function dedupeCandidates(array $candidates): array
    {
        usort($candidates, static function (array $a, array $b): int {
            $cmp = ((float)$a['t_start_seconds'] <=> (float)$b['t_start_seconds']);
            if ($cmp !== 0) {
                return $cmp;
            }
            return ((float)$b['confidence'] <=> (float)$a['confidence']);
        });
        $kept = array();
        foreach ($candidates as $candidate) {
            $overlap = false;
            foreach ($kept as $existing) {
                if ((string)$existing['exercise_code'] !== (string)$candidate['exercise_code']) {
                    continue;
                }
                $a0 = (float)$existing['t_start_seconds'];
                $a1 = (float)($existing['t_end_seconds'] ?? $a0);
                $b0 = (float)$candidate['t_start_seconds'];
                $b1 = (float)($candidate['t_end_seconds'] ?? $b0);
                if ($b0 <= ($a1 + 20.0) && $b1 >= ($a0 - 20.0)) {
                    $overlap = true;
                    break;
                }
            }
            if (!$overlap) {
                $kept[] = $candidate;
            }
        }
        return $kept;
    }

    /**
     * Prefer one exercise instance when different codes collide on the same time/marker.
     *
     * @param list<array<string,mixed>> $candidates
     * @return list<array<string,mixed>>
     */
    private function dedupeAcrossExercises(array $candidates): array
    {
        usort($candidates, static function (array $a, array $b): int {
            $cmp = ((float)$b['confidence'] <=> (float)$a['confidence']);
            if ($cmp !== 0) {
                return $cmp;
            }
            return ((float)$a['t_start_seconds'] <=> (float)$b['t_start_seconds']);
        });
        $kept = array();
        foreach ($candidates as $candidate) {
            $overlap = false;
            $c0 = (float)$candidate['t_start_seconds'];
            $cMarker = trim((string)($candidate['source_marker_event_uuid'] ?? ''));
            foreach ($kept as $existing) {
                $e0 = (float)$existing['t_start_seconds'];
                $eMarker = trim((string)($existing['source_marker_event_uuid'] ?? ''));
                $sameMarker = ($cMarker !== '' && $cMarker === $eMarker);
                // Same coarse transcript chunk / simultaneous competing labels.
                $sameInstant = abs($c0 - $e0) <= 15.0;
                if ($sameMarker || $sameInstant) {
                    $overlap = true;
                    break;
                }
            }
            if (!$overlap) {
                $kept[] = $candidate;
            }
        }
        usort($kept, static fn(array $a, array $b): int => ((float)$a['t_start_seconds'] <=> (float)$b['t_start_seconds']));
        return $kept;
    }

    /**
     * @param list<array<string,mixed>> $candidates
     */
    private function replaceDetectedForRecording(int $recordingId, array $candidates): void
    {
        $this->pdo->prepare(
            'DELETE FROM ' . self::DETECTED_TABLE . '
              WHERE recording_id = ? AND detector_version = ?'
        )->execute(array($recordingId, self::DETECTOR_VERSION));

        if ($candidates === array()) {
            return;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO ' . self::DETECTED_TABLE . '
             (detection_uuid, recording_id, workflow_flight_record_uuid, exercise_code, display_name,
              t_start_seconds, t_end_seconds, confidence, detector_version, source_marker_event_uuid,
              evidence_json, matched_acs_task_codes_json, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        foreach ($candidates as $candidate) {
            $stmt->execute(array(
                (string)$candidate['detection_uuid'],
                $recordingId,
                $candidate['workflow_flight_record_uuid'] ?? null,
                (string)$candidate['exercise_code'],
                (string)$candidate['display_name'],
                (float)$candidate['t_start_seconds'],
                $candidate['t_end_seconds'] !== null ? (float)$candidate['t_end_seconds'] : null,
                (float)$candidate['confidence'],
                (string)$candidate['detector_version'],
                $candidate['source_marker_event_uuid'] ?? null,
                json_encode($candidate['evidence'] ?? array(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                json_encode($candidate['matched_acs_task_codes'] ?? array(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                (string)($candidate['status'] ?? 'identified'),
            ));
        }
    }

    /**
     * @param array<string,mixed> $recording
     * @return list<array<string,mixed>>
     */
    private function crewEventsForRecording(array $recording): array
    {
        $flightSessionUid = strtolower(trim((string)($recording['flight_session_uid'] ?? '')));
        if ($flightSessionUid === '' || !$this->tableExists('ipca_cvr_flight_events')) {
            return array();
        }
        $startedAt = trim((string)($recording['started_at'] ?? ''));
        $startedMs = $startedAt !== '' ? strtotime($startedAt) : false;
        $duration = max(0.0, (float)($recording['duration_seconds'] ?? 0));
        $stmt = $this->pdo->prepare(
            'SELECT event_uuid, event_type, timestamp_utc, audio_offset_seconds
               FROM ipca_cvr_flight_events
              WHERE workflow_flight_record_uuid = ?
              ORDER BY COALESCE(audio_offset_seconds, 0) ASC, timestamp_utc ASC, id ASC'
        );
        $stmt->execute(array($flightSessionUid));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $out = array();
        foreach (is_array($rows) ? $rows : array() as $row) {
            $t = null;
            if ($row['audio_offset_seconds'] !== null && $row['audio_offset_seconds'] !== '') {
                $t = (float)$row['audio_offset_seconds'];
            } elseif ($startedMs !== false) {
                $eventMs = strtotime((string)($row['timestamp_utc'] ?? ''));
                if ($eventMs !== false) {
                    $t = (float)($eventMs - $startedMs);
                }
            }
            if ($t === null || !is_finite($t)) {
                continue;
            }
            if ($duration > 0.0 && ($t < -5.0 || $t > ($duration + 30.0))) {
                continue;
            }
            $out[] = array(
                'event_uuid' => (string)($row['event_uuid'] ?? ''),
                'event_type' => (string)($row['event_type'] ?? ''),
                't' => max(0.0, $t),
            );
        }
        return $out;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function readyTranscriptChunks(int $recordingId): array
    {
        if (!$this->tableExists('ipca_cockpit_recording_transcription_chunks')) {
            return array();
        }
        $stmt = $this->pdo->prepare(
            'SELECT chunk_index, start_seconds, end_seconds, transcript_text
               FROM ipca_cockpit_recording_transcription_chunks
              WHERE recording_id = ?
                AND status = \'ready\'
                AND TRIM(COALESCE(transcript_text, \'\')) <> \'\'
              ORDER BY chunk_index ASC'
        );
        $stmt->execute(array($recordingId));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : array();
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function loadReplaySamples(int $recordingId): array
    {
        if (!$this->tableExists('ipca_cockpit_replay_samples')) {
            return array();
        }
        // ~2 Hz from 10 Hz replay samples keeps telemetry heuristics light.
        $stmt = $this->pdo->prepare(
            'SELECT time_s AS t, rpm, aoa, aoa_cp, pitch_deg, roll_deg, ias_kt,
                    ground_speed_kt AS groundspeed_kt
               FROM ipca_cockpit_replay_samples
              WHERE recording_id = ?
                AND MOD(sample_index, 5) = 0
              ORDER BY time_s ASC'
        );
        try {
            $stmt->execute(array($recordingId));
        } catch (Throwable) {
            // Older schemas may lack some columns; fail soft.
            return array();
        }
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : array();
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function publicDetected(array $row): array
    {
        return array(
            'detection_uuid' => (string)$row['detection_uuid'],
            'exercise_code' => (string)$row['exercise_code'],
            'display_name' => (string)$row['display_name'],
            't' => (float)$row['t_start_seconds'],
            'end' => $row['t_end_seconds'] !== null ? (float)$row['t_end_seconds'] : null,
            'confidence' => (float)$row['confidence'],
            'detector_version' => (string)$row['detector_version'],
            'source_marker_event_uuid' => $row['source_marker_event_uuid'] !== null ? (string)$row['source_marker_event_uuid'] : null,
            'evidence' => $this->decodeObject((string)($row['evidence_json'] ?? '{}')),
            'matched_acs_task_codes' => $this->decodeStringList((string)($row['matched_acs_task_codes_json'] ?? '[]')),
            'status' => (string)$row['status'],
            'category' => 'exercise',
            'marker' => 'green',
            'source' => 'identified_exercise',
            'event_type' => 'identified_exercise:' . (string)$row['exercise_code'],
            'title' => (string)$row['display_name'],
            'subtitle' => '',
        );
    }

    /**
     * Map in-memory replay/canonical samples into the slim telemetry shape used by heuristics.
     *
     * @param list<array<string,mixed>> $samples
     * @return list<array<string,mixed>>
     */
    public function slimTelemetrySamples(array $samples, int $stride = 5): array
    {
        $stride = max(1, $stride);
        $out = array();
        $i = 0;
        foreach ($samples as $sample) {
            if (($i++ % $stride) !== 0) {
                continue;
            }
            $t = null;
            if (isset($sample['t'])) {
                $t = (float)$sample['t'];
            } elseif (isset($sample['time_s'])) {
                $t = (float)$sample['time_s'];
            } elseif (isset($sample['seconds_since_start'])) {
                $t = (float)$sample['seconds_since_start'];
            }
            if ($t === null || !is_finite($t)) {
                continue;
            }
            $out[] = array(
                't' => $t,
                'rpm' => $sample['rpm'] ?? null,
                'aoa' => $sample['aoa'] ?? null,
                'aoa_cp' => $sample['aoa_cp'] ?? null,
                'pitch_deg' => $sample['pitch_deg'] ?? null,
                'roll_deg' => $sample['roll_deg'] ?? ($sample['bank_deg'] ?? null),
                'ias_kt' => $sample['ias_kt'] ?? null,
                'groundspeed_kt' => $sample['groundspeed_kt'] ?? ($sample['ground_speed_kt'] ?? null),
            );
        }
        return $out;
    }

    private function normalizeText(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9\\s\\-]+/', ' ', $value) ?? $value;
        $value = preg_replace('/\\s+/', ' ', $value) ?? $value;
        return trim($value);
    }

    /**
     * @return list<string>
     */
    private function decodeStringList(string $json): array
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return array();
        }
        $out = array();
        foreach ($decoded as $item) {
            $text = trim((string)$item);
            if ($text !== '') {
                $out[] = $text;
            }
        }
        return $out;
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeObject(string $json): array
    {
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : array();
    }

    private function tableExists(string $table): bool
    {
        static $cache = array();
        if (array_key_exists($table, $cache)) {
            return $cache[$table];
        }
        try {
            $stmt = $this->pdo->prepare(
                'SELECT 1 FROM information_schema.TABLES
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1'
            );
            $stmt->execute(array($table));
            $cache[$table] = (bool)$stmt->fetchColumn();
        } catch (Throwable) {
            $cache[$table] = false;
        }
        return $cache[$table];
    }
}
