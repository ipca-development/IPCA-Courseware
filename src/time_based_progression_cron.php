<?php
declare(strict_types=1);

require_once __DIR__ . '/courseware_progression_v2.php';
require_once __DIR__ . '/automation_runtime.php';

final class TimeBasedProgressionCron
{
    private const LOCK_NAME = 'ipca_time_based_progression_cron';
    private const LOCK_TIMEOUT_SECONDS = 1;
    private const APPROACHING_WINDOW_HOURS = 48;

    private const EVENT_CODE_DISPATCH_BEFORE = 'cron_dispatch_before';
    private const EVENT_CODE_DISPATCH_AFTER = 'cron_dispatch_after';
    private const EVENT_CODE_SKIPPED_DUPLICATE = 'cron_dispatch_skipped_duplicate';
    private const EVENT_CODE_SKIPPED_STATE_MISMATCH = 'cron_dispatch_skipped_state_mismatch';

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
            'errors' => array(),
            'results' => array(),
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

            $candidates = $this->loadCandidateRows();
            $summary['candidates_scanned'] = count($candidates);

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

                $summary['results'][] = $candidateResult;
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

    private function loadCandidateRows(): array
    {
        $sql = "
            SELECT
                la.user_id,
                la.cohort_id,
                la.lesson_id,
                la.completion_status,
                la.training_suspended,
                la.effective_deadline_utc,
                la.last_state_eval_at,
                la.updated_at
            FROM lesson_activity la
            WHERE la.completion_status <> 'completed'
              AND la.effective_deadline_utc IS NOT NULL
              AND COALESCE(la.training_suspended, 0) = 0
              AND la.effective_deadline_utc <= DATE_ADD(UTC_TIMESTAMP(), INTERVAL :window_hours HOUR)
            ORDER BY la.effective_deadline_utc ASC, la.user_id ASC, la.cohort_id ASC, la.lesson_id ASC
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
            'errors' => array(),
        );

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

    $dedupeKey = $this->buildDedupeKey(
        $triggerEventKey,
        $userId,
        $cohortId,
        $lessonId,
        $effectiveDeadlineUtc
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

    if ($triggerEventKey === 'deadline_passed') {
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
                'effective_deadline_utc' => $effectiveDeadlineUtc,
                'engine_result' => $engineResult,
            ),
            'legal_note' => 'Time-based progression cron completed canonical missed-deadline handling through CoursewareProgressionV2.',
        ));

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
                'effective_deadline_utc' => $effectiveDeadlineUtc,
                'engine_handled' => 1,
            ),
            'legal_note' => 'Time-based progression cron recorded missed-deadline handling for dedupe after canonical engine processing.',
        ));

        $result['dispatch_successes']++;
        $result['trigger_results'][] = array(
            'event_key' => $triggerEventKey,
            'dedupe_key' => $dedupeKey,
            'status' => 'engine_handled',
            'engine_result' => $engineResult,
        );

        continue;
    }

    $automationContext = $this->buildAutomationContext(
        $triggerEventKey,
        $dedupeKey,
        $progressionContext,
        $deadlineState,
        $attemptState
    );

    $this->engine->logProgressionEvent(array(
        'user_id' => $userId,
        'cohort_id' => $cohortId,
        'lesson_id' => $lessonId,
        'progress_test_id' => isset($automationContext['progress_test_id']) ? (int)$automationContext['progress_test_id'] : null,
        'event_type' => 'automation',
        'event_code' => self::EVENT_CODE_DISPATCH_BEFORE,
        'event_status' => 'info',
        'actor_type' => 'system',
        'actor_user_id' => null,
        'event_time' => gmdate('Y-m-d H:i:s'),
        'payload' => array(
            'event_key' => $triggerEventKey,
            'dedupe_key' => $dedupeKey,
            'candidate' => array(
                'user_id' => $userId,
                'cohort_id' => $cohortId,
                'lesson_id' => $lessonId,
            ),
            'effective_deadline_utc' => $effectiveDeadlineUtc,
        ),
        'legal_note' => 'Time-based progression cron dispatch starting.',
    ));

    $automationResult = $this->automation->dispatchEvent($this->pdo, $triggerEventKey, $automationContext);

    $this->engine->logProgressionEvent(array(
        'user_id' => $userId,
        'cohort_id' => $cohortId,
        'lesson_id' => $lessonId,
        'progress_test_id' => isset($automationContext['progress_test_id']) ? (int)$automationContext['progress_test_id'] : null,
        'event_type' => 'automation',
        'event_code' => self::EVENT_CODE_DISPATCH_AFTER,
        'event_status' => 'info',
        'actor_type' => 'system',
        'actor_user_id' => null,
        'event_time' => gmdate('Y-m-d H:i:s'),
        'payload' => array(
            'event_key' => $triggerEventKey,
            'dedupe_key' => $dedupeKey,
            'automation_result' => $automationResult,
        ),
        'legal_note' => 'Time-based progression cron dispatch completed.',
    ));

    $this->engine->logProgressionEvent(array(
        'user_id' => $userId,
        'cohort_id' => $cohortId,
        'lesson_id' => $lessonId,
        'progress_test_id' => isset($automationContext['progress_test_id']) ? (int)$automationContext['progress_test_id'] : null,
        'event_type' => 'automation',
        'event_code' => $this->getDedupeEventCodeForTrigger($triggerEventKey),
        'event_status' => 'info',
        'actor_type' => 'system',
        'actor_user_id' => null,
        'event_time' => gmdate('Y-m-d H:i:s'),
        'payload' => array(
            'event_key' => $triggerEventKey,
            'dedupe_key' => $dedupeKey,
            'effective_deadline_utc' => $effectiveDeadlineUtc,
            'automation_result' => $automationResult,
        ),
        'legal_note' => 'Time-based progression cron dispatch recorded for dedupe.',
    ));

    $result['dispatch_successes']++;
    $result['trigger_results'][] = array(
        'event_key' => $triggerEventKey,
        'dedupe_key' => $dedupeKey,
        'status' => 'dispatched',
        'automation_result' => $automationResult,
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

        $completionStatus = trim((string)($activityState['completion_status'] ?? ''));
        if ($completionStatus === 'completed') {
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
            array(
                'completion_status' => (string)($candidateRow['completion_status'] ?? ''),
            ),
            $projectedState
        );
    }

    private function buildDedupeKey(
        string $eventKey,
        int $userId,
        int $cohortId,
        int $lessonId,
        string $effectiveDeadlineUtc
    ): string {
        return $eventKey
            . '|'
            . $userId
            . '|'
            . $cohortId
            . '|'
            . $lessonId
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
}