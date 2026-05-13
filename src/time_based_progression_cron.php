<?php
declare(strict_types=1);

require_once __DIR__ . '/courseware_progression_v2.php';
require_once __DIR__ . '/automation_runtime.php';

final class TimeBasedProgressionCron
{
    private const LOCK_NAME = 'ipca_time_based_progression_cron';
    private const LOCK_TIMEOUT_SECONDS = 1;
    private const APPROACHING_WINDOW_HOURS = 48;

    // Q4 / BUG D: orphan-`ready` sweeper threshold.
    // A `progress_tests_v2` row in status='ready' that has never moved to 'in_progress'
    // (i.e., the student never clicked Start) is considered abandoned after this many
    // minutes and is stale-aborted by the cron. The orphan blocks new attempt creation
    // until cleared, which was the root cause for lesson-930's prolonged block.
    //
    // The default is 60 minutes (the upper bound of the user-approved range). A progress
    // test should take ~max 60 minutes in-progress; the 'ready' state is just the brief
    // window between generation and Start, so 60min is generous. Tune downward (to 15min)
    // once we have telemetry on real student start latency.
    private const ORPHAN_READY_THRESHOLD_MINUTES = 60;

    private const EVENT_CODE_DISPATCH_BEFORE = 'cron_dispatch_before';
    private const EVENT_CODE_DISPATCH_AFTER = 'cron_dispatch_after';
    private const EVENT_CODE_SKIPPED_DUPLICATE = 'cron_dispatch_skipped_duplicate';
    private const EVENT_CODE_SKIPPED_STATE_MISMATCH = 'cron_dispatch_skipped_state_mismatch';
    private const EVENT_CODE_EXISTING_ACTION_REUSED_NO_DEDUPE = 'cron_existing_action_reused_no_dedupe';

    // Q1 / BUG A:
    // The dedupe key is versioned so that fix-deployment naturally invalidates the old keys
    // (so any deadline whose action_status changes since the v=1 dispatch will be re-evaluated
    // exactly once after this deploy ships, then again whenever the status changes). The v=2
    // key includes the action_status of the currently-relevant required action so that when
    // a student submits a deadline reason (or an instructor decides on it), the key naturally
    // changes and the cron re-evaluates instead of being permanently deduped.
    private const DEDUPE_KEY_VERSION = 'v=2';

    /** @var array<string,string> */
    private const TRIGGER_TO_DEDUPE_EVENT_CODE = array(
        'deadline_approaching_48h' => 'cron_deadline_approaching_48h_dispatched',
        'deadline_today' => 'cron_deadline_today_dispatched',
        'deadline_passed' => 'cron_deadline_passed_dispatched',
    );

    private PDO $pdo;
    private CoursewareProgressionV2 $engine;
    private AutomationRuntime $automation;
    private bool $lockAcquired = false;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->engine = new CoursewareProgressionV2($pdo);
        $this->automation = new AutomationRuntime();
    }

    public function run(): array
    {
        $startedAtUtc = gmdate('Y-m-d H:i:s');
        $summary = array(
            'ok' => true,
            'started_at_utc' => $startedAtUtc,
            'finished_at_utc' => null,
            'lock_name' => self::LOCK_NAME,
            'lock_acquired' => false,
            'candidates_scanned' => 0,
            'candidates_with_triggers' => 0,
            'triggers_detected' => 0,
            'dispatch_attempts' => 0,
            'dispatch_successes' => 0,
            'dispatch_duplicates_skipped' => 0,
            'dispatch_state_mismatch_skipped' => 0,
            'aggregated_buckets_built' => 0,
            'aggregated_dispatch_attempts' => 0,
            'aggregated_dispatch_successes' => 0,
            'aggregated_dispatch_duplicates_skipped' => 0,
            'orphan_ready_swept' => 0,
            'orphan_ready_threshold_minutes' => self::ORPHAN_READY_THRESHOLD_MINUTES,
            'errors' => array(),
            'results' => array(),
            'aggregated_results' => array(),
            'orphan_ready_sweep_results' => array(),
        );

        try {
            if (!$this->acquireLock()) {
                $summary['lock_acquired'] = false;
                $summary['finished_at_utc'] = gmdate('Y-m-d H:i:s');
                $summary['ok'] = true;
                $summary['message'] = 'Cron lock not acquired. Another run is already active.';
                return $summary;
            }

            $summary['lock_acquired'] = true;

            // Q4 / BUG D: sweep orphan 'ready' rows before the deadline pass. This ensures
            // any newly-staled rows free up the lesson for a fresh attempt before the same
            // cron tick evaluates deadlines.
            $sweepResults = $this->sweepOrphanReadyAttempts();
            $summary['orphan_ready_sweep_results'] = $sweepResults;
            $summary['orphan_ready_swept'] = count($sweepResults);

            $candidates = $this->loadCandidateRows();
            $summary['candidates_scanned'] = count($candidates);

            $aggregatedReminderBuckets = array();

            foreach ($candidates as $candidateRow) {
                $candidateResult = $this->processCandidate($candidateRow);

                if (!empty($candidateResult['has_triggers'])) {
                    $summary['candidates_with_triggers']++;
                }

                $summary['triggers_detected'] += (int)($candidateResult['triggers_detected'] ?? 0);
                $summary['dispatch_attempts'] += (int)($candidateResult['dispatch_attempts'] ?? 0);
                $summary['dispatch_successes'] += (int)($candidateResult['dispatch_successes'] ?? 0);
                $summary['dispatch_duplicates_skipped'] += (int)($candidateResult['dispatch_duplicates_skipped'] ?? 0);
                $summary['dispatch_state_mismatch_skipped'] += (int)($candidateResult['dispatch_state_mismatch_skipped'] ?? 0);

                if (!empty($candidateResult['errors'])) {
                    foreach ((array)$candidateResult['errors'] as $error) {
                        $summary['errors'][] = $error;
                    }
                }

                if (!empty($candidateResult['reminder_candidates'])) {
                    foreach ((array)$candidateResult['reminder_candidates'] as $reminderCandidate) {
                        $this->addReminderCandidateToBuckets($aggregatedReminderBuckets, $reminderCandidate);
                    }
                }

                $summary['results'][] = $candidateResult;
            }

            $summary['aggregated_buckets_built'] = count($aggregatedReminderBuckets);

            foreach ($aggregatedReminderBuckets as $bucket) {
                $bucketResult = $this->dispatchAggregatedReminderBucket($bucket);

                $summary['aggregated_dispatch_attempts']++;
                $summary['dispatch_attempts']++;

                if (($bucketResult['status'] ?? '') === 'dispatched') {
                    $summary['aggregated_dispatch_successes']++;
                    $summary['dispatch_successes']++;
                } elseif (($bucketResult['status'] ?? '') === 'skipped_duplicate') {
                    $summary['aggregated_dispatch_duplicates_skipped']++;
                    $summary['dispatch_duplicates_skipped']++;
                }

                if (!empty($bucketResult['error'])) {
                    $summary['errors'][] = array(
                        'scope' => 'aggregated_bucket',
                        'event_key' => (string)($bucketResult['event_key'] ?? ''),
                        'user_id' => (int)($bucketResult['user_id'] ?? 0),
                        'cohort_id' => (int)($bucketResult['cohort_id'] ?? 0),
                        'message' => (string)$bucketResult['error'],
                    );
                }

                $summary['aggregated_results'][] = $bucketResult;
            }
        } catch (Throwable $e) {
            $summary['ok'] = false;
            $summary['errors'][] = array(
                'scope' => 'run',
                'message' => $e->getMessage(),
            );
        } finally {
            $this->releaseLock();
            $summary['finished_at_utc'] = gmdate('Y-m-d H:i:s');
        }

        return $summary;
    }

    private function acquireLock(): bool
    {
        $stmt = $this->pdo->prepare('SELECT GET_LOCK(:lock_name, :timeout_seconds)');
        $stmt->bindValue(':lock_name', self::LOCK_NAME, PDO::PARAM_STR);
        $stmt->bindValue(':timeout_seconds', self::LOCK_TIMEOUT_SECONDS, PDO::PARAM_INT);
        $stmt->execute();

        $result = $stmt->fetchColumn();
        $this->lockAcquired = ((int)$result === 1);

        return $this->lockAcquired;
    }

    private function releaseLock(): void
    {
        if (!$this->lockAcquired) {
            return;
        }

        try {
            $stmt = $this->pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
            $stmt->execute(array(
                ':lock_name' => self::LOCK_NAME,
            ));
        } catch (Throwable $e) {
            // swallow safely in cron cleanup
        }

        $this->lockAcquired = false;
    }

    /**
     * Q4 / BUG D: sweep orphan-`ready` attempts older than ORPHAN_READY_THRESHOLD_MINUTES.
     *
     * A `progress_tests_v2` row in status='ready' that has never moved to 'in_progress'
     * (no attempt_started event was ever logged for it) is considered abandoned past the
     * threshold and is stale-aborted. Without this sweep, orphan 'ready' rows accumulate
     * indefinitely and block new attempt creation for the same user/cohort/lesson — which
     * is exactly what caused lesson-930's prolonged block (test 1373 was 'ready' for days
     * because the student took the deadline-reason path instead of starting it).
     *
     * Safety constraints:
     *  - Only acts on status='ready' AND result_code IS NULL
     *  - Requires no attempt_started event ever fired for this attempt id (defensive — if
     *    the student briefly started and the session crashed mid-transition we don't want
     *    to silently invalidate in-progress work)
     *  - Skips rows that are tied to a canonical PASS in the same lesson (defensive — the
     *    presence of a PASS means a different code path should be cleaning these up via
     *    TCC repair)
     *  - Writes a full attempt_stale_aborted audit event with the trigger reason
     *
     * @return array<int,array<string,mixed>>
     */
    private function sweepOrphanReadyAttempts(): array
    {
        $thresholdMinutes = self::ORPHAN_READY_THRESHOLD_MINUTES;

        $candidatesSql = "
            SELECT
                pt.id,
                pt.user_id,
                pt.cohort_id,
                pt.lesson_id,
                pt.status,
                pt.attempt,
                pt.created_at,
                pt.updated_at,
                pt.formal_result_code,
                TIMESTAMPDIFF(MINUTE, pt.created_at, UTC_TIMESTAMP()) AS age_minutes
            FROM progress_tests_v2 pt
            WHERE pt.status = 'ready'
              AND pt.formal_result_code IS NULL
              AND pt.created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL :threshold_minutes MINUTE)
              AND NOT EXISTS (
                  SELECT 1
                  FROM training_progression_events ev
                  WHERE ev.progress_test_id = pt.id
                    AND ev.event_code IN ('attempt_started','progress_test_started')
                  LIMIT 1
              )
              AND NOT EXISTS (
                  SELECT 1
                  FROM progress_tests_v2 sibling
                  WHERE sibling.id != pt.id
                    AND sibling.user_id = pt.user_id
                    AND sibling.cohort_id = pt.cohort_id
                    AND sibling.lesson_id = pt.lesson_id
                    AND sibling.status = 'completed'
                    AND sibling.pass_gate_met = 1
                  LIMIT 1
              )
            ORDER BY pt.id ASC
            LIMIT 200
        ";

        $stmt = $this->pdo->prepare($candidatesSql);
        $stmt->bindValue(':threshold_minutes', $thresholdMinutes, PDO::PARAM_INT);
        $stmt->execute();

        $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: array();

        $sweepResults = array();

        foreach ($candidates as $candidate) {
            $testId = (int)$candidate['id'];
            $userId = (int)$candidate['user_id'];
            $cohortId = (int)$candidate['cohort_id'];
            $lessonId = (int)$candidate['lesson_id'];
            $ageMinutes = (int)$candidate['age_minutes'];

            try {
                $this->pdo->beginTransaction();

                $updateStmt = $this->pdo->prepare("
                    UPDATE progress_tests_v2
                    SET
                        status = 'failed',
                        formal_result_code = 'STALE_ABORTED',
                        counts_as_unsat = 0,
                        updated_at = UTC_TIMESTAMP()
                    WHERE id = :id
                      AND status = 'ready'
                      AND formal_result_code IS NULL
                ");
                $updateStmt->execute(array(':id' => $testId));
                $affected = $updateStmt->rowCount();

                if ($affected !== 1) {
                    $this->pdo->rollBack();
                    $sweepResults[] = array(
                        'test_id' => $testId,
                        'user_id' => $userId,
                        'cohort_id' => $cohortId,
                        'lesson_id' => $lessonId,
                        'age_minutes' => $ageMinutes,
                        'status' => 'skipped_race',
                        'message' => 'Row state changed between candidate scan and update.',
                    );
                    continue;
                }

                $this->engine->logProgressionEvent(array(
                    'user_id' => $userId,
                    'cohort_id' => $cohortId,
                    'lesson_id' => $lessonId,
                    'progress_test_id' => $testId,
                    'event_type' => 'progress_test',
                    'event_code' => 'attempt_stale_aborted',
                    'event_status' => 'warning',
                    'actor_type' => 'system',
                    'actor_user_id' => null,
                    'event_time' => gmdate('Y-m-d H:i:s'),
                    'payload' => array(
                        'progress_test_id' => $testId,
                        'previous_status' => 'ready',
                        'new_status' => 'failed',
                        'formal_result_code' => 'STALE_ABORTED',
                        'counts_as_unsat' => 0,
                        'stale_reason' => 'orphan_ready_swept',
                        'age_minutes' => $ageMinutes,
                        'threshold_minutes' => $thresholdMinutes,
                        'attempt' => (int)($candidate['attempt'] ?? 0),
                        'sweep_source' => 'TimeBasedProgressionCron::sweepOrphanReadyAttempts',
                    ),
                    'legal_note' => 'Time-based progression cron stale-aborted an orphan ready progress test attempt older than the configured threshold. No student progress data was lost because the row was never started (Q4 / BUG D).',
                ));

                $this->pdo->commit();

                $sweepResults[] = array(
                    'test_id' => $testId,
                    'user_id' => $userId,
                    'cohort_id' => $cohortId,
                    'lesson_id' => $lessonId,
                    'age_minutes' => $ageMinutes,
                    'status' => 'stale_aborted',
                );
            } catch (Throwable $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }

                $sweepResults[] = array(
                    'test_id' => $testId,
                    'user_id' => $userId,
                    'cohort_id' => $cohortId,
                    'lesson_id' => $lessonId,
                    'age_minutes' => $ageMinutes,
                    'status' => 'error',
                    'error' => $e->getMessage(),
                );
            }
        }

        return $sweepResults;
    }

    private function loadCandidateRows(): array
    {
        $sql = "
            SELECT
                cs.user_id,
                cs.cohort_id,
                cld.lesson_id,

                COALESCE(sldo.new_deadline_utc, cld.deadline_utc) AS effective_deadline_utc,

                COALESCE(la.completion_status, '') AS completion_status,
                COALESCE(la.training_suspended, 0) AS training_suspended,
                la.last_state_eval_at,
                la.updated_at

            FROM cohort_students cs

            INNER JOIN cohort_lesson_deadlines cld
                ON cld.cohort_id = cs.cohort_id

            LEFT JOIN (
                SELECT o1.user_id, o1.cohort_id, o1.lesson_id, o1.new_deadline_utc
                FROM student_lesson_deadline_overrides o1
                INNER JOIN (
                    SELECT
                        user_id,
                        cohort_id,
                        lesson_id,
                        MAX(id) AS max_id
                    FROM student_lesson_deadline_overrides
                    WHERE is_active = 1
                    GROUP BY user_id, cohort_id, lesson_id
                ) o2
                    ON o2.user_id = o1.user_id
                   AND o2.cohort_id = o1.cohort_id
                   AND o2.lesson_id = o1.lesson_id
                   AND o2.max_id = o1.id
                WHERE o1.is_active = 1
            ) sldo
                ON sldo.user_id = cs.user_id
               AND sldo.cohort_id = cs.cohort_id
               AND sldo.lesson_id = cld.lesson_id

            LEFT JOIN lesson_activity la
                ON la.user_id = cs.user_id
               AND la.cohort_id = cs.cohort_id
               AND la.lesson_id = cld.lesson_id

            WHERE cs.status = 'active'
              AND cld.deadline_utc IS NOT NULL
              AND NOT EXISTS (
                  SELECT 1
                  FROM progress_tests_v2 pt
                  WHERE pt.user_id = cs.user_id
                    AND pt.cohort_id = cs.cohort_id
                    AND pt.lesson_id = cld.lesson_id
                    AND pt.status = 'completed'
                    AND pt.pass_gate_met = 1
              )
              AND COALESCE(sldo.new_deadline_utc, cld.deadline_utc) <= DATE_ADD(UTC_TIMESTAMP(), INTERVAL :window_hours HOUR)

            ORDER BY
                COALESCE(sldo.new_deadline_utc, cld.deadline_utc) ASC,
                cs.user_id ASC,
                cs.cohort_id ASC,
                cld.lesson_id ASC
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':window_hours', self::APPROACHING_WINDOW_HOURS, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : array();
    }

    private function processCandidate(array $candidateRow): array
    {
        $userId = (int)($candidateRow['user_id'] ?? 0);
        $cohortId = (int)($candidateRow['cohort_id'] ?? 0);
        $lessonId = (int)($candidateRow['lesson_id'] ?? 0);

        $result = array(
            'user_id' => $userId,
            'cohort_id' => $cohortId,
            'lesson_id' => $lessonId,
            'has_triggers' => false,
            'triggers_detected' => 0,
            'dispatch_attempts' => 0,
            'dispatch_successes' => 0,
            'dispatch_duplicates_skipped' => 0,
            'dispatch_state_mismatch_skipped' => 0,
            'trigger_results' => array(),
            'reminder_candidates' => array(),
            'errors' => array(),
        );

        $passGuardStmt = $this->pdo->prepare("
            SELECT 1
            FROM progress_tests_v2
            WHERE user_id = :user_id
              AND cohort_id = :cohort_id
              AND lesson_id = :lesson_id
              AND status = 'completed'
              AND pass_gate_met = 1
            LIMIT 1
        ");
        $passGuardStmt->execute(array(
            ':user_id' => $userId,
            ':cohort_id' => $cohortId,
            ':lesson_id' => $lessonId,
        ));

        if ((bool)$passGuardStmt->fetchColumn()) {
            return $result + array(
                'status' => 'skipped',
                'skip_reason' => 'already_passed',
            );
        }

        try {
            $nowUtc = gmdate('Y-m-d H:i:s');

            $progressionContext = $this->engine->getProgressionContextForUserLesson($userId, $cohortId, $lessonId);
            $policy = $this->engine->resolveEffectivePolicySet($cohortId);
            $behaviorMode = $this->engine->resolveBehaviorMode($policy);
            $attemptState = $this->engine->resolveAttemptPolicyState($userId, $cohortId, $lessonId, $policy, null, $behaviorMode);
            $deadlineState = $this->engine->resolveDeadlineState($userId, $cohortId, $lessonId);

            $activityState = (array)($progressionContext['activity_state'] ?? array());

            $resolvedTriggers = $this->detectTriggers(
                $nowUtc,
                $activityState,
                $deadlineState
            );

            $projectedTriggers = $this->detectProjectedTriggers(
                $nowUtc,
                $candidateRow
            );

            if (empty($resolvedTriggers) && empty($projectedTriggers)) {
                return $result;
            }

            $result['has_triggers'] = !empty($resolvedTriggers);
            $result['triggers_detected'] = count($resolvedTriggers);

            $resolvedTriggerMap = array_fill_keys($resolvedTriggers, true);
            foreach ($projectedTriggers as $projectedTrigger) {
                if (!isset($resolvedTriggerMap[$projectedTrigger])) {
                    $result['dispatch_state_mismatch_skipped']++;
                    $result['trigger_results'][] = $this->logStateMismatch(
                        $userId,
                        $cohortId,
                        $lessonId,
                        $projectedTrigger,
                        $candidateRow,
                        $deadlineState
                    );
                }
            }

            foreach ($resolvedTriggers as $triggerEventKey) {
                if ($triggerEventKey === 'deadline_passed') {
                    $result['dispatch_attempts']++;

                    $effectiveDeadlineUtc = trim((string)($deadlineState['effective_deadline_utc'] ?? ''));
                    if ($effectiveDeadlineUtc === '') {
                        $result['dispatch_state_mismatch_skipped']++;
                        $result['trigger_results'][] = $this->logStateMismatch(
                            $userId,
                            $cohortId,
                            $lessonId,
                            $triggerEventKey,
                            $candidateRow,
                            $deadlineState
                        );
                        continue;
                    }

                    // Q1 / BUG A (Option c): Build the v=2 dedupe key including the
                    // current action_status of the most-relevant required action. When the
                    // student submits a deadline reason (or an instructor decides on it),
                    // the status changes and the key naturally changes, releasing dedupe.
                    $currentActionStatus = $this->fetchCurrentDeadlineActionStatus(
                        $userId,
                        $cohortId,
                        $lessonId
                    );

                    $dedupeKey = $this->buildDedupeKey(
                        $triggerEventKey,
                        $userId,
                        $cohortId,
                        $lessonId,
                        $effectiveDeadlineUtc,
                        $currentActionStatus
                    );

                    if ($this->hasDispatchAlreadyOccurred($userId, $cohortId, $lessonId, $triggerEventKey, $dedupeKey)) {
                        $result['dispatch_duplicates_skipped']++;
                        $result['trigger_results'][] = $this->logSkippedDuplicate(
                            $userId,
                            $cohortId,
                            $lessonId,
                            $triggerEventKey,
                            $dedupeKey,
                            $effectiveDeadlineUtc
                        );
                        continue;
                    }

                    $this->engine->logProgressionEvent(array(
                        'user_id' => $userId,
                        'cohort_id' => $cohortId,
                        'lesson_id' => $lessonId,
                        'progress_test_id' => null,
                        'event_type' => 'deadline',
                        'event_code' => 'cron_deadline_passed_engine_before',
                        'event_status' => 'info',
                        'actor_type' => 'system',
                        'actor_user_id' => null,
                        'event_time' => gmdate('Y-m-d H:i:s'),
                        'payload' => array(
                            'event_key' => $triggerEventKey,
                            'dedupe_key' => $dedupeKey,
                            'dedupe_key_version' => self::DEDUPE_KEY_VERSION,
                            'action_status_before' => $currentActionStatus,
                            'effective_deadline_utc' => $effectiveDeadlineUtc,
                        ),
                        'legal_note' => 'Time-based progression cron is delegating canonical missed-deadline handling to CoursewareProgressionV2.',
                    ));

                    $engineResult = $this->engine->handleMissedDeadlineForLesson(
                        $userId,
                        $cohortId,
                        $lessonId,
                        null
                    );

                    $actionStatusAfter = $this->fetchCurrentDeadlineActionStatus(
                        $userId,
                        $cohortId,
                        $lessonId
                    );

                    $this->engine->logProgressionEvent(array(
                        'user_id' => $userId,
                        'cohort_id' => $cohortId,
                        'lesson_id' => $lessonId,
                        'progress_test_id' => null,
                        'event_type' => 'deadline',
                        'event_code' => 'cron_deadline_passed_engine_after',
                        'event_status' => 'info',
                        'actor_type' => 'system',
                        'actor_user_id' => null,
                        'event_time' => gmdate('Y-m-d H:i:s'),
                        'payload' => array(
                            'event_key' => $triggerEventKey,
                            'dedupe_key' => $dedupeKey,
                            'dedupe_key_version' => self::DEDUPE_KEY_VERSION,
                            'action_status_before' => $currentActionStatus,
                            'action_status_after' => $actionStatusAfter,
                            'effective_deadline_utc' => $effectiveDeadlineUtc,
                            'engine_result' => $engineResult,
                        ),
                        'legal_note' => 'Time-based progression cron completed canonical missed-deadline handling through CoursewareProgressionV2.',
                    ));

                    // Q1 / BUG A (Option a): Do NOT write a dedupe event when the engine
                    // outcome was a NO-STATE-CHANGE re-use of an existing action. Writing
                    // a dedupe event in that case would let it block the very next tick
                    // even though nothing happened this tick. Instead we log a separate
                    // info-only event so the audit trail still shows the cron evaluated
                    // this case.
                    if ($this->isNoStateChangeEngineOutcome($engineResult)) {
                        $this->engine->logProgressionEvent(array(
                            'user_id' => $userId,
                            'cohort_id' => $cohortId,
                            'lesson_id' => $lessonId,
                            'progress_test_id' => null,
                            'event_type' => 'automation',
                            'event_code' => self::EVENT_CODE_EXISTING_ACTION_REUSED_NO_DEDUPE,
                            'event_status' => 'info',
                            'actor_type' => 'system',
                            'actor_user_id' => null,
                            'event_time' => gmdate('Y-m-d H:i:s'),
                            'payload' => array(
                                'event_key' => $triggerEventKey,
                                'dedupe_key' => $dedupeKey,
                                'dedupe_key_version' => self::DEDUPE_KEY_VERSION,
                                'action_taken' => (string)($engineResult['action_taken'] ?? ''),
                                'action_status_before' => $currentActionStatus,
                                'action_status_after' => $actionStatusAfter,
                                'effective_deadline_utc' => $effectiveDeadlineUtc,
                                'engine_handled' => 1,
                                'dedupe_written' => 0,
                            ),
                            'legal_note' => 'Time-based progression cron observed engine re-using an existing required action (no state change); dedupe deliberately NOT written so the next cron tick can re-evaluate immediately when action status changes (Q1 / BUG A).',
                        ));
                    } else {
                        $this->engine->logProgressionEvent(array(
                            'user_id' => $userId,
                            'cohort_id' => $cohortId,
                            'lesson_id' => $lessonId,
                            'progress_test_id' => null,
                            'event_type' => 'automation',
                            'event_code' => $this->getDedupeEventCodeForTrigger($triggerEventKey),
                            'event_status' => 'info',
                            'actor_type' => 'system',
                            'actor_user_id' => null,
                            'event_time' => gmdate('Y-m-d H:i:s'),
                            'payload' => array(
                                'event_key' => $triggerEventKey,
                                'dedupe_key' => $dedupeKey,
                                'dedupe_key_version' => self::DEDUPE_KEY_VERSION,
                                'action_taken' => (string)($engineResult['action_taken'] ?? ''),
                                'action_status_before' => $currentActionStatus,
                                'action_status_after' => $actionStatusAfter,
                                'effective_deadline_utc' => $effectiveDeadlineUtc,
                                'engine_handled' => 1,
                                'dedupe_written' => 1,
                            ),
                            'legal_note' => 'Time-based progression cron recorded missed-deadline handling for dedupe after canonical engine processing.',
                        ));
                    }

                    $result['dispatch_successes']++;
                    $result['trigger_results'][] = array(
                        'event_key' => $triggerEventKey,
                        'dedupe_key' => $dedupeKey,
                        'status' => 'engine_handled',
                        'engine_result' => $engineResult,
                    );

                    continue;
                }

                if ($this->isAggregatableReminderTrigger($triggerEventKey)) {
                    $reminderCandidate = $this->buildReminderCandidate(
                        $triggerEventKey,
                        $progressionContext,
                        $deadlineState,
                        $attemptState
                    );

                    $result['reminder_candidates'][] = $reminderCandidate;
                    $result['trigger_results'][] = array(
                        'event_key' => $triggerEventKey,
                        'bucket_key' => (string)$reminderCandidate['bucket_key'],
                        'status' => 'queued_for_aggregation',
                    );
                    continue;
                }

                $result['dispatch_state_mismatch_skipped']++;
                $result['trigger_results'][] = array(
                    'event_key' => $triggerEventKey,
                    'status' => 'skipped_unknown_trigger_type',
                );
            }
        } catch (Throwable $e) {
            $result['errors'][] = array(
                'user_id' => $userId,
                'cohort_id' => $cohortId,
                'lesson_id' => $lessonId,
                'message' => $e->getMessage(),
            );
        }

        return $result;
    }

    /**
     * @return array<int,string>
     */
    private function detectTriggers(string $nowUtc, array $activityState, array $deadlineState): array
    {
        $effectiveDeadlineUtc = trim((string)($deadlineState['effective_deadline_utc'] ?? ''));
        if ($effectiveDeadlineUtc === '') {
            return array();
        }

        $nowTs = strtotime($nowUtc);
        $deadlineTs = strtotime($effectiveDeadlineUtc);

        if ($nowTs === false || $deadlineTs === false) {
            return array();
        }

        if (!empty($deadlineState['deadline_passed'])) {
            return array('deadline_passed');
        }

        if (gmdate('Y-m-d', $nowTs) === gmdate('Y-m-d', $deadlineTs)) {
            return array('deadline_today');
        }

        $secondsUntilDeadline = $deadlineTs - $nowTs;
        if ($secondsUntilDeadline > 0 && $secondsUntilDeadline <= (self::APPROACHING_WINDOW_HOURS * 3600)) {
            return array('deadline_approaching_48h');
        }

        return array();
    }

    /**
     * @return array<int,string>
     */
    private function detectProjectedTriggers(string $nowUtc, array $candidateRow): array
    {
        $projectedState = array(
            'effective_deadline_utc' => (string)($candidateRow['effective_deadline_utc'] ?? ''),
            'deadline_passed' => false,
        );

        $effectiveDeadlineUtc = trim((string)($projectedState['effective_deadline_utc'] ?? ''));
        if ($effectiveDeadlineUtc === '') {
            return array();
        }

        $nowTs = strtotime($nowUtc);
        $deadlineTs = strtotime($effectiveDeadlineUtc);

        if ($nowTs === false || $deadlineTs === false) {
            return array();
        }

        $projectedState['deadline_passed'] = ($nowTs > $deadlineTs);

        return $this->detectTriggers(
            $nowUtc,
            array(),
            $projectedState
        );
    }

    private function buildDedupeKey(
        string $eventKey,
        int $userId,
        int $cohortId,
        int $lessonId,
        string $effectiveDeadlineUtc,
        string $actionStatus = ''
    ): string {
        // Q1 / BUG A (Option c): The v=2 key includes the action_status of the currently
        // relevant required action. When the action transitions (e.g., student submits a
        // deadline reason) the dedupe key naturally changes and the cron re-evaluates
        // exactly once for the new state, instead of remaining permanently skipped on the
        // v=1 key. A blank actionStatus (no current required action) is encoded as 'none'
        // so the key is deterministic.
        $normalizedStatus = $actionStatus === '' ? 'none' : $actionStatus;

        return self::DEDUPE_KEY_VERSION
            . '|'
            . $eventKey
            . '|'
            . $userId
            . '|'
            . $cohortId
            . '|'
            . $lessonId
            . '|'
            . $effectiveDeadlineUtc
            . '|action_status='
            . $normalizedStatus;
    }

    /**
     * Q1 / BUG A helper.
     * Returns the current 'pending' / 'opened' action status for the most-relevant required
     * action for this user/cohort/lesson, or '' if none. Order of relevance:
     *   1. instructor_approval (final escalation overrides reason flow)
     *   2. deadline_reason_submission
     * The status string is what gets baked into the v=2 dedupe key.
     */
    private function fetchCurrentDeadlineActionStatus(int $userId, int $cohortId, int $lessonId): string
    {
        $sql = "
            SELECT action_type, status
            FROM student_required_actions
            WHERE user_id = :user_id
              AND cohort_id = :cohort_id
              AND lesson_id = :lesson_id
              AND action_type IN ('instructor_approval','deadline_reason_submission')
            ORDER BY
                CASE action_type
                    WHEN 'instructor_approval' THEN 0
                    WHEN 'deadline_reason_submission' THEN 1
                    ELSE 2
                END,
                id DESC
            LIMIT 1
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':user_id' => $userId,
            ':cohort_id' => $cohortId,
            ':lesson_id' => $lessonId,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return '';
        }

        return (string)$row['action_type'] . ':' . (string)$row['status'];
    }

    /**
     * Q1 / BUG A (Option a) helper.
     * Returns true when the engine's outcome represents a NO-STATE-CHANGE re-use of an
     * existing required action. For these outcomes we do NOT write a dedupe event because:
     *   - The action is already in place; nothing happened this run.
     *   - We want the next cron tick to re-evaluate so that when the action_status changes
     *     (e.g., student submits a reason), the engine can immediately progress the case.
     * The v=2 dedupe key change (Option c) alone would also handle this, but suppressing
     * the event write keeps training_progression_events tidy and avoids accumulating no-op
     * dedupe rows for every reused action across hours of cron ticks.
     */
    private function isNoStateChangeEngineOutcome(array $engineResult): bool
    {
        $actionTaken = (string)($engineResult['action_taken'] ?? '');
        return $actionTaken === 'existing_reason_action_reused'
            || $actionTaken === 'existing_instructor_action_reused';
    }

    private function buildAggregatedDedupeKey(
        string $eventKey,
        int $userId,
        int $cohortId,
        string $effectiveDeadlineUtc
    ): string {
        return $eventKey
            . '|'
            . $userId
            . '|'
            . $cohortId
            . '|'
            . $effectiveDeadlineUtc;
    }

    private function hasDispatchAlreadyOccurred(
        int $userId,
        int $cohortId,
        int $lessonId,
        string $triggerEventKey,
        string $dedupeKey
    ): bool {
        $eventCode = $this->getDedupeEventCodeForTrigger($triggerEventKey);

        $sql = "
            SELECT 1
            FROM training_progression_events
            WHERE user_id = :user_id
              AND cohort_id = :cohort_id
              AND lesson_id = :lesson_id
              AND event_type = 'automation'
              AND event_code = :event_code
              AND payload_json IS NOT NULL
              AND payload_json LIKE :payload_like
            LIMIT 1
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array(
            ':user_id' => $userId,
            ':cohort_id' => $cohortId,
            ':lesson_id' => $lessonId,
            ':event_code' => $eventCode,
            ':payload_like' => '%' . $dedupeKey . '%',
        ));

        return (bool)$stmt->fetchColumn();
    }

    private function hasAggregatedDispatchAlreadyOccurred(
        int $userId,
        int $cohortId,
        string $triggerEventKey,
        string $dedupeKey
    ): bool {
        $eventCode = $this->getDedupeEventCodeForTrigger($triggerEventKey);

        $sql = "
            SELECT 1
            FROM training_progression_events
            WHERE user_id = :user_id
              AND cohort_id = :cohort_id
              AND lesson_id = 0
              AND event_type = 'automation'
              AND event_code = :event_code
              AND payload_json IS NOT NULL
              AND payload_json LIKE :payload_like
            LIMIT 1
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array(
            ':user_id' => $userId,
            ':cohort_id' => $cohortId,
            ':event_code' => $eventCode,
            ':payload_like' => '%' . $dedupeKey . '%',
        ));

        return (bool)$stmt->fetchColumn();
    }

    private function getDedupeEventCodeForTrigger(string $triggerEventKey): string
    {
        if (!isset(self::TRIGGER_TO_DEDUPE_EVENT_CODE[$triggerEventKey])) {
            throw new RuntimeException('Unsupported trigger event key for dedupe: ' . $triggerEventKey);
        }

        return self::TRIGGER_TO_DEDUPE_EVENT_CODE[$triggerEventKey];
    }

    private function buildAutomationContext(
        string $triggerEventKey,
        string $dedupeKey,
        array $progressionContext,
        array $deadlineState,
        array $attemptState
    ): array {
        $userId = (int)($progressionContext['user_id'] ?? 0);
        $cohortId = (int)($progressionContext['cohort_id'] ?? 0);
        $lessonId = (int)($progressionContext['lesson_id'] ?? 0);

        $studentRecipient = (array)($progressionContext['student_recipient'] ?? array());
        $chiefRecipient = (array)($progressionContext['chief_instructor_recipient'] ?? array());
        $activityState = (array)($progressionContext['activity_state'] ?? array());
        $latestProgressTest = (array)($progressionContext['latest_progress_test'] ?? array());

        $studentName = trim((string)($studentRecipient['name'] ?? ''));
        if ($studentName === '') {
            $studentName = 'Student';
        }

        $chiefInstructorName = trim((string)($chiefRecipient['name'] ?? ''));
        if ($chiefInstructorName === '') {
            $chiefInstructorName = 'Chief Instructor';
        }

        $effectiveDeadlineUtc = trim((string)($deadlineState['effective_deadline_utc'] ?? ''));
        $deadlineTs = $effectiveDeadlineUtc !== '' ? strtotime($effectiveDeadlineUtc) : false;
        $nowTs = time();

        $hoursUntilDeadline = null;
        if ($deadlineTs !== false) {
            $hoursUntilDeadline = round(($deadlineTs - $nowTs) / 3600, 2);
        }

        return array(
            'user_id' => $userId,
            'cohort_id' => $cohortId,
            'lesson_id' => $lessonId,
            'progress_test_id' => isset($latestProgressTest['id']) ? (int)$latestProgressTest['id'] : null,

            'event_key' => $triggerEventKey,
            'dedupe_key' => $dedupeKey,

            'student_name' => $studentName,
            'student_email' => trim((string)($studentRecipient['email'] ?? '')),
            'chief_instructor_name' => $chiefInstructorName,
            'chief_instructor_email' => trim((string)($chiefRecipient['email'] ?? '')),

            'lesson_title' => (string)($progressionContext['lesson_title'] ?? ''),
            'cohort_title' => (string)($progressionContext['cohort_title'] ?? ''),

            'effective_deadline_utc' => $effectiveDeadlineUtc,
            'deadline_source' => (string)($deadlineState['deadline_source'] ?? ''),
            'base_deadline_utc' => (string)($deadlineState['base_deadline_utc'] ?? ''),
            'override_id' => isset($deadlineState['override_id']) ? (int)$deadlineState['override_id'] : null,
            'deadline_passed' => !empty($deadlineState['deadline_passed']) ? 1 : 0,
            'deadline_date_utc' => $deadlineTs !== false ? gmdate('Y-m-d', $deadlineTs) : '',
            'deadline_time_utc' => $deadlineTs !== false ? gmdate('H:i:s', $deadlineTs) : '',
            'hours_until_deadline' => $hoursUntilDeadline,

            'completion_status' => (string)($activityState['completion_status'] ?? ''),
            'summary_status' => (string)($activityState['summary_status'] ?? ''),
            'test_pass_status' => (string)($activityState['test_pass_status'] ?? ''),
            'training_suspended' => !empty($activityState['training_suspended']) ? 1 : 0,
            'one_on_one_required' => !empty($activityState['one_on_one_required']) ? 1 : 0,
            'one_on_one_completed' => !empty($activityState['one_on_one_completed']) ? 1 : 0,

            'attempt_count' => (string)((int)($attemptState['current_attempt_number'] ?? 0)),
            'remaining_attempts' => (string)((int)($attemptState['remaining_attempts'] ?? 0)),
            'effective_allowed_attempts' => (string)((int)($attemptState['effective_allowed_attempts'] ?? 0)),
            'next_attempt_number' => (string)((int)($attemptState['next_attempt_number'] ?? 0)),
        );
    }

    private function logSkippedDuplicate(
        int $userId,
        int $cohortId,
        int $lessonId,
        string $triggerEventKey,
        string $dedupeKey,
        string $effectiveDeadlineUtc
    ): array {
        $this->engine->logProgressionEvent(array(
            'user_id' => $userId,
            'cohort_id' => $cohortId,
            'lesson_id' => $lessonId,
            'progress_test_id' => null,
            'event_type' => 'automation',
            'event_code' => self::EVENT_CODE_SKIPPED_DUPLICATE,
            'event_status' => 'info',
            'actor_type' => 'system',
            'actor_user_id' => null,
            'event_time' => gmdate('Y-m-d H:i:s'),
            'payload' => array(
                'event_key' => $triggerEventKey,
                'dedupe_key' => $dedupeKey,
                'effective_deadline_utc' => $effectiveDeadlineUtc,
            ),
            'legal_note' => 'Time-based progression cron skipped duplicate dispatch.',
        ));

        return array(
            'event_key' => $triggerEventKey,
            'dedupe_key' => $dedupeKey,
            'status' => 'skipped_duplicate',
        );
    }

    private function logAggregatedSkippedDuplicate(
        int $userId,
        int $cohortId,
        string $triggerEventKey,
        string $dedupeKey,
        string $effectiveDeadlineUtc,
        int $affectedLessonsCount
    ): array {
        $this->engine->logProgressionEvent(array(
            'user_id' => $userId,
            'cohort_id' => $cohortId,
            'lesson_id' => 0,
            'progress_test_id' => null,
            'event_type' => 'automation',
            'event_code' => self::EVENT_CODE_SKIPPED_DUPLICATE,
            'event_status' => 'info',
            'actor_type' => 'system',
            'actor_user_id' => null,
            'event_time' => gmdate('Y-m-d H:i:s'),
            'payload' => array(
                'event_key' => $triggerEventKey,
                'dedupe_key' => $dedupeKey,
                'effective_deadline_utc' => $effectiveDeadlineUtc,
                'aggregation_mode' => 'student_cohort_deadline',
                'affected_lessons_count' => $affectedLessonsCount,
            ),
            'legal_note' => 'Time-based progression cron skipped duplicate aggregated reminder dispatch.',
        ));

        return array(
            'event_key' => $triggerEventKey,
            'dedupe_key' => $dedupeKey,
            'status' => 'skipped_duplicate',
            'aggregation_mode' => 'student_cohort_deadline',
            'affected_lessons_count' => $affectedLessonsCount,
        );
    }

    private function logStateMismatch(
        int $userId,
        int $cohortId,
        int $lessonId,
        string $triggerEventKey,
        array $candidateRow,
        array $deadlineState
    ): array {
        $effectiveDeadlineUtc = (string)($deadlineState['effective_deadline_utc'] ?? '');

        $dedupeKey = '';
        if ($effectiveDeadlineUtc !== '') {
            $dedupeKey = $this->buildDedupeKey(
                $triggerEventKey,
                $userId,
                $cohortId,
                $lessonId,
                $effectiveDeadlineUtc
            );
        }

        $this->engine->logProgressionEvent(array(
            'user_id' => $userId,
            'cohort_id' => $cohortId,
            'lesson_id' => $lessonId,
            'progress_test_id' => null,
            'event_type' => 'automation',
            'event_code' => self::EVENT_CODE_SKIPPED_STATE_MISMATCH,
            'event_status' => 'info',
            'actor_type' => 'system',
            'actor_user_id' => null,
            'event_time' => gmdate('Y-m-d H:i:s'),
            'payload' => array(
                'event_key' => $triggerEventKey,
                'dedupe_key' => $dedupeKey,
                'candidate_effective_deadline_utc' => (string)($candidateRow['effective_deadline_utc'] ?? ''),
                'resolved_effective_deadline_utc' => $effectiveDeadlineUtc,
                'resolved_deadline_passed' => !empty($deadlineState['deadline_passed']) ? 1 : 0,
            ),
            'legal_note' => 'Time-based progression cron skipped dispatch because projected candidate state no longer matched engine-resolved state.',
        ));

        return array(
            'event_key' => $triggerEventKey,
            'dedupe_key' => $dedupeKey,
            'status' => 'skipped_state_mismatch',
        );
    }

    private function isAggregatableReminderTrigger(string $triggerEventKey): bool
    {
        return in_array($triggerEventKey, array('deadline_approaching_48h', 'deadline_today'), true);
    }

    private function buildAggregatedBucketKey(
        string $eventKey,
        int $userId,
        int $cohortId,
        string $effectiveDeadlineUtc
    ): string {
        return $eventKey
            . '|'
            . $userId
            . '|'
            . $cohortId
            . '|'
            . $effectiveDeadlineUtc;
    }

    private function buildReminderCandidate(
        string $triggerEventKey,
        array $progressionContext,
        array $deadlineState,
        array $attemptState
    ): array {
        $userId = (int)($progressionContext['user_id'] ?? 0);
        $cohortId = (int)($progressionContext['cohort_id'] ?? 0);
        $lessonId = (int)($progressionContext['lesson_id'] ?? 0);

        $studentRecipient = (array)($progressionContext['student_recipient'] ?? array());
        $chiefRecipient = (array)($progressionContext['chief_instructor_recipient'] ?? array());
        $activityState = (array)($progressionContext['activity_state'] ?? array());
        $latestProgressTest = (array)($progressionContext['latest_progress_test'] ?? array());

        $effectiveDeadlineUtc = trim((string)($deadlineState['effective_deadline_utc'] ?? ''));
        $bucketKey = $this->buildAggregatedBucketKey(
            $triggerEventKey,
            $userId,
            $cohortId,
            $effectiveDeadlineUtc
        );

        return array(
            'bucket_key' => $bucketKey,
            'event_key' => $triggerEventKey,
            'user_id' => $userId,
            'cohort_id' => $cohortId,
            'effective_deadline_utc' => $effectiveDeadlineUtc,
            'deadline_source' => (string)($deadlineState['deadline_source'] ?? ''),
            'base_deadline_utc' => (string)($deadlineState['base_deadline_utc'] ?? ''),
            'override_id' => isset($deadlineState['override_id']) ? (int)$deadlineState['override_id'] : null,
            'student_name' => trim((string)($studentRecipient['name'] ?? '')),
            'student_email' => trim((string)($studentRecipient['email'] ?? '')),
            'chief_instructor_name' => trim((string)($chiefRecipient['name'] ?? '')),
            'chief_instructor_email' => trim((string)($chiefRecipient['email'] ?? '')),
            'cohort_title' => (string)($progressionContext['cohort_title'] ?? ''),
            'lesson' => array(
                'lesson_id' => $lessonId,
                'lesson_title' => (string)($progressionContext['lesson_title'] ?? ''),
                'progress_test_id' => isset($latestProgressTest['id']) ? (int)$latestProgressTest['id'] : null,
                'completion_status' => (string)($activityState['completion_status'] ?? ''),
                'summary_status' => (string)($activityState['summary_status'] ?? ''),
                'test_pass_status' => (string)($activityState['test_pass_status'] ?? ''),
                'training_suspended' => !empty($activityState['training_suspended']) ? 1 : 0,
                'one_on_one_required' => !empty($activityState['one_on_one_required']) ? 1 : 0,
                'one_on_one_completed' => !empty($activityState['one_on_one_completed']) ? 1 : 0,
                'attempt_count' => (int)($attemptState['current_attempt_number'] ?? 0),
                'remaining_attempts' => (int)($attemptState['remaining_attempts'] ?? 0),
                'effective_allowed_attempts' => (int)($attemptState['effective_allowed_attempts'] ?? 0),
                'next_attempt_number' => (int)($attemptState['next_attempt_number'] ?? 0),
            ),
        );
    }

    private function addReminderCandidateToBuckets(array &$buckets, array $candidate): void
    {
        $bucketKey = (string)($candidate['bucket_key'] ?? '');
        if ($bucketKey === '') {
            return;
        }

        if (!isset($buckets[$bucketKey])) {
            $buckets[$bucketKey] = array(
                'bucket_key' => $bucketKey,
                'event_key' => (string)($candidate['event_key'] ?? ''),
                'user_id' => (int)($candidate['user_id'] ?? 0),
                'cohort_id' => (int)($candidate['cohort_id'] ?? 0),
                'effective_deadline_utc' => (string)($candidate['effective_deadline_utc'] ?? ''),
                'deadline_source' => (string)($candidate['deadline_source'] ?? ''),
                'base_deadline_utc' => (string)($candidate['base_deadline_utc'] ?? ''),
                'override_ids' => array(),
                'student_name' => (string)($candidate['student_name'] ?? ''),
                'student_email' => (string)($candidate['student_email'] ?? ''),
                'chief_instructor_name' => (string)($candidate['chief_instructor_name'] ?? ''),
                'chief_instructor_email' => (string)($candidate['chief_instructor_email'] ?? ''),
                'cohort_title' => (string)($candidate['cohort_title'] ?? ''),
                'lessons' => array(),
            );
        }

        if (isset($candidate['override_id']) && $candidate['override_id'] !== null) {
            $buckets[$bucketKey]['override_ids'][(string)$candidate['override_id']] = (int)$candidate['override_id'];
        }

        $lesson = (array)($candidate['lesson'] ?? array());
        $lessonId = (int)($lesson['lesson_id'] ?? 0);
        if ($lessonId > 0) {
            $buckets[$bucketKey]['lessons'][(string)$lessonId] = $lesson;
        }
    }

    private function dispatchAggregatedReminderBucket(array $bucket): array
    {
        $eventKey = (string)($bucket['event_key'] ?? '');
        $userId = (int)($bucket['user_id'] ?? 0);
        $cohortId = (int)($bucket['cohort_id'] ?? 0);
        $effectiveDeadlineUtc = trim((string)($bucket['effective_deadline_utc'] ?? ''));

        $lessons = array_values((array)($bucket['lessons'] ?? array()));
        usort($lessons, array($this, 'sortLessonsById'));

        $affectedLessonsCount = count($lessons);

        if (
            $eventKey === '' ||
            $userId <= 0 ||
            $cohortId <= 0 ||
            $effectiveDeadlineUtc === '' ||
            $affectedLessonsCount === 0
        ) {
            return array(
                'event_key' => $eventKey,
                'user_id' => $userId,
                'cohort_id' => $cohortId,
                'status' => 'error',
                'error' => 'Invalid aggregated reminder bucket.',
            );
        }

        $dedupeKey = $this->buildAggregatedDedupeKey(
            $eventKey,
            $userId,
            $cohortId,
            $effectiveDeadlineUtc
        );

        if ($this->hasAggregatedDispatchAlreadyOccurred($userId, $cohortId, $eventKey, $dedupeKey)) {
            return $this->logAggregatedSkippedDuplicate(
                $userId,
                $cohortId,
                $eventKey,
                $dedupeKey,
                $effectiveDeadlineUtc,
                $affectedLessonsCount
            );
        }

        try {
            $automationContext = $this->buildAggregatedAutomationContext($bucket, $dedupeKey, $lessons);

            $this->engine->logProgressionEvent(array(
                'user_id' => $userId,
                'cohort_id' => $cohortId,
                'lesson_id' => 0,
                'progress_test_id' => null,
                'event_type' => 'automation',
                'event_code' => self::EVENT_CODE_DISPATCH_BEFORE,
                'event_status' => 'info',
                'actor_type' => 'system',
                'actor_user_id' => null,
                'event_time' => gmdate('Y-m-d H:i:s'),
                'payload' => array(
                    'event_key' => $eventKey,
                    'dedupe_key' => $dedupeKey,
                    'aggregation_mode' => 'student_cohort_deadline',
                    'affected_lessons_count' => $affectedLessonsCount,
                    'effective_deadline_utc' => $effectiveDeadlineUtc,
                ),
                'legal_note' => 'Time-based progression cron aggregated reminder dispatch starting.',
            ));

            $automationResult = $this->automation->dispatchEvent($this->pdo, $eventKey, $automationContext);

            $this->engine->logProgressionEvent(array(
                'user_id' => $userId,
                'cohort_id' => $cohortId,
                'lesson_id' => 0,
                'progress_test_id' => null,
                'event_type' => 'automation',
                'event_code' => self::EVENT_CODE_DISPATCH_AFTER,
                'event_status' => 'info',
                'actor_type' => 'system',
                'actor_user_id' => null,
                'event_time' => gmdate('Y-m-d H:i:s'),
                'payload' => array(
                    'event_key' => $eventKey,
                    'dedupe_key' => $dedupeKey,
                    'aggregation_mode' => 'student_cohort_deadline',
                    'affected_lessons_count' => $affectedLessonsCount,
                    'automation_result' => $automationResult,
                ),
                'legal_note' => 'Time-based progression cron aggregated reminder dispatch completed.',
            ));

            $this->engine->logProgressionEvent(array(
                'user_id' => $userId,
                'cohort_id' => $cohortId,
                'lesson_id' => 0,
                'progress_test_id' => null,
                'event_type' => 'automation',
                'event_code' => $this->getDedupeEventCodeForTrigger($eventKey),
                'event_status' => 'info',
                'actor_type' => 'system',
                'actor_user_id' => null,
                'event_time' => gmdate('Y-m-d H:i:s'),
                'payload' => array(
                    'event_key' => $eventKey,
                    'dedupe_key' => $dedupeKey,
                    'aggregation_mode' => 'student_cohort_deadline',
                    'affected_lessons_count' => $affectedLessonsCount,
                    'effective_deadline_utc' => $effectiveDeadlineUtc,
                    'automation_result' => $automationResult,
                ),
                'legal_note' => 'Time-based progression cron aggregated reminder dispatch recorded for dedupe.',
            ));

            return array(
                'event_key' => $eventKey,
                'user_id' => $userId,
                'cohort_id' => $cohortId,
                'dedupe_key' => $dedupeKey,
                'status' => 'dispatched',
                'aggregation_mode' => 'student_cohort_deadline',
                'affected_lessons_count' => $affectedLessonsCount,
                'lesson_ids' => array_map(array($this, 'extractLessonId'), $lessons),
                'automation_result' => $automationResult,
            );
        } catch (Throwable $e) {
            return array(
                'event_key' => $eventKey,
                'user_id' => $userId,
                'cohort_id' => $cohortId,
                'dedupe_key' => $dedupeKey,
                'status' => 'error',
                'aggregation_mode' => 'student_cohort_deadline',
                'affected_lessons_count' => $affectedLessonsCount,
                'error' => $e->getMessage(),
            );
        }
    }

    private function buildAggregatedAutomationContext(array $bucket, string $dedupeKey, array $lessons): array
    {
        $studentName = trim((string)($bucket['student_name'] ?? ''));
        if ($studentName === '') {
            $studentName = 'Student';
        }

        $chiefInstructorName = trim((string)($bucket['chief_instructor_name'] ?? ''));
        if ($chiefInstructorName === '') {
            $chiefInstructorName = 'Chief Instructor';
        }

        $effectiveDeadlineUtc = trim((string)($bucket['effective_deadline_utc'] ?? ''));
        $deadlineTs = $effectiveDeadlineUtc !== '' ? strtotime($effectiveDeadlineUtc) : false;
        $nowTs = time();

        $hoursUntilDeadline = null;
        if ($deadlineTs !== false) {
            $hoursUntilDeadline = round(($deadlineTs - $nowTs) / 3600, 2);
        }

        $affectedLessonsCount = count($lessons);

        $lessonTitles = array();
        $affectedLessonsTextLines = array();
        $affectedLessonsHtmlItems = array();
        $summaryMissingCount = 0;
        $testInProgressCount = 0;
        $failedCount = 0;

        foreach ($lessons as $lesson) {
            $lessonId = (int)($lesson['lesson_id'] ?? 0);
            $lessonTitle = trim((string)($lesson['lesson_title'] ?? ('Lesson ' . $lessonId)));
            $summaryStatus = trim((string)($lesson['summary_status'] ?? ''));
            $testPassStatus = trim((string)($lesson['test_pass_status'] ?? ''));
            $completionStatus = trim((string)($lesson['completion_status'] ?? ''));

            $lessonTitles[] = $lessonTitle;

            if ($summaryStatus === 'missing') {
                $summaryMissingCount++;
            }

            if ($testPassStatus === 'in_progress') {
                $testInProgressCount++;
            }

            if ($testPassStatus === 'failed' || $testPassStatus === 'deadline_missed') {
                $failedCount++;
            }

            $affectedLessonsTextLines[] =
                '- ' . $lessonTitle
                . ' | Summary: ' . $summaryStatus
                . ' | Test: ' . $testPassStatus
                . ' | Completion: ' . $completionStatus;

            $affectedLessonsHtmlItems[] =
                '<li><strong>' . htmlspecialchars($lessonTitle, ENT_QUOTES, 'UTF-8') . '</strong>'
                . ' — Summary: ' . htmlspecialchars($summaryStatus, ENT_QUOTES, 'UTF-8')
                . ', Test: ' . htmlspecialchars($testPassStatus, ENT_QUOTES, 'UTF-8')
                . ', Completion: ' . htmlspecialchars($completionStatus, ENT_QUOTES, 'UTF-8')
                . '</li>';
        }

        $affectedLessonsText = implode("\n", $affectedLessonsTextLines);
        $affectedLessonsHtml = '<ul style="margin:0;padding-left:20px;">' . implode('', $affectedLessonsHtmlItems) . '</ul>';
        $affectedLessonsJson = json_encode($lessons, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $lessonTitleForLegacyTemplates = $affectedLessonsCount === 1
            ? (string)$lessonTitles[0]
            : ((string)$affectedLessonsCount . ' lessons');

        return array(
            'user_id' => (int)($bucket['user_id'] ?? 0),
            'cohort_id' => (int)($bucket['cohort_id'] ?? 0),
            'lesson_id' => 0,
            'progress_test_id' => null,

            'event_key' => (string)($bucket['event_key'] ?? ''),
            'dedupe_key' => $dedupeKey,

            'student_name' => $studentName,
            'student_email' => trim((string)($bucket['student_email'] ?? '')),
            'chief_instructor_name' => $chiefInstructorName,
            'chief_instructor_email' => trim((string)($bucket['chief_instructor_email'] ?? '')),

            'lesson_title' => $lessonTitleForLegacyTemplates,
            'cohort_title' => (string)($bucket['cohort_title'] ?? ''),

            'effective_deadline_utc' => $effectiveDeadlineUtc,
            'deadline_source' => (string)($bucket['deadline_source'] ?? ''),
            'base_deadline_utc' => (string)($bucket['base_deadline_utc'] ?? ''),
            'override_ids_json' => json_encode(array_values((array)($bucket['override_ids'] ?? array())), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'deadline_passed' => 0,
            'deadline_date_utc' => $deadlineTs !== false ? gmdate('Y-m-d', $deadlineTs) : '',
            'deadline_time_utc' => $deadlineTs !== false ? gmdate('H:i:s', $deadlineTs) : '',
            'hours_until_deadline' => $hoursUntilDeadline,

            'affected_lessons_count' => $affectedLessonsCount,
            'affected_lessons_text' => $affectedLessonsText,
            'affected_lessons_html' => $affectedLessonsHtml,
            'affected_lessons_json' => $affectedLessonsJson,

            'summary_missing_count' => $summaryMissingCount,
            'test_in_progress_count' => $testInProgressCount,
            'failed_count' => $failedCount,

            'completion_status' => $affectedLessonsCount === 1 ? (string)($lessons[0]['completion_status'] ?? '') : 'multiple_lessons',
            'summary_status' => $affectedLessonsCount === 1 ? (string)($lessons[0]['summary_status'] ?? '') : 'multiple_lessons',
            'test_pass_status' => $affectedLessonsCount === 1 ? (string)($lessons[0]['test_pass_status'] ?? '') : 'multiple_lessons',
            'training_suspended' => 0,
            'one_on_one_required' => 0,
            'one_on_one_completed' => 0,

            'attempt_count' => $affectedLessonsCount === 1 ? (string)((int)($lessons[0]['attempt_count'] ?? 0)) : '',
            'remaining_attempts' => $affectedLessonsCount === 1 ? (string)((int)($lessons[0]['remaining_attempts'] ?? 0)) : '',
            'effective_allowed_attempts' => $affectedLessonsCount === 1 ? (string)((int)($lessons[0]['effective_allowed_attempts'] ?? 0)) : '',
            'next_attempt_number' => $affectedLessonsCount === 1 ? (string)((int)($lessons[0]['next_attempt_number'] ?? 0)) : '',
        );
    }

    private function sortLessonsById(array $a, array $b): int
    {
        return ((int)($a['lesson_id'] ?? 0)) <=> ((int)($b['lesson_id'] ?? 0));
    }

    private function extractLessonId(array $lesson): int
    {
        return (int)($lesson['lesson_id'] ?? 0);
    }
}