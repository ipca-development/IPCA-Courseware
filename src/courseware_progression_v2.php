<?php
declare(strict_types=1);

/**
 * Courseware Progression Engine V2
 *
 * Foundation layer:
 * - policy loading
 * - effective deadline resolution
 * - required action logic
 * - event logging
 * - notification template rendering
 * - real progression email queue record creation
 * - queued email sending
 * - preview / test-send support for admin notification control panel
 *
 * Requirements:
 * - PHP 8.2+
 * - MySQL 8+
 * - PDO
 */
final class CoursewareProgressionV2
{
    public const LOGIC_VERSION = 'v2.0';
    public const NOTIFICATION_CHANNEL_EMAIL = 'email';

    public function __construct(
        private readonly PDO $pdo
    ) {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    }

    /**
     * Resolve one effective policy value.
     *
     * Scope precedence:
     * 1. cohort
     * 2. course
     * 3. global
     * 4. default from definition
     */
    public function getPolicy(string $policyKey, array $scope = []): mixed
    {
        $definition = $this->getPolicyDefinition($policyKey);

        if ($definition === null) {
            throw new RuntimeException("Unknown policy key: {$policyKey}");
        }

        $valueText = $this->resolvePolicyValueText($policyKey, $scope);
        if ($valueText === null) {
            $valueText = (string)$definition['default_value_text'];
        }

        return $this->castPolicyValue(
            (string)$definition['value_type'],
            $valueText
        );
    }

    /**
     * Return all effective policies for the given scope.
     */
    public function getAllPolicies(array $scope = []): array
    {
        $sql = "
            SELECT
                policy_key,
                value_type,
                default_value_text
            FROM system_policy_definitions
            ORDER BY sort_order ASC, policy_key ASC
        ";

        $stmt = $this->pdo->query($sql);
        $definitions = $stmt->fetchAll();

        $result = [];

        foreach ($definitions as $definition) {
            $policyKey = (string)$definition['policy_key'];
            $valueText = $this->resolvePolicyValueText($policyKey, $scope);

            if ($valueText === null) {
                $valueText = (string)$definition['default_value_text'];
            }

            $result[$policyKey] = $this->castPolicyValue(
                (string)$definition['value_type'],
                $valueText
            );
        }

        return $result;
    }

    /**
     * Return the current effective deadline for a student/lesson.
     *
     * Return format:
     * [
     *   'effective_deadline_utc' => '2026-03-10 00:00:00',
     *   'deadline_source' => 'cohort_default'|'student_extension_1'|'student_extension_2_final'|'manual_override',
     *   'base_deadline_utc' => '2026-03-08 00:00:00',
     *   'override_id' => 123|null
     * ]
     */
    public function getEffectiveDeadline(int $userId, int $cohortId, int $lessonId): array
    {
        $baseSql = "
            SELECT deadline_utc
            FROM cohort_lesson_deadlines
            WHERE cohort_id = :cohort_id
              AND lesson_id = :lesson_id
            LIMIT 1
        ";

        $baseStmt = $this->pdo->prepare($baseSql);
        $baseStmt->execute([
            ':cohort_id' => $cohortId,
            ':lesson_id' => $lessonId,
        ]);

        $baseRow = $baseStmt->fetch();

        if (!$baseRow) {
            throw new RuntimeException(
                "No base deadline found for cohort_id={$cohortId}, lesson_id={$lessonId}"
            );
        }

        $baseDeadlineUtc = (string)$baseRow['deadline_utc'];

        $overrideSql = "
            SELECT
                id,
                override_type,
                new_deadline_utc
            FROM student_lesson_deadline_overrides
            WHERE user_id = :user_id
              AND cohort_id = :cohort_id
              AND lesson_id = :lesson_id
              AND is_active = 1
            ORDER BY new_deadline_utc DESC, id DESC
            LIMIT 1
        ";

        $overrideStmt = $this->pdo->prepare($overrideSql);
        $overrideStmt->execute([
            ':user_id' => $userId,
            ':cohort_id' => $cohortId,
            ':lesson_id' => $lessonId,
        ]);

        $overrideRow = $overrideStmt->fetch();

        if (!$overrideRow) {
            return [
                'effective_deadline_utc' => $baseDeadlineUtc,
                'deadline_source' => 'cohort_default',
                'base_deadline_utc' => $baseDeadlineUtc,
                'override_id' => null,
            ];
        }

        $deadlineSource = match ((string)$overrideRow['override_type']) {
            'extension_1' => 'student_extension_1',
            'extension_2_final' => 'student_extension_2_final',
            'manual_override' => 'manual_override',
            default => 'manual_override',
        };

        return [
            'effective_deadline_utc' => (string)$overrideRow['new_deadline_utc'],
            'deadline_source' => $deadlineSource,
            'base_deadline_utc' => $baseDeadlineUtc,
            'override_id' => (int)$overrideRow['id'],
        ];
    }

    /**
     * Return the chief instructor user id from policy.
     */
    public function getChiefInstructorUserId(array $scope = []): int
    {
        return (int)$this->getPolicy('chief_instructor_user_id', $scope);
    }

    /**
     * Resolve one user's email/name for mail delivery.
     * Returns null if no usable email is found.
     */
    public function getUserRecipient(int $userId): ?array
    {
        $sql = "
            SELECT id, email, name
            FROM users
            WHERE id = :id
            LIMIT 1
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':id' => $userId,
        ]);

        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }

        $email = trim((string)($row['email'] ?? ''));
        if ($email === '') {
            return null;
        }

        return [
            'email' => $email,
            'name' => trim((string)($row['name'] ?? '')),
            'user_id' => (int)$row['id'],
        ];
    }

    /**
     * Resolve the chief instructor recipient from policy.
     */
    public function getChiefInstructorRecipient(array $scope = []): ?array
    {
        $chiefInstructorUserId = $this->getChiefInstructorUserId($scope);
        if ($chiefInstructorUserId <= 0) {
            return null;
        }

        return $this->getUserRecipient($chiefInstructorUserId);
    }

    public function getLessonTitle(int $lessonId): string
    {
        $sql = "
            SELECT title
            FROM lessons
            WHERE id = :id
            LIMIT 1
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':id' => $lessonId,
        ]);

        $title = $stmt->fetchColumn();
        return is_string($title) && trim($title) !== ''
            ? trim($title)
            : ('Lesson ' . $lessonId);
    }

    public function getCohortTitle(int $cohortId): string
    {
        $sql = "
            SELECT name
            FROM cohorts
            WHERE id = :id
            LIMIT 1
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':id' => $cohortId,
        ]);

        $title = $stmt->fetchColumn();
        return is_string($title) && trim($title) !== ''
            ? trim($title)
            : ('Cohort ' . $cohortId);
    }

    public function resolveEffectivePolicySet(int $cohortId, ?int $courseId = null): array
    {
        $scope = [
            'cohort_id' => $cohortId,
        ];

        if ($courseId !== null && $courseId > 0) {
            $scope['course_id'] = $courseId;
        }

        return $this->getAllPolicies($scope);
    }

    public function resolveBehaviorMode(array $policy): array
    {
        $fallbackMapUsed = [];

        if (
            !$this->policyKeyExistsInArray($policy, 'auto_extra_attempts_after_remediation')
            && $this->policyKeyExistsInArray($policy, 'extra_attempts_after_threshold_fail')
        ) {
            $fallbackMapUsed['auto_extra_attempts_after_remediation'] = 'extra_attempts_after_threshold_fail';
        }

        if (
            !$this->policyKeyExistsInArray($policy, 'remediation_trigger_attempt')
            && $this->policyKeyExistsInArray($policy, 'threshold_attempt_for_remediation_email')
        ) {
            $fallbackMapUsed['remediation_trigger_attempt'] = 'threshold_attempt_for_remediation_email';
        }

        if (
            !$this->policyKeyExistsInArray($policy, 'instructor_escalation_attempt')
            && $this->policyKeyExistsInArray($policy, 'max_total_attempts_without_admin_override')
        ) {
            $fallbackMapUsed['instructor_escalation_attempt'] = 'max_total_attempts_without_admin_override';
        }

        return [
            'mode' => empty($fallbackMapUsed) ? 'target_policy_driven' : 'live_compatible',
            'target_policy_driven' => empty($fallbackMapUsed),
            'live_compatible' => !empty($fallbackMapUsed),
            'fallback_map_used' => $fallbackMapUsed,
        ];
    }

    public function resolveAttemptPolicyState(
        int $userId,
        int $cohortId,
        int $lessonId,
        array $policy,
        ?int $currentAttemptNumber = null,
        ?array $behaviorMode = null
    ): array {
        $behaviorMode = $behaviorMode ?? $this->resolveBehaviorMode($policy);

        $initialAttemptLimit = (int)$this->resolveProgressionPolicyValue(
            $policy,
            'initial_attempt_limit',
            [],
            3
        );

        $autoExtraAttemptsAfterRemediation = (int)$this->resolveProgressionPolicyValue(
            $policy,
            'auto_extra_attempts_after_remediation',
            ['extra_attempts_after_threshold_fail'],
            2
        );

        $maxTotalAttemptsWithoutAdminOverride = (int)$this->resolveProgressionPolicyValue(
            $policy,
            'max_total_attempts_without_admin_override',
            [],
            5
        );

        $remediationTriggerAttempt = (int)$this->resolveProgressionPolicyValue(
            $policy,
            'remediation_trigger_attempt',
            ['threshold_attempt_for_remediation_email'],
            3
        );

        $instructorEscalationAttempt = (int)$this->resolveProgressionPolicyValue(
            $policy,
            'instructor_escalation_attempt',
            ['max_total_attempts_without_admin_override'],
            $maxTotalAttemptsWithoutAdminOverride
        );

        if ($initialAttemptLimit <= 0) {
            $initialAttemptLimit = 3;
        }
        if ($autoExtraAttemptsAfterRemediation < 0) {
            $autoExtraAttemptsAfterRemediation = 0;
        }
        if ($maxTotalAttemptsWithoutAdminOverride <= 0) {
            $maxTotalAttemptsWithoutAdminOverride = 5;
        }
        if ($remediationTriggerAttempt <= 0) {
            $remediationTriggerAttempt = $initialAttemptLimit;
        }
        if ($instructorEscalationAttempt <= 0) {
            $instructorEscalationAttempt = $maxTotalAttemptsWithoutAdminOverride;
        }

        $latestAttemptNumber = $this->getLatestAttemptNumber($userId, $cohortId, $lessonId);

        if ($currentAttemptNumber === null) {
            $currentAttemptNumber = $latestAttemptNumber;
        }

        if ($currentAttemptNumber < 0) {
            $currentAttemptNumber = 0;
        }

        $nextAttemptNumber = $latestAttemptNumber + 1;

        $completedRemediation = $this->getLatestCompletedRequiredAction(
            $userId,
            $cohortId,
            $lessonId,
            'remediation_acknowledgement'
        );

        $activity = $this->getLessonActivityProjectionRow($userId, $cohortId, $lessonId) ?: [];
        $instructorGrantedExtraAttempts = max(0, (int)($activity['granted_extra_attempts'] ?? 0));

        $effectiveAllowedAttempts = min($initialAttemptLimit, $maxTotalAttemptsWithoutAdminOverride);

        if ($completedRemediation !== null) {
            $effectiveAllowedAttempts = min(
                $initialAttemptLimit + $autoExtraAttemptsAfterRemediation,
                $maxTotalAttemptsWithoutAdminOverride
            );
        }

        $effectiveAllowedAttempts += $instructorGrantedExtraAttempts;

        $remainingAttempts = max(0, $effectiveAllowedAttempts - $currentAttemptNumber);

        return [
            'initial_attempt_limit' => $initialAttemptLimit,
            'auto_extra_attempts_after_remediation' => $autoExtraAttemptsAfterRemediation,
            'max_total_attempts_without_admin_override' => $maxTotalAttemptsWithoutAdminOverride,
            'remediation_trigger_attempt' => $remediationTriggerAttempt,
            'instructor_escalation_attempt' => $instructorEscalationAttempt,
            'latest_attempt_number' => $latestAttemptNumber,
            'current_attempt_number' => $currentAttemptNumber,
            'next_attempt_number' => $nextAttemptNumber,
            'remediation_completed' => $completedRemediation !== null,
            'instructor_granted_extra_attempts' => $instructorGrantedExtraAttempts,
            'effective_allowed_attempts' => $effectiveAllowedAttempts,
            'remaining_attempts' => $remainingAttempts,
            'behavior_mode' => $behaviorMode,
        ];
    }

    public function resolveDeadlineState(int $userId, int $cohortId, int $lessonId): array
    {
        $deadlineMeta = $this->getEffectiveDeadline($userId, $cohortId, $lessonId);
        $nowUtc = gmdate('Y-m-d H:i:s');

        return [
            'effective_deadline_utc' => (string)$deadlineMeta['effective_deadline_utc'],
            'deadline_source' => (string)$deadlineMeta['deadline_source'],
            'base_deadline_utc' => (string)$deadlineMeta['base_deadline_utc'],
            'override_id' => $deadlineMeta['override_id'],
            'deadline_passed' => (strtotime($nowUtc) > strtotime((string)$deadlineMeta['effective_deadline_utc'])),
        ];
    }

    public function classifyProgressTestResult(array $testRow, int $scorePct, array $policy, ?array $behaviorMode = null): array
    {
        $behaviorMode = $behaviorMode ?? $this->resolveBehaviorMode($policy);

        $passPct = (int)$this->resolveProgressionPolicyValue(
            $policy,
            'progress_test_pass_pct',
            [],
            75
        );

        $completedAt = trim((string)($testRow['completed_at'] ?? ''));
        $effectiveDeadlineUtc = trim((string)($testRow['effective_deadline_utc'] ?? ''));
        $attempt = max(1, (int)($testRow['attempt'] ?? 1));

        $timingStatus = 'unknown';
        if ($completedAt !== '' && $effectiveDeadlineUtc !== '') {
            $timingStatus = (strtotime($completedAt) <= strtotime($effectiveDeadlineUtc))
                ? 'on_time'
                : 'late';
        }

        $passGateMet = ($scorePct >= $passPct && $timingStatus === 'on_time') ? 1 : 0;
        $countsAsUnsat = $passGateMet ? 0 : 1;

        return [
            'attempt' => $attempt,
            'score_pct' => $scorePct,
            'pass_pct' => $passPct,
            'timing_status' => $timingStatus,
            'pass_gate_met' => $passGateMet,
            'counts_as_unsat' => $countsAsUnsat,
            'formal_result_code' => $passGateMet ? 'PASS' : 'UNSAT',
            'formal_result_label' => $passGateMet ? 'Pass' : 'Unsatisfactory',
            'behavior_mode' => $behaviorMode,
        ];
    }

    public function prepareStartDecision(int $userId, int $cohortId, int $lessonId, array $scope = []): array
    {
        $courseId = isset($scope['course_id']) ? (int)$scope['course_id'] : null;

        $policy = $this->resolveEffectivePolicySet($cohortId, $courseId);
        $behaviorMode = $this->resolveBehaviorMode($policy);
        $summaryState = $this->resolveSummaryState($userId, $cohortId, $lessonId, $policy);
        $attemptState = $this->resolveAttemptPolicyState($userId, $cohortId, $lessonId, $policy, null, $behaviorMode);
        $deadlineState = $this->resolveDeadlineState($userId, $cohortId, $lessonId);
        $progressionContext = $this->getProgressionContextForUserLesson($userId, $cohortId, $lessonId);

        $decision = $this->evaluateProgressionDecision([
            'phase' => 'prepare_start',
            'user_id' => $userId,
            'cohort_id' => $cohortId,
            'lesson_id' => $lessonId,
            'activity_state' => $progressionContext['activity_state'],
            'summary_state' => $summaryState,
            'attempt_state' => $attemptState,
            'deadline_state' => $deadlineState,
            'classification' => [],
        ]);

        $requiredActions = [
            'should_create_any' => false,
            'actions' => [],
            'latest_instructor_action_id' => null,
        ];

        $notificationDecision = $this->buildNotificationDecision($progressionContext, $decision, [
            'behavior_mode' => $behaviorMode,
            'required_actions' => $requiredActions,
            'phase' => 'prepare_start',
        ]);

        $lessonActivityProjection = $this->computeLessonActivityProjection([
            'phase' => 'prepare_start',
            'user_id' => $userId,
            'cohort_id' => $cohortId,
            'lesson_id' => $lessonId,
            'summary_state' => $summaryState,
            'attempt_state' => $attemptState,
            'deadline_state' => $deadlineState,
            'classification' => [],
            'activity_state' => $progressionContext['activity_state'],
            'required_actions' => $requiredActions,
        ], $decision);

        return [
            'allowed' => !empty($decision['allowed']),
            'decision' => $decision,
            'summary_state' => $summaryState,
            'attempt_state' => $attemptState,
            'deadline_state' => $deadlineState,
            'required_actions' => $requiredActions,
            'notification_decision' => $notificationDecision,
            'lesson_activity_projection' => $lessonActivityProjection,
        ];
    }

    public function finalizeProgressionDecision(int $progressTestId, array $finalizeData = []): array
    {
        if ($this->pdo->inTransaction()) {
            throw new RuntimeException('finalizeProgressionDecision requires transaction ownership and cannot run inside an outer transaction.');
        }

        $testRow = $this->getProgressTestRowById($progressTestId);
        if (!$testRow) {
            throw new RuntimeException('Progress test not found.');
        }

        $userId = (int)$testRow['user_id'];
        $cohortId = (int)$testRow['cohort_id'];
        $lessonId = (int)$testRow['lesson_id'];

        $policy = $this->resolveEffectivePolicySet($cohortId);
        $behaviorMode = $this->resolveBehaviorMode($policy);

        $scorePct = (int)($finalizeData['score_pct'] ?? (int)($testRow['score_pct'] ?? 0));
        $completedAt = (string)($finalizeData['completed_at'] ?? gmdate('Y-m-d H:i:s'));

        $testForClassification = $testRow;
        $testForClassification['completed_at'] = $completedAt;

        $summaryState = $this->resolveSummaryState($userId, $cohortId, $lessonId, $policy);
        $attemptState = $this->resolveAttemptPolicyState(
            $userId,
            $cohortId,
            $lessonId,
            $policy,
            (int)($testRow['attempt'] ?? 1),
            $behaviorMode
        );
        $deadlineState = $this->resolveDeadlineState($userId, $cohortId, $lessonId);
        $classification = $this->classifyProgressTestResult($testForClassification, $scorePct, $policy, $behaviorMode);
        $progressionContext = $this->getProgressionContextForUserLesson($userId, $cohortId, $lessonId);

        $decision = $this->evaluateProgressionDecision([
            'phase' => 'finalize',
            'user_id' => $userId,
            'cohort_id' => $cohortId,
            'lesson_id' => $lessonId,
            'activity_state' => $progressionContext['activity_state'],
            'summary_state' => $summaryState,
            'attempt_state' => $attemptState,
            'deadline_state' => $deadlineState,
            'classification' => $classification,
        ]);

        $this->pdo->beginTransaction();

        try {
            $requiredActions = $this->ensureRequiredActionsForDecision($progressTestId, $decision);

            $notificationDecision = $this->buildNotificationDecision($progressionContext, $decision, [
                'behavior_mode' => $behaviorMode,
                'required_actions' => $requiredActions,
                'phase' => 'finalize',
            ]);

            $projection = $this->computeLessonActivityProjection([
                'phase' => 'finalize',
                'user_id' => $userId,
                'cohort_id' => $cohortId,
                'lesson_id' => $lessonId,
                'summary_state' => $summaryState,
                'attempt_state' => $attemptState,
                'deadline_state' => $deadlineState,
                'classification' => $classification,
                'activity_state' => $progressionContext['activity_state'],
                'required_actions' => $requiredActions,
                'completed_at' => $completedAt,
            ], $decision);

            $persistResult = $this->persistProgressionConsequences(
                $progressTestId,
                $classification,
                $decision,
                $requiredActions,
                $projection,
                ['completed_at' => $completedAt]
            );

            $this->logProgressionEvent([
                'user_id' => $userId,
                'cohort_id' => $cohortId,
                'lesson_id' => $lessonId,
                'progress_test_id' => $progressTestId,
                'event_type' => 'finalization',
                'event_code' => 'progression_decision_finalized',
                'event_status' => 'info',
                'actor_type' => 'system',
                'actor_user_id' => null,
                'event_time' => $completedAt,
                'payload' => [
                    'classification' => $classification,
                    'decision' => $decision,
                    'required_actions_count' => count((array)($requiredActions['actions'] ?? [])),
                    'notification_count' => count((array)($notificationDecision['notifications'] ?? [])),
                ],
                'legal_note' => 'Central progression finalization decision persisted by CoursewareProgressionV2.',
            ]);

            $this->pdo->commit();

            return [
                'ok' => true,
                'progress_test_id' => $progressTestId,
                'classification' => $classification,
                'decision' => $decision,
                'summary_state' => $summaryState,
                'attempt_state' => $attemptState,
                'deadline_state' => $deadlineState,
                'required_actions' => $requiredActions,
                'notification_decision' => $notificationDecision,
                'lesson_activity_projection' => $projection,
                'persist_result' => $persistResult,
            ];
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

	
	
public function finalizeAssessedProgressTest(int $progressTestId, array $assessment): array
{
    if ($this->pdo->inTransaction()) {
        throw new RuntimeException('finalizeAssessedProgressTest must own its transaction.');
    }

    $testRow = $this->getProgressTestRowById($progressTestId);
    if (!$testRow) {
        throw new RuntimeException('Progress test not found.');
    }

    $userId   = (int)$testRow['user_id'];
    $cohortId = (int)$testRow['cohort_id'];
    $lessonId = (int)$testRow['lesson_id'];

    $scorePct      = (int)($assessment['score_pct'] ?? 0);
    $completedAt   = (string)($assessment['completed_at'] ?? gmdate('Y-m-d H:i:s'));
    $aiSummary     = (string)($assessment['ai_summary'] ?? '');
    $weakAreas     = (string)($assessment['weak_areas'] ?? '');
    $spoken        = (string)($assessment['debrief_spoken'] ?? '');
    $summaryQual   = (string)($assessment['summary_quality'] ?? '');
    $summaryIssues = (string)($assessment['summary_issues'] ?? '');
    $summaryCorr   = (string)($assessment['summary_corrections'] ?? '');
    $misunder      = (string)($assessment['confirmed_misunderstandings'] ?? '');

    $policy       = $this->resolveEffectivePolicySet($cohortId);
    $behaviorMode = $this->resolveBehaviorMode($policy);

    $testForClassification = $testRow;
    $testForClassification['completed_at'] = $completedAt;

    $summaryState = $this->resolveSummaryState($userId, $cohortId, $lessonId, $policy);
    $attemptState = $this->resolveAttemptPolicyState(
        $userId,
        $cohortId,
        $lessonId,
        $policy,
        (int)($testRow['attempt'] ?? 1),
        $behaviorMode
    );
    $deadlineState = $this->resolveDeadlineState($userId, $cohortId, $lessonId);

    $classification = $this->classifyProgressTestResult(
        $testForClassification,
        $scorePct,
        $policy,
        $behaviorMode
    );

    $context = $this->getProgressionContextForUserLesson($userId, $cohortId, $lessonId);

    $decision = $this->evaluateProgressionDecision([
        'phase'          => 'finalize',
        'user_id'        => $userId,
        'cohort_id'      => $cohortId,
        'lesson_id'      => $lessonId,
        'activity_state' => $context['activity_state'],
        'summary_state'  => $summaryState,
        'attempt_state'  => $attemptState,
        'deadline_state' => $deadlineState,
        'classification' => $classification,
    ]);

    $automationEventContext = [];

    $this->pdo->beginTransaction();

    try {
        $stmt = $this->pdo->prepare("
            UPDATE progress_tests_v2
            SET
                status = 'completed',
                score_pct = :score_pct,
                ai_summary = :ai_summary,
                weak_areas = :weak_areas,
                debrief_spoken = :debrief_spoken,
                summary_quality = :summary_quality,
                summary_issues = :summary_issues,
                summary_corrections = :summary_corrections,
                confirmed_misunderstandings = :confirmed_misunderstandings,
                timing_status = :timing_status,
                formal_result_code = :formal_result_code,
                formal_result_label = :formal_result_label,
                pass_gate_met = :pass_gate_met,
                counts_as_unsat = :counts_as_unsat,
                finalized_by_logic_version = :logic_version,
                completed_at = :completed_at,
                updated_at = NOW()
            WHERE id = :id
        ");

        $stmt->execute([
            ':score_pct' => $scorePct,
            ':ai_summary' => $aiSummary,
            ':weak_areas' => $weakAreas,
            ':debrief_spoken' => $spoken,
            ':summary_quality' => $summaryQual,
            ':summary_issues' => $summaryIssues,
            ':summary_corrections' => $summaryCorr,
            ':confirmed_misunderstandings' => $misunder,
            ':timing_status' => (string)($classification['timing_status'] ?? 'unknown'),
            ':formal_result_code' => (string)($classification['formal_result_code'] ?? ''),
            ':formal_result_label' => (string)($classification['formal_result_label'] ?? ''),
            ':pass_gate_met' => (int)($classification['pass_gate_met'] ?? 0),
            ':counts_as_unsat' => (int)($classification['counts_as_unsat'] ?? 0),
            ':logic_version' => self::LOGIC_VERSION,
            ':completed_at' => $completedAt,
            ':id' => $progressTestId
        ]);

        $requiredActions = $this->ensureRequiredActionsForDecision(
            $progressTestId,
            $decision
        );

        $projection = $this->computeLessonActivityProjection([
            'phase'            => 'finalize',
            'user_id'          => $userId,
            'cohort_id'        => $cohortId,
            'lesson_id'        => $lessonId,
            'summary_state'    => $summaryState,
            'attempt_state'    => $attemptState,
            'deadline_state'   => $deadlineState,
            'classification'   => $classification,
            'activity_state'   => $context['activity_state'],
            'required_actions' => $requiredActions,
            'completed_at'     => $completedAt,
        ], $decision);

        $projectionResult = $this->persistLessonActivityProjection(
            $userId,
            $cohortId,
            $lessonId,
            $projection
        );

		$queuedEmailIds = [];

        $studentName = (string)(($context['student_recipient']['name'] ?? '') ?: 'Student');
        $studentEmail = (string)($context['student_recipient']['email'] ?? '');
        $lessonTitle = (string)($context['lesson_title'] ?? '');
        $cohortTitle = (string)($context['cohort_title'] ?? '');

        $chiefInstructorName = (string)(($context['chief_instructor_recipient']['name'] ?? '') ?: 'Chief Instructor');
        $chiefInstructorEmail = (string)($context['chief_instructor_recipient']['email'] ?? '');

        $remediationUrl = '';
        $approvalUrl = '';

        foreach ((array)($requiredActions['actions'] ?? []) as $actionItem) {
            $actionType = (string)($actionItem['action']['action_type'] ?? '');
            if ($actionType === 'remediation_acknowledgement') {
                $remediationUrl = (string)($actionItem['action_url'] ?? '');
            } elseif ($actionType === 'instructor_approval') {
                $approvalUrl = (string)($actionItem['action_url'] ?? '');
            }
        }

        //UPDATED LOGIC PATH V3
		        $currentAttemptNumber = (int)($attemptState['current_attempt_number'] ?? (int)($testRow['attempt'] ?? 0));
        $initialAttemptLimit = (int)($attemptState['initial_attempt_limit'] ?? 0);
        $maxTotalAttemptsWithoutAdminOverride = (int)($attemptState['max_total_attempts_without_admin_override'] ?? 0);

        $multipleUnsatSameLessonThreshold = (int)$this->resolveProgressionPolicyValue(
            $policy,
            'multiple_unsat_same_lesson_threshold',
            [],
            3
        );

        $multipleUnsatCoursewideThreshold = (int)$this->resolveProgressionPolicyValue(
            $policy,
            'multiple_unsat_coursewide_threshold',
            [],
            5
        );

        $multipleUnsatWindowDays = (int)$this->resolveProgressionPolicyValue(
            $policy,
            'multiple_unsat_window_days',
            [],
            30
        );

        $sameLessonUnsatCount = 0;
        $coursewideUnsatCount = 0;

        if (!empty($classification['counts_as_unsat'])) {
            $sameLessonUnsatCount = $this->countUnsatResultsForLesson($userId, $cohortId, $lessonId);
            $coursewideUnsatCount = $this->countUnsatResultsCoursewide($userId, $multipleUnsatWindowDays);
        }

        $automationEventContext = [
            'user_id' => $userId,
            'cohort_id' => $cohortId,
            'lesson_id' => $lessonId,
            'progress_test_id' => $progressTestId,

            'student_name' => $studentName,
            'student_email' => $studentEmail,
            'chief_instructor_name' => $chiefInstructorName,
            'chief_instructor_email' => $chiefInstructorEmail,
            'lesson_title' => $lessonTitle,
            'cohort_title' => $cohortTitle,

            'attempt_count' => (string)$currentAttemptNumber,
            'initial_attempt_limit' => (int)$initialAttemptLimit,
            'max_total_attempts_without_admin_override' => (int)$maxTotalAttemptsWithoutAdminOverride,
            'initial_attempt_limit_reached' => $initialAttemptLimit > 0 && $currentAttemptNumber >= $initialAttemptLimit ? 1 : 0,
            'max_attempts_reached' => $maxTotalAttemptsWithoutAdminOverride > 0 && $currentAttemptNumber >= $maxTotalAttemptsWithoutAdminOverride ? 1 : 0,

            'score_pct' => (string)$scorePct,
            'formal_result_code' => (string)($classification['formal_result_code'] ?? ''),
            'formal_result_label' => (string)($classification['formal_result_label'] ?? ''),
            'timing_status' => (string)($classification['timing_status'] ?? ''),
            'pass_gate_met' => (int)($classification['pass_gate_met'] ?? 0),
            'counts_as_unsat' => (int)($classification['counts_as_unsat'] ?? 0),

            'decision_code' => (string)($classification['formal_result_code'] ?? ''),
            'remediation_required' => !empty($decision['remediation_required']) ? 1 : 0,
            'instructor_required' => !empty($decision['instructor_required']) ? 1 : 0,
            'deadline_blocked' => !empty($decision['deadline_blocked']) ? 1 : 0,
            'training_suspended' => !empty($decision['training_suspended']) ? 1 : 0,
            'summary_blocked' => !empty($decision['summary_blocked']) ? 1 : 0,

            'same_lesson_unsat_count' => (int)$sameLessonUnsatCount,
            'coursewide_unsat_count' => (int)$coursewideUnsatCount,
            'multiple_unsat_same_lesson_threshold' => (int)$multipleUnsatSameLessonThreshold,
            'multiple_unsat_coursewide_threshold' => (int)$multipleUnsatCoursewideThreshold,
            'multiple_unsat_window_days' => (int)$multipleUnsatWindowDays,
            'multiple_unsat_triggered' => (
                ($multipleUnsatSameLessonThreshold > 0 && $sameLessonUnsatCount >= $multipleUnsatSameLessonThreshold) ||
                ($multipleUnsatCoursewideThreshold > 0 && $coursewideUnsatCount >= $multipleUnsatCoursewideThreshold)
            ) ? 1 : 0,

            'weak_areas_text' => $weakAreas,
			'weak_areas_html' => nl2br($this->escapeHtml($weakAreas)),
			'written_debrief_text' => $aiSummary,
			'written_debrief_html' => nl2br($this->escapeHtml($aiSummary)),
            'summary_quality' => $summaryQual,
            'summary_issues' => $summaryIssues,
            'summary_corrections' => $summaryCorr,
            'confirmed_misunderstandings' => $misunder,

            'remediation_url' => $remediationUrl,
            'approval_url' => $approvalUrl,
        ];
		
		
        $this->logProgressionEvent([
            'user_id' => $userId,
            'cohort_id' => $cohortId,
            'lesson_id' => $lessonId,
            'progress_test_id' => $progressTestId,
            'event_type' => 'finalization',
            'event_code' => 'progress_test_finalized',
            'event_status' => 'info',
            'actor_type' => 'system',
            'event_time' => $completedAt,
            'payload' => [
                'score_pct' => $scorePct,
                'classification' => $classification,
                'decision' => $decision,
                'queued_email_ids' => $queuedEmailIds
            ],
        ]);

        $this->pdo->commit();

        $automationResult = null;

        $eventKey = !empty($classification['pass_gate_met'])
            ? 'progress_test_passed'
            : 'progress_test_failed';

        if (is_file(__DIR__ . '/automation_runtime.php')) {
            require_once __DIR__ . '/automation_runtime.php';
            $automation = new AutomationRuntime();

            $this->logProgressionEvent([
                'user_id' => $userId,
                'cohort_id' => $cohortId,
                'lesson_id' => $lessonId,
                'progress_test_id' => $progressTestId,
                'event_type' => 'automation',
                'event_code' => 'automation_dispatch_before',
                'event_status' => 'info',
                'actor_type' => 'system',
                'event_time' => gmdate('Y-m-d H:i:s'),
                'payload' => [
                    'event_key' => $eventKey,
                    'attempt_count' => (int)($testRow['attempt'] ?? 0),
                    'score_pct' => $scorePct,
                ],
            ]);

            $automationResult = $automation->dispatchEvent($this->pdo, $eventKey, $automationEventContext);

            $this->logProgressionEvent([
                'user_id' => $userId,
                'cohort_id' => $cohortId,
                'lesson_id' => $lessonId,
                'progress_test_id' => $progressTestId,
                'event_type' => 'automation',
                'event_code' => 'automation_dispatch_after',
                'event_status' => 'info',
                'actor_type' => 'system',
                'event_time' => gmdate('Y-m-d H:i:s'),
                'payload' => [
                    'event_key' => $eventKey,
                    'automation_result' => $automationResult,
                ],
            ]);
        }

        return [
            'ok' => true,
            'classification' => $classification,
            'summary_state' => $summaryState,
            'activity_state' => $projection['fields'],
            'queued_email_ids' => $queuedEmailIds,
            'remediation_triggered' => !empty($decision['remediation_required']),
            'instructor_escalation_triggered' => !empty($decision['instructor_required']),
            'automation_result' => $automationResult,
        ];

    } catch (Throwable $e) {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        throw $e;
    }
}
	
	
	
	
    public function evaluateProgressionDecision(array $context): array
    {
        $activity = (array)($context['activity_state'] ?? []);
        $deadline = (array)($context['deadline_state'] ?? []);
        $attempt = (array)($context['attempt_state'] ?? []);
        $classification = (array)($context['classification'] ?? []);
        $summaryState = (array)($context['summary_state'] ?? []);
        $phase = (string)($context['phase'] ?? 'prepare_start');

        $trainingSuspended = !empty($activity['training_suspended']);
        $oneOnOneRequired = !empty($activity['one_on_one_required']) && empty($activity['one_on_one_completed']);
        $deadlineBlocked = !empty($deadline['deadline_passed']);

        $summaryRequiredBeforeStart = !empty($summaryState['summary_required_before_test_start']);
        $summaryStatus = (string)($summaryState['summary_status'] ?? 'missing');
        $summaryReadyForStart = (!$summaryRequiredBeforeStart) || ($summaryStatus === 'acceptable');

        $instructorRequired = false;
        $remediationRequired = false;
        $summaryBlocked = false;

        if ($phase === 'prepare_start') {
            if (!$summaryReadyForStart) {
                $summaryBlocked = true;
            } elseif (($attempt['next_attempt_number'] ?? 1) > ($attempt['effective_allowed_attempts'] ?? 0)) {
                if (($attempt['next_attempt_number'] ?? 1) >= ($attempt['instructor_escalation_attempt'] ?? PHP_INT_MAX)) {
                    $instructorRequired = true;
                } elseif (!(bool)($attempt['remediation_completed'] ?? false)) {
                    $remediationRequired = true;
                } else {
                    $instructorRequired = true;
                }
            } elseif (
                ($attempt['next_attempt_number'] ?? 1) > ($attempt['initial_attempt_limit'] ?? PHP_INT_MAX)
                && !(bool)($attempt['remediation_completed'] ?? false)
            ) {
                $remediationRequired = true;
            }
                } else {
            		if (!empty($classification['counts_as_unsat'])) {
						$currentAttemptNumber = (int)($attempt['current_attempt_number'] ?? 1);
						$effectiveAllowedAttempts = (int)($attempt['effective_allowed_attempts'] ?? 0);
						$remediationTriggerAttempt = (int)($attempt['remediation_trigger_attempt'] ?? PHP_INT_MAX);
						$remediationCompleted = !empty($attempt['remediation_completed']);

						if ($effectiveAllowedAttempts <= 0) {
							$effectiveAllowedAttempts = (int)($attempt['instructor_escalation_attempt'] ?? PHP_INT_MAX);
						}

						if ($currentAttemptNumber >= $effectiveAllowedAttempts) {
							$instructorRequired = true;
						} elseif (
							$currentAttemptNumber === $remediationTriggerAttempt
							&& !$remediationCompleted
						) {
							$remediationRequired = true;
						}
					}
        }

        if ($oneOnOneRequired) {
            $instructorRequired = true;
        }

        $priority = 'normal';
        $allowed = true;
        $requiredActionDecision = [
            'should_create_any' => false,
            'action_types' => [],
        ];

        if ($trainingSuspended) {
            $priority = 'training_suspended';
            $allowed = false;
        } elseif ($deadlineBlocked) {
            $priority = 'deadline_blocked';
            $allowed = false;
        } elseif ($instructorRequired) {
            $priority = 'instructor_required';
            $allowed = false;
            if ($phase === 'finalize') {
                $requiredActionDecision = [
                    'should_create_any' => true,
                    'action_types' => ['instructor_approval'],
                ];
            }
        } elseif ($remediationRequired) {
            $priority = 'remediation_required';
            $allowed = false;
            if ($phase === 'finalize') {
                $requiredActionDecision = [
                    'should_create_any' => true,
                    'action_types' => ['remediation_acknowledgement'],
                ];
            }
        } elseif ($summaryBlocked) {
            $priority = 'summary_required';
            $allowed = false;
        }

        return [
            'phase' => $phase,
            'priority_state' => $priority,
            'allowed' => $allowed,
            'required_action_decision' => $requiredActionDecision,
            'training_suspended' => $trainingSuspended,
            'deadline_blocked' => $deadlineBlocked,
            'instructor_required' => $instructorRequired,
            'remediation_required' => $remediationRequired,
            'summary_blocked' => $summaryBlocked,
        ];
    }

    public function computeLessonActivityProjection(array $context, array $decision): array
    {
        $userId = (int)$context['user_id'];
        $cohortId = (int)$context['cohort_id'];
        $lessonId = (int)$context['lesson_id'];
        $phase = (string)($context['phase'] ?? 'unknown');
        $summaryState = (array)($context['summary_state'] ?? []);
        $deadlineState = (array)($context['deadline_state'] ?? []);
        $attemptState = (array)($context['attempt_state'] ?? []);
        $classification = (array)($context['classification'] ?? []);
        $activity = (array)($context['activity_state'] ?? []);
        $requiredActions = (array)($context['required_actions'] ?? []);

        $fields = [
            'summary_status' => (string)($summaryState['summary_status'] ?? ($activity['summary_status'] ?? 'missing')),
            'effective_deadline_utc' => (string)($deadlineState['effective_deadline_utc'] ?? ($activity['effective_deadline_utc'] ?? '')),
            'last_state_eval_at' => gmdate('Y-m-d H:i:s'),
        ];

        if ($phase === 'prepare_start') {
            $fields['started_at'] = $activity['started_at'] ?? gmdate('Y-m-d H:i:s');
            $fields['completion_status'] = !empty($decision['allowed']) ? 'in_progress' : (string)$decision['priority_state'];
            $fields['test_pass_status'] = 'in_progress';
        } else {
            $fields['attempt_count'] = (int)($attemptState['current_attempt_number'] ?? 0);
            $fields['best_score'] = isset($classification['score_pct'])
                ? max((int)($activity['best_score'] ?? 0), (int)$classification['score_pct'])
                : (int)($activity['best_score'] ?? 0);

            if (!empty($classification['pass_gate_met'])) {
                $fields['test_pass_status'] = 'passed';
                $fields['completion_status'] = ((string)($summaryState['summary_status'] ?? '') === 'acceptable')
                    ? 'completed'
                    : 'awaiting_summary_review';

                if ($fields['completion_status'] === 'completed') {
                    $fields['completed_at'] = (string)($context['completed_at'] ?? gmdate('Y-m-d H:i:s'));
                    $fields['next_lesson_unlocked_at'] = (string)($context['completed_at'] ?? gmdate('Y-m-d H:i:s'));
                }
            } else {
                $fields['test_pass_status'] = !empty($decision['deadline_blocked']) ? 'deadline_missed' : 'failed';
                $fields['completion_status'] = (string)($decision['priority_state'] === 'normal'
                    ? 'in_progress'
                    : $decision['priority_state']);
            }

            if (!empty($requiredActions['latest_instructor_action_id'])) {
                $fields['latest_instructor_action_id'] = (int)$requiredActions['latest_instructor_action_id'];
            }
        }

        return [
            'engine_projection' => true,
            'user_id' => $userId,
            'cohort_id' => $cohortId,
            'lesson_id' => $lessonId,
            'phase' => $phase,
            'fields' => $fields,
        ];
    }

    public function persistLessonActivityProjection(int $userId, int $cohortId, int $lessonId, array $projection): array
    {
        if (empty($projection['engine_projection']) || !is_array($projection['fields'] ?? null)) {
            throw new InvalidArgumentException('persistLessonActivityProjection only accepts canonical output from computeLessonActivityProjection().');
        }

        if ((int)$projection['user_id'] !== $userId || (int)$projection['cohort_id'] !== $cohortId || (int)$projection['lesson_id'] !== $lessonId) {
            throw new InvalidArgumentException('Projection identity mismatch.');
        }

        $allowedFields = [
            'started_at',
            'completed_at',
            'attempt_count',
            'best_score',
            'summary_status',
            'test_pass_status',
            'completion_status',
            'effective_deadline_utc',
            'last_state_eval_at',
            'next_lesson_unlocked_at',
            'latest_instructor_action_id',
            'granted_extra_attempts',
            'one_on_one_required',
            'one_on_one_completed',
            'training_suspended',
        ];

        $fields = [];
        foreach ($allowedFields as $key) {
            if (array_key_exists($key, $projection['fields'])) {
                $fields[$key] = $projection['fields'][$key];
            }
        }

        if (!$fields) {
            return [
                'ok' => true,
                'changed_fields' => [],
                'action' => 'noop',
            ];
        }

        $existing = $this->getLessonActivityProjectionRow($userId, $cohortId, $lessonId);

        if ($existing) {
            $set = [];
            $params = [
                ':user_id' => $userId,
                ':cohort_id' => $cohortId,
                ':lesson_id' => $lessonId,
            ];

            foreach ($fields as $key => $value) {
                $set[] = "{$key} = :{$key}";
                $params[":{$key}"] = $value;
            }

            $set[] = "updated_at = NOW()";

            $sql = "
                UPDATE lesson_activity
                SET " . implode(", ", $set) . "
                WHERE user_id = :user_id
                  AND cohort_id = :cohort_id
                  AND lesson_id = :lesson_id
                LIMIT 1
            ";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);

            return [
                'ok' => true,
                'changed_fields' => array_keys($fields),
                'action' => 'update',
            ];
        }

        $columns = ['user_id', 'cohort_id', 'lesson_id'];
        $placeholders = [':user_id', ':cohort_id', ':lesson_id'];
        $params = [
            ':user_id' => $userId,
            ':cohort_id' => $cohortId,
            ':lesson_id' => $lessonId,
        ];

        foreach ($fields as $key => $value) {
            $columns[] = $key;
            $placeholders[] = ':' . $key;
            $params[':' . $key] = $value;
        }

        $columns[] = 'created_at';
        $columns[] = 'updated_at';
        $placeholders[] = 'NOW()';
        $placeholders[] = 'NOW()';

        $sql = "
            INSERT INTO lesson_activity (" . implode(', ', $columns) . ")
            VALUES (" . implode(', ', $placeholders) . ")
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return [
            'ok' => true,
            'changed_fields' => array_keys($fields),
            'action' => 'insert',
        ];
    }

    public function progressionEmailExistsForProgressTest(int $progressTestId, string $emailType): bool
    {
        $sql = "
            SELECT 1
            FROM training_progression_emails
            WHERE progress_test_id = :progress_test_id
              AND email_type = :email_type
            LIMIT 1
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':progress_test_id' => $progressTestId,
            ':email_type' => $emailType,
        ]);

        return (bool)$stmt->fetchColumn();
    }

    public function hasAnyProgressionEmailForLesson(int $userId, int $cohortId, int $lessonId, string $emailType): bool
    {
        $sql = "
            SELECT 1
            FROM training_progression_emails
            WHERE user_id = :user_id
              AND cohort_id = :cohort_id
              AND lesson_id = :lesson_id
              AND email_type = :email_type
            LIMIT 1
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':user_id' => $userId,
            ':cohort_id' => $cohortId,
            ':lesson_id' => $lessonId,
            ':email_type' => $emailType,
        ]);

        return (bool)$stmt->fetchColumn();
    }

    public function getPendingRequiredAction(int $userId, int $cohortId, int $lessonId, string $actionType): ?array
    {
        $sql = "
            SELECT *
            FROM student_required_actions
            WHERE user_id = :user_id
              AND cohort_id = :cohort_id
              AND lesson_id = :lesson_id
              AND action_type = :action_type
              AND status IN ('pending','opened')
            ORDER BY id DESC
            LIMIT 1
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':user_id' => $userId,
            ':cohort_id' => $cohortId,
            ':lesson_id' => $lessonId,
            ':action_type' => $actionType,
        ]);

        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function getLatestCompletedRequiredAction(int $userId, int $cohortId, int $lessonId, string $actionType): ?array
    {
        $sql = "
            SELECT *
            FROM student_required_actions
            WHERE user_id = :user_id
              AND cohort_id = :cohort_id
              AND lesson_id = :lesson_id
              AND action_type = :action_type
              AND status IN ('completed','approved')
            ORDER BY id DESC
            LIMIT 1
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':user_id' => $userId,
            ':cohort_id' => $cohortId,
            ':lesson_id' => $lessonId,
            ':action_type' => $actionType,
        ]);

        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function getRequiredActionByToken(string $token): ?array
    {
        $sql = "
            SELECT *
            FROM student_required_actions
            WHERE token = :token
            LIMIT 1
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':token' => $token,
        ]);

        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function getRequiredActionById(int $actionId): ?array
    {
        $sql = "
            SELECT *
            FROM student_required_actions
            WHERE id = :id
            LIMIT 1
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':id' => $actionId,
        ]);

        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function markRequiredActionOpened(int $actionId, ?string $ipAddress = null, ?string $userAgent = null): void
    {
        $sql = "
            UPDATE student_required_actions
            SET
                status = CASE
                    WHEN status = 'pending' THEN 'opened'
                    ELSE status
                END,
                opened_at = CASE
                    WHEN opened_at IS NULL THEN :opened_at
                    ELSE opened_at
                END,
                ip_address = COALESCE(:ip_address, ip_address),
                user_agent = COALESCE(:user_agent, user_agent),
                updated_at = :updated_at
            WHERE id = :id
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':opened_at' => gmdate('Y-m-d H:i:s'),
            ':ip_address' => $ipAddress,
            ':user_agent' => $userAgent,
            ':updated_at' => gmdate('Y-m-d H:i:s'),
            ':id' => $actionId,
        ]);
    }

    public function approveRequiredAction(int $actionId, ?string $ipAddress = null, ?string $userAgent = null): void
    {
        $sql = "
            UPDATE student_required_actions
            SET
                status = 'approved',
                approved_at = :approved_at,
                completed_at = COALESCE(completed_at, :completed_at),
                ip_address = COALESCE(:ip_address, ip_address),
                user_agent = COALESCE(:user_agent, user_agent),
                updated_at = :updated_at
            WHERE id = :id
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':approved_at' => gmdate('Y-m-d H:i:s'),
            ':completed_at' => gmdate('Y-m-d H:i:s'),
            ':ip_address' => $ipAddress,
            ':user_agent' => $userAgent,
            ':updated_at' => gmdate('Y-m-d H:i:s'),
            ':id' => $actionId,
        ]);
    }

    public function recordInstructorDecision(
        int $actionId,
        array $decision,
        int $actorUserId,
        ?string $ipAddress = null,
        ?string $userAgent = null
    ): array {
        $result = $this->processInstructorApprovalDecision(
            $actionId,
            $decision,
            $actorUserId,
            (string)($ipAddress ?? ''),
            (string)($userAgent ?? '')
        );

        $state = (array)($result['state'] ?? []);
        $action = (array)($state['action'] ?? []);

        return [
            'decision_code' => (string)($action['decision_code'] ?? ($decision['decision_code'] ?? '')),
            'granted_extra_attempts' => (int)($action['granted_extra_attempts'] ?? ($decision['granted_extra_attempts'] ?? 0)),
            'summary_revision_required' => (int)($action['summary_revision_required'] ?? (!empty($decision['summary_revision_required']) ? 1 : 0)),
            'one_on_one_required' => (int)($action['one_on_one_required'] ?? (!empty($decision['one_on_one_required']) ? 1 : 0)),
            'training_suspended' => (int)($action['training_suspended'] ?? (!empty($decision['training_suspended']) ? 1 : 0)),
            'major_intervention_flag' => (int)($action['major_intervention_flag'] ?? (!empty($decision['major_intervention_flag']) ? 1 : 0)),
            'decision_notes' => (string)($action['decision_notes'] ?? ($decision['decision_notes'] ?? '')),
            'message' => (string)($result['message'] ?? 'Instructor decision saved successfully.'),
            'state' => $state,
        ];
    }

 public function markInstructorSessionCompleted(
    int $actionId,
    int $actorUserId,
    ?string $ipAddress = null,
    ?string $userAgent = null
): array
{
    return $this->markInstructorApprovalOneOnOneCompleted(
        $actionId,
        $actorUserId,
        (string)($ipAddress ?? ''),
        (string)($userAgent ?? '')
    );
}

    public function markInstructorApprovalPageOpened(int $requiredActionId, string $ipAddress, string $userAgent): array
    {
        $action = $this->getRequiredActionById($requiredActionId);
        if (!$action) {
            throw new RuntimeException('Required action not found.');
        }
        if ((string)$action['action_type'] !== 'instructor_approval') {
            throw new RuntimeException('Required action is not instructor_approval.');
        }

        $this->markRequiredActionOpened($requiredActionId, $ipAddress, $userAgent);
        $updated = $this->getRequiredActionById($requiredActionId);

        return [
            'message' => 'Instructor approval page marked opened.',
            'state' => $this->getInstructorApprovalPageStateByToken((string)($updated['token'] ?? $action['token'])),
        ];
    }

public function processInstructorApprovalDecision(int $requiredActionId, array $payload, int $actorUserId, string $ipAddress, string $userAgent): array
{
    $action = $this->getRequiredActionById($requiredActionId);
    if (!$action) {
        throw new RuntimeException('Required action not found.');
    }

    if ((string)$action['action_type'] !== 'instructor_approval') {
        throw new RuntimeException('Required action is not instructor_approval.');
    }

    if (!in_array((string)$action['status'], ['pending', 'opened'], true)) {
        throw new RuntimeException('Instructor approval action is no longer pending.');
    }

    $decisionCode = trim((string)($payload['decision_code'] ?? ''));
    $decisionNotes = trim((string)($payload['decision_notes'] ?? ''));
	$oneOnOneDate = trim((string)($payload['one_on_one_date'] ?? ''));
    $oneOnOneTimeFrom = trim((string)($payload['one_on_one_time_from'] ?? ''));
    $oneOnOneTimeUntil = trim((string)($payload['one_on_one_time_until'] ?? ''));
    $oneOnOneInstructorUserId = (int)($payload['one_on_one_instructor_user_id'] ?? 0);
	$oneOnOneStartUtc = trim((string)($payload['one_on_one_start_utc'] ?? ''));
    $oneOnOneEndUtc = trim((string)($payload['one_on_one_end_utc'] ?? ''));
    $oneOnOneTimezone = trim((string)($payload['one_on_one_timezone'] ?? ''));
	$oneOnOneDate = trim((string)($payload['one_on_one_date'] ?? ''));
	$oneOnOneTimeFrom = trim((string)($payload['one_on_one_time_from'] ?? ''));
	$oneOnOneTimeUntil = trim((string)($payload['one_on_one_time_until'] ?? ''));
	$oneOnOneInstructorUserId = (int)($payload['one_on_one_instructor_user_id'] ?? 0);

    if ($decisionCode === '') {
        throw new RuntimeException('decision_code is required.');
    }
    if ($decisionNotes === '') {
        throw new RuntimeException('decision_notes is required.');
    }

    $allowedDecisionCodes = [
        'approve_additional_attempts',
        'approve_with_summary_revision',
        'approve_with_one_on_one',
        'suspend_training',
    ];

    if (!in_array($decisionCode, $allowedDecisionCodes, true)) {
        throw new RuntimeException('Invalid decision_code.');
    }

    $grantedExtraAttempts = max(0, min(5, (int)($payload['granted_extra_attempts'] ?? 0)));
    $summaryRevisionRequired = !empty($payload['summary_revision_required']) ? 1 : 0;
    $oneOnOneRequired = !empty($payload['one_on_one_required']) ? 1 : 0;
    $trainingSuspended = !empty($payload['training_suspended']) ? 1 : 0;
    $majorInterventionFlag = !empty($payload['major_intervention_flag']) ? 1 : 0;

    if ($decisionCode === 'approve_additional_attempts') {
        $summaryRevisionRequired = 0;
        $oneOnOneRequired = 0;
        $trainingSuspended = 0;
    } elseif ($decisionCode === 'approve_with_summary_revision') {
        $summaryRevisionRequired = 1;
        $oneOnOneRequired = 0;
        $trainingSuspended = 0;
    } elseif ($decisionCode === 'approve_with_one_on_one') {
        $summaryRevisionRequired = 0;
        $oneOnOneRequired = 1;
        $trainingSuspended = 0;
    } elseif ($decisionCode === 'suspend_training') {
        $summaryRevisionRequired = 0;
        $oneOnOneRequired = 0;
        $trainingSuspended = 1;
        $grantedExtraAttempts = 0;
        $majorInterventionFlag = 1;
    }

    $nowUtc = gmdate('Y-m-d H:i:s');
    $automationContext = [];
    $projectionResult = [];

    $this->pdo->beginTransaction();

    try {
                $decisionPayload = [
            'decision_code' => $decisionCode,
            'granted_extra_attempts' => $grantedExtraAttempts,
            'summary_revision_required' => $summaryRevisionRequired,
            'one_on_one_required' => $oneOnOneRequired,
            'training_suspended' => $trainingSuspended,
            'major_intervention_flag' => $majorInterventionFlag,
            'decision_notes' => $decisionNotes,

            'one_on_one_date' => $oneOnOneDate,
            'one_on_one_time_from' => $oneOnOneTimeFrom,
            'one_on_one_time_until' => $oneOnOneTimeUntil,
            'one_on_one_instructor_user_id' => $oneOnOneInstructorUserId,

            'one_on_one_start_utc' => $oneOnOneStartUtc,
            'one_on_one_end_utc' => $oneOnOneEndUtc,
            'one_on_one_timezone' => $oneOnOneTimezone,
        ];

        $stmt = $this->pdo->prepare("
            UPDATE student_required_actions
            SET
                status = 'approved',
                approved_at = :approved_at,
                completed_at = COALESCE(completed_at, :completed_at),
                ip_address = COALESCE(:ip_address, ip_address),
                user_agent = COALESCE(:user_agent, user_agent),
                decision_code = :decision_code,
                decision_notes = :decision_notes,
                decision_payload_json = :decision_payload_json,
                decision_by_user_id = :decision_by_user_id,
                decision_at = :decision_at,
                granted_extra_attempts = :granted_extra_attempts,
                summary_revision_required = :summary_revision_required,
                one_on_one_required = :one_on_one_required,
                training_suspended = :training_suspended,
                major_intervention_flag = :major_intervention_flag,
                updated_at = :updated_at
            WHERE id = :id
        ");

        $stmt->execute([
            ':approved_at' => $nowUtc,
            ':completed_at' => $nowUtc,
            ':ip_address' => $ipAddress,
            ':user_agent' => $userAgent,
            ':decision_code' => $decisionCode,
            ':decision_notes' => $decisionNotes,
            ':decision_payload_json' => json_encode($decisionPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ':decision_by_user_id' => $actorUserId,
            ':decision_at' => $nowUtc,
            ':granted_extra_attempts' => $grantedExtraAttempts,
            ':summary_revision_required' => $summaryRevisionRequired,
            ':one_on_one_required' => $oneOnOneRequired,
            ':training_suspended' => $trainingSuspended,
            ':major_intervention_flag' => $majorInterventionFlag,
            ':updated_at' => $nowUtc,
            ':id' => $requiredActionId,
        ]);

		if ($summaryRevisionRequired === 1) {
            $summaryStmt = $this->pdo->prepare("
                UPDATE lesson_summaries
                SET
                    review_status = 'needs_revision',
                    student_soft_locked = 0,
                    updated_at = CURRENT_TIMESTAMP
                WHERE user_id = :user_id
                  AND cohort_id = :cohort_id
                  AND lesson_id = :lesson_id
            ");

            $summaryStmt->execute([
                ':user_id' => (int)$action['user_id'],
                ':cohort_id' => (int)$action['cohort_id'],
                ':lesson_id' => (int)$action['lesson_id'],
            ]);
        }	

        $currentActivity = $this->getLessonActivityProjectionRow(
            (int)$action['user_id'],
            (int)$action['cohort_id'],
            (int)$action['lesson_id']
        ) ?? [];

        $currentGrantedExtraAttempts = max(0, (int)($currentActivity['granted_extra_attempts'] ?? 0));
        $newTotalGrantedExtraAttempts = $currentGrantedExtraAttempts;

        if (
			$decisionCode === 'approve_additional_attempts' ||
			$decisionCode === 'approve_with_summary_revision' ||
			$decisionCode === 'approve_with_one_on_one'
		) {
			$newTotalGrantedExtraAttempts = $currentGrantedExtraAttempts + $grantedExtraAttempts;
		}

        if ($decisionCode === 'suspend_training') {
            $newTotalGrantedExtraAttempts = $currentGrantedExtraAttempts;
        }

        $projection = [
            'engine_projection' => true,
            'user_id' => (int)$action['user_id'],
            'cohort_id' => (int)$action['cohort_id'],
            'lesson_id' => (int)$action['lesson_id'],
            'phase' => 'instructor_approval_decision',
            'fields' => [
                'granted_extra_attempts' => $newTotalGrantedExtraAttempts,
                'one_on_one_required' => $oneOnOneRequired,
                'training_suspended' => $trainingSuspended,
                'latest_instructor_action_id' => $requiredActionId,
                'last_state_eval_at' => $nowUtc,
                'summary_status' => $summaryRevisionRequired === 1
                    ? 'needs_revision'
                    : (string)($currentActivity['summary_status'] ?? 'acceptable'),
                'completion_status' => $trainingSuspended
                    ? 'training_suspended'
                    : ($oneOnOneRequired
                        ? 'instructor_required'
                        : ($summaryRevisionRequired
                            ? 'awaiting_summary_review'
                            : 'in_progress')),
            ],
        ];

        if ($oneOnOneRequired === 1) {
            $projection['fields']['one_on_one_completed'] = 0;
        }

        $projectionResult = $this->persistLessonActivityProjection(
            (int)$action['user_id'],
            (int)$action['cohort_id'],
            (int)$action['lesson_id'],
            $projection
        );

        $this->logProgressionEvent([
            'user_id' => (int)$action['user_id'],
            'cohort_id' => (int)$action['cohort_id'],
            'lesson_id' => (int)$action['lesson_id'],
            'progress_test_id' => isset($action['progress_test_id']) ? (int)$action['progress_test_id'] : null,
            'event_type' => 'instructor_intervention',
            'event_code' => 'instructor_decision_recorded',
            'event_status' => 'warning',
            'actor_type' => 'admin',
            'actor_user_id' => $actorUserId,
            'event_time' => $nowUtc,
            'payload' => [
                'required_action_id' => $requiredActionId,
                'decision_payload' => $decisionPayload,
                'projection_result' => $projectionResult,
            ],
            'legal_note' => 'Instructor approval decision recorded through CoursewareProgressionV2.',
        ]);

        $automationContext = $this->buildInstructorDecisionAutomationContext(
            $action,
            $decisionPayload,
            (array)($projection['fields'] ?? [])
        );

        $this->pdo->commit();
    } catch (Throwable $e) {
        $this->pdo->rollBack();
        throw $e;
    }

    $automationResult = null;

    if (is_file(__DIR__ . '/automation_runtime.php')) {
        require_once __DIR__ . '/automation_runtime.php';
        $automation = new AutomationRuntime();

        $this->logProgressionEvent([
            'user_id' => (int)$action['user_id'],
            'cohort_id' => (int)$action['cohort_id'],
            'lesson_id' => (int)$action['lesson_id'],
            'progress_test_id' => isset($action['progress_test_id']) ? (int)$action['progress_test_id'] : null,
            'event_type' => 'automation',
            'event_code' => 'automation_dispatch_before',
            'event_status' => 'info',
            'actor_type' => 'system',
            'actor_user_id' => null,
            'event_time' => gmdate('Y-m-d H:i:s'),
            'payload' => [
                'event_key' => 'instructor_decision_recorded',
                'decision_code' => $decisionCode,
                'required_action_id' => $requiredActionId,
            ],
        ]);

        $automationResult = $automation->dispatchEvent(
            $this->pdo,
            'instructor_decision_recorded',
            $automationContext
        );

        $this->logProgressionEvent([
            'user_id' => (int)$action['user_id'],
            'cohort_id' => (int)$action['cohort_id'],
            'lesson_id' => (int)$action['lesson_id'],
            'progress_test_id' => isset($action['progress_test_id']) ? (int)$action['progress_test_id'] : null,
            'event_type' => 'automation',
            'event_code' => 'automation_dispatch_after',
            'event_status' => 'info',
            'actor_type' => 'system',
            'actor_user_id' => null,
            'event_time' => gmdate('Y-m-d H:i:s'),
            'payload' => [
                'event_key' => 'instructor_decision_recorded',
                'decision_code' => $decisionCode,
                'required_action_id' => $requiredActionId,
                'automation_result' => $automationResult,
            ],
        ]);
    }

    return [
        'message' => 'Instructor decision saved successfully.',
        'state' => $this->getInstructorApprovalPageStateByToken((string)$action['token']),
        'decision_payload' => [
            'decision_code' => $decisionCode,
            'granted_extra_attempts' => $grantedExtraAttempts,
            'summary_revision_required' => $summaryRevisionRequired,
            'one_on_one_required' => $oneOnOneRequired,
            'training_suspended' => $trainingSuspended,
            'major_intervention_flag' => $majorInterventionFlag,
            'decision_notes' => $decisionNotes,
            'one_on_one_date' => $oneOnOneDate,
            'one_on_one_time_from' => $oneOnOneTimeFrom,
            'one_on_one_time_until' => $oneOnOneTimeUntil,
            'one_on_one_instructor_user_id' => $oneOnOneInstructorUserId,
        ],
        'projection_result' => $projectionResult,
        'automation_result' => $automationResult,
    ];
}

    public function getInstructorApprovalPageStateByToken(string $token): ?array
    {
        $action = $this->getRequiredActionByToken($token);
        if (!$action || (string)$action['action_type'] !== 'instructor_approval') {
            return null;
        }

        $userId = (int)$action['user_id'];
        $cohortId = (int)$action['cohort_id'];
        $lessonId = (int)$action['lesson_id'];

        $activity = $this->getLessonActivityProjectionRow($userId, $cohortId, $lessonId) ?? [];
        $context = $this->getProgressionContextForUserLesson($userId, $cohortId, $lessonId);

        return [
            'action' => $action,
            'activity' => $activity,
            'access' => [
                'is_allowed' => in_array((string)$action['status'], ['pending', 'opened', 'approved'], true),
            ],
            'progression_context' => $context,
            'latest_progress_test' => $context['latest_progress_test'],
            'lesson_title' => $context['lesson_title'],
            'cohort_title' => $context['cohort_title'],
            'student_name' => (string)(($context['student_recipient']['name'] ?? '') ?: 'Student'),
            'is_pending' => in_array((string)$action['status'], ['pending', 'opened'], true),
            'is_approved' => (string)$action['status'] === 'approved',
        ];
    }


public function markInstructorApprovalOneOnOneCompleted(int $requiredActionId, int $actorUserId, string $ipAddress, string $userAgent): array
{
    $action = $this->getRequiredActionById($requiredActionId);
    if (!$action) {
        throw new RuntimeException('Required action not found.');
    }
    if ((string)$action['action_type'] !== 'instructor_approval') {
        throw new RuntimeException('Required action is not instructor_approval.');
    }
    if ((int)($action['one_on_one_required'] ?? 0) !== 1) {
        throw new RuntimeException('This required action does not require a one-on-one session.');
    }
    if ((string)$action['status'] !== 'approved') {
        throw new RuntimeException('Instructor approval action must be approved before marking one-on-one completed.');
    }

    $nowUtc = gmdate('Y-m-d H:i:s');
    $automationEventKey = 'one_on_one_completed';
    $automationContext = null;

    $this->pdo->beginTransaction();

    try {
        $stmt = $this->pdo->prepare("
            UPDATE student_required_actions
            SET
                completed_at = COALESCE(completed_at, :completed_at),
                ip_address = COALESCE(:ip_address, ip_address),
                user_agent = COALESCE(:user_agent, user_agent),
                updated_at = :updated_at
            WHERE id = :id
        ");
        $stmt->execute([
            ':completed_at' => $nowUtc,
            ':ip_address' => $ipAddress,
            ':user_agent' => $userAgent,
            ':updated_at' => $nowUtc,
            ':id' => $requiredActionId,
        ]);

        $projection = [
            'engine_projection' => true,
            'user_id' => (int)$action['user_id'],
            'cohort_id' => (int)$action['cohort_id'],
            'lesson_id' => (int)$action['lesson_id'],
            'phase' => 'instructor_one_on_one_completed',
            'fields' => [
                'one_on_one_completed' => 1,
                'completion_status' => 'in_progress',
                'latest_instructor_action_id' => $requiredActionId,
                'last_state_eval_at' => $nowUtc,
            ],
        ];

        $projectionResult = $this->persistLessonActivityProjection(
            (int)$action['user_id'],
            (int)$action['cohort_id'],
            (int)$action['lesson_id'],
            $projection
        );

        $this->logProgressionEvent([
            'user_id' => (int)$action['user_id'],
            'cohort_id' => (int)$action['cohort_id'],
            'lesson_id' => (int)$action['lesson_id'],
            'progress_test_id' => isset($action['progress_test_id']) ? (int)$action['progress_test_id'] : null,
            'event_type' => 'instructor_intervention',
            'event_code' => 'one_on_one_completed',
            'event_status' => 'info',
            'actor_type' => 'admin',
            'actor_user_id' => $actorUserId,
            'event_time' => $nowUtc,
            'payload' => [
                'required_action_id' => $requiredActionId,
                'projection_result' => $projectionResult,
            ],
            'legal_note' => 'Instructor one-on-one completion recorded through CoursewareProgressionV2.',
        ]);

        $automationContext = $this->buildInstructorDecisionAutomationContext(
            $action,
            [
                'decision_code' => (string)($action['decision_code'] ?? ''),
                'granted_extra_attempts' => (int)($action['granted_extra_attempts'] ?? 0),
                'summary_revision_required' => (int)($action['summary_revision_required'] ?? 0),
                'one_on_one_required' => 1,
                'training_suspended' => (int)($action['training_suspended'] ?? 0),
                'major_intervention_flag' => (int)($action['major_intervention_flag'] ?? 0),
                'decision_notes' => (string)($action['decision_notes'] ?? ''),
            ],
            (array)$projection['fields']
        );
        $automationContext['one_on_one_completed'] = 1;

        $this->pdo->commit();

        $automationResult = null;

        if (is_file(__DIR__ . '/automation_runtime.php')) {
            require_once __DIR__ . '/automation_runtime.php';
            $automation = new AutomationRuntime();

            $this->logProgressionEvent([
                'user_id' => (int)$action['user_id'],
                'cohort_id' => (int)$action['cohort_id'],
                'lesson_id' => (int)$action['lesson_id'],
                'progress_test_id' => isset($action['progress_test_id']) ? (int)$action['progress_test_id'] : null,
                'event_type' => 'automation',
                'event_code' => 'automation_dispatch_before',
                'event_status' => 'info',
                'actor_type' => 'system',
                'actor_user_id' => null,
                'event_time' => gmdate('Y-m-d H:i:s'),
                'payload' => [
                    'event_key' => $automationEventKey,
                    'required_action_id' => $requiredActionId,
                ],
                'legal_note' => 'Automation dispatch starting after canonical one-on-one completion commit.',
            ]);

            $automationResult = $automation->dispatchEvent($this->pdo, $automationEventKey, $automationContext);

            $this->logProgressionEvent([
                'user_id' => (int)$action['user_id'],
                'cohort_id' => (int)$action['cohort_id'],
                'lesson_id' => (int)$action['lesson_id'],
                'progress_test_id' => isset($action['progress_test_id']) ? (int)$action['progress_test_id'] : null,
                'event_type' => 'automation',
                'event_code' => 'automation_dispatch_after',
                'event_status' => 'info',
                'actor_type' => 'system',
                'actor_user_id' => null,
                'event_time' => gmdate('Y-m-d H:i:s'),
                'payload' => [
                    'event_key' => $automationEventKey,
                    'automation_result' => $automationResult,
                ],
                'legal_note' => 'Automation dispatch completed after canonical one-on-one completion commit.',
            ]);
        }

        return [
            'message' => 'Required one-on-one session marked completed.',
            'state' => $this->getInstructorApprovalPageStateByToken((string)$action['token']),
            'projection_result' => $projectionResult,
            'automation_result' => $automationResult,
        ];
    } catch (Throwable $e) {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        throw $e;
    }
}
	
public function processProgressTestItemOverride(
int $progressTestItemId,
array $payload,
int $actorUserId,
string $ipAddress = ‘’,
string $userAgent = ‘’
): array
{
$itemRow = $this->getProgressTestItemWithAttemptContext($progressTestItemId);
if (!$itemRow) {
throw new RuntimeException(‘Progress test item not found.’);
}
$progressTestId = (int)$itemRow['test_id'];
$testRow = $this->getProgressTestRowById($progressTestId);
if (!$testRow) {
    throw new RuntimeException('Parent progress test not found.');
}

$overrideIsCorrect = isset($payload['override_is_correct']) ? (int)$payload['override_is_correct'] : null;
$overrideScorePoints = isset($payload['override_score_points']) ? (int)$payload['override_score_points'] : null;
$overrideReason = trim((string)($payload['override_reason'] ?? ''));

if ($overrideReason === '') {
    throw new RuntimeException('override_reason is required.');
}

if ($overrideIsCorrect !== 0 && $overrideIsCorrect !== 1) {
    throw new RuntimeException('override_is_correct must be 0 or 1.');
}

if ($overrideScorePoints === null) {
    throw new RuntimeException('override_score_points is required.');
}

$maxPoints = (int)($itemRow['max_points'] ?? 0);
if ($overrideScorePoints < 0 || $overrideScorePoints > $maxPoints) {
    throw new RuntimeException('override_score_points must be between 0 and max_points.');
}

$oldOutcome = [
    'score_pct' => (int)($testRow['score_pct'] ?? 0),
    'pass_gate_met' => (int)($testRow['pass_gate_met'] ?? 0),
    'counts_as_unsat' => (int)($testRow['counts_as_unsat'] ?? 0),
    'formal_result_code' => (string)($testRow['formal_result_code'] ?? ''),
    'formal_result_label' => (string)($testRow['formal_result_label'] ?? ''),
    'timing_status' => (string)($testRow['timing_status'] ?? 'unknown'),
];

$automationContext = null;
$automationResult = null;
$projectionResult = null;
$newOutcome = [];

$this->pdo->beginTransaction();

try {
    $insert = $this->pdo->prepare("
        INSERT INTO progress_test_item_score_overrides
        (
            progress_test_item_id,
            overridden_by_user_id,
            original_is_correct,
            original_score_points,
            original_max_points,
            override_is_correct,
            override_score_points,
            override_reason,
            created_at
        )
        VALUES
        (
            :item_id,
            :actor_user_id,
            :orig_correct,
            :orig_points,
            :orig_max,
            :override_correct,
            :override_points,
            :reason,
            :created_at
        )
    ");

    $insert->execute([
        ':item_id' => $progressTestItemId,
        ':actor_user_id' => $actorUserId,
        ':orig_correct' => isset($itemRow['is_correct']) && $itemRow['is_correct'] !== null ? (int)$itemRow['is_correct'] : 0,
        ':orig_points' => isset($itemRow['score_points']) && $itemRow['score_points'] !== null ? (int)$itemRow['score_points'] : 0,
        ':orig_max' => $maxPoints,
        ':override_correct' => $overrideIsCorrect,
        ':override_points' => $overrideScorePoints,
        ':reason' => $overrideReason,
        ':created_at' => gmdate('Y-m-d H:i:s'),
    ]);

    $newOutcome = $this->recomputeProgressTestOutcomeFromItems($progressTestId);

    $updateTest = $this->pdo->prepare("
        UPDATE progress_tests_v2
        SET
            score_pct = :score_pct,
            timing_status = :timing_status,
            pass_gate_met = :pass_gate_met,
            counts_as_unsat = :counts_as_unsat,
            formal_result_code = :formal_result_code,
            formal_result_label = :formal_result_label,
            finalized_by_logic_version = :logic_version,
            updated_at = NOW()
        WHERE id = :id
    ");

    $updateTest->execute([
        ':score_pct' => (int)$newOutcome['score_pct'],
        ':timing_status' => (string)$newOutcome['timing_status'],
        ':pass_gate_met' => (int)$newOutcome['pass_gate_met'],
        ':counts_as_unsat' => (int)$newOutcome['counts_as_unsat'],
        ':formal_result_code' => (string)$newOutcome['formal_result_code'],
        ':formal_result_label' => (string)$newOutcome['formal_result_label'],
        ':logic_version' => self::LOGIC_VERSION,
        ':id' => $progressTestId,
    ]);

    $userId = (int)$testRow['user_id'];
    $cohortId = (int)$testRow['cohort_id'];
    $lessonId = (int)$testRow['lesson_id'];

    $policy = $this->resolveEffectivePolicySet($cohortId);
    $behaviorMode = $this->resolveBehaviorMode($policy);

    $summaryState = $this->resolveSummaryState($userId, $cohortId, $lessonId, $policy);
    $attemptState = $this->resolveAttemptPolicyState(
        $userId,
        $cohortId,
        $lessonId,
        $policy,
        (int)($testRow['attempt'] ?? 1),
        $behaviorMode
    );
    $deadlineState = $this->resolveDeadlineState($userId, $cohortId, $lessonId);
    $activityState = $this->getLessonActivityProjectionRow($userId, $cohortId, $lessonId) ?: [];

    $decision = $this->evaluateProgressionDecision([
        'phase' => 'finalize',
        'user_id' => $userId,
        'cohort_id' => $cohortId,
        'lesson_id' => $lessonId,
        'activity_state' => $activityState,
        'summary_state' => $summaryState,
        'attempt_state' => $attemptState,
        'deadline_state' => $deadlineState,
        'classification' => $newOutcome,
    ]);

    $projection = $this->computeLessonActivityProjection([
        'phase' => 'finalize',
        'user_id' => $userId,
        'cohort_id' => $cohortId,
        'lesson_id' => $lessonId,
        'summary_state' => $summaryState,
        'attempt_state' => $attemptState,
        'deadline_state' => $deadlineState,
        'classification' => $newOutcome,
        'activity_state' => $activityState,
        'required_actions' => [],
        'completed_at' => (string)($testRow['completed_at'] ?: gmdate('Y-m-d H:i:s')),
    ], $decision);

    $projectionResult = $this->persistLessonActivityProjection(
        $userId,
        $cohortId,
        $lessonId,
        $projection
    );

    $this->logProgressionEvent([
        'user_id' => $userId,
        'cohort_id' => $cohortId,
        'lesson_id' => $lessonId,
        'progress_test_id' => $progressTestId,
        'event_type' => 'instructor_intervention',
        'event_code' => 'progress_test_item_override_applied',
        'event_status' => 'info',
        'actor_type' => 'admin',
        'actor_user_id' => $actorUserId,
        'event_time' => gmdate('Y-m-d H:i:s'),
        'payload' => [
            'progress_test_item_id' => $progressTestItemId,
            'original_is_correct' => isset($itemRow['is_correct']) && $itemRow['is_correct'] !== null ? (int)$itemRow['is_correct'] : 0,
            'original_score_points' => isset($itemRow['score_points']) && $itemRow['score_points'] !== null ? (int)$itemRow['score_points'] : 0,
            'override_is_correct' => $overrideIsCorrect,
            'override_score_points' => $overrideScorePoints,
            'override_reason' => $overrideReason,
            'old_score_pct' => (int)$oldOutcome['score_pct'],
            'new_score_pct' => (int)$newOutcome['score_pct'],
            'old_pass_gate_met' => (int)$oldOutcome['pass_gate_met'],
            'new_pass_gate_met' => (int)$newOutcome['pass_gate_met'],
            'old_formal_result_code' => (string)$oldOutcome['formal_result_code'],
            'new_formal_result_code' => (string)$newOutcome['formal_result_code'],
            'projection_result' => $projectionResult,
        ],
        'legal_note' => 'Progress test item override applied and attempt outcome recomputed through CoursewareProgressionV2.',
    ]);

    $automationContext = $this->buildProgressTestOverrideAutomationContext(
        $itemRow,
        [
            'override_is_correct' => $overrideIsCorrect,
            'override_score_points' => $overrideScorePoints,
            'override_reason' => $overrideReason,
        ],
        $oldOutcome,
        $newOutcome
    );

    $this->pdo->commit();
} catch (Throwable $e) {
    if ($this->pdo->inTransaction()) {
        $this->pdo->rollBack();
    }
    throw $e;
}

if (is_file(__DIR__ . '/automation_runtime.php')) {
    require_once __DIR__ . '/automation_runtime.php';
    $automation = new AutomationRuntime();

    $this->logProgressionEvent([
        'user_id' => (int)$testRow['user_id'],
        'cohort_id' => (int)$testRow['cohort_id'],
        'lesson_id' => (int)$testRow['lesson_id'],
        'progress_test_id' => $progressTestId,
        'event_type' => 'automation',
        'event_code' => 'automation_dispatch_before',
        'event_status' => 'info',
        'actor_type' => 'system',
        'actor_user_id' => null,
        'event_time' => gmdate('Y-m-d H:i:s'),
        'payload' => [
            'event_key' => 'progress_test_item_override_applied',
            'progress_test_item_id' => $progressTestItemId,
        ],
        'legal_note' => 'Automation dispatch starting after canonical progress test item override commit.',
    ]);

    $automationResult = $automation->dispatchEvent(
        $this->pdo,
        'progress_test_item_override_applied',
        $automationContext
    );

    $this->logProgressionEvent([
        'user_id' => (int)$testRow['user_id'],
        'cohort_id' => (int)$testRow['cohort_id'],
        'lesson_id' => (int)$testRow['lesson_id'],
        'progress_test_id' => $progressTestId,
        'event_type' => 'automation',
        'event_code' => 'automation_dispatch_after',
        'event_status' => 'info',
        'actor_type' => 'system',
        'actor_user_id' => null,
        'event_time' => gmdate('Y-m-d H:i:s'),
        'payload' => [
            'event_key' => 'progress_test_item_override_applied',
            'automation_result' => $automationResult,
        ],
        'legal_note' => 'Automation dispatch completed after canonical progress test item override commit.',
    ]);
}

return [
    'ok' => true,
    'message' => 'Override applied and progression recalculated.',
    'progress_test_id' => $progressTestId,
    'progress_test_item_id' => $progressTestItemId,
    'old_outcome' => $oldOutcome,
    'new_outcome' => $newOutcome,
    'projection_result' => $projectionResult,
    'automation_result' => $automationResult,
];	
	
private function getProgressTestItemWithAttemptContext(int $progressTestItemId): ?array
{
$stmt = $this->pdo->prepare(”
SELECT
pti.*,
pt.id AS test_id,
pt.user_id,
pt.cohort_id,
pt.lesson_id,
pt.attempt,
pt.completed_at,
pt.score_pct AS test_score_pct,
pt.pass_gate_met AS test_pass_gate_met,
pt.counts_as_unsat AS test_counts_as_unsat,
pt.formal_result_code AS test_formal_result_code,
pt.formal_result_label AS test_formal_result_label,
pt.timing_status AS test_timing_status
FROM progress_test_items_v2 pti
INNER JOIN progress_tests_v2 pt
ON pt.id = pti.test_id
WHERE pti.id = :id
LIMIT 1
“);
$stmt->execute([
‘:id’ => $progressTestItemId,
]);	
	
$row = $stmt->fetch();
return $row ?: null;
	
}

private function getLatestOverrideRowForProgressTestItem(int $progressTestItemId): ?array
{
$stmt = $this->pdo->prepare(”
SELECT *
FROM progress_test_item_score_overrides
WHERE progress_test_item_id = :progress_test_item_id
ORDER BY id DESC
LIMIT 1
“);
$stmt->execute([
‘:progress_test_item_id’ => $progressTestItemId,
]);
	
$row = $stmt->fetch();
return $row ?: null;
	
}

private function recomputeProgressTestOutcomeFromItems(int $progressTestId): array
{
$stmt = $this->pdo->prepare(”
SELECT
pti.id,
pti.is_correct,
pti.score_points,
pti.max_points,
ov.override_is_correct,
ov.override_score_points
FROM progress_test_items_v2 pti
LEFT JOIN progress_test_item_score_overrides ov
ON ov.id = (
SELECT ov2.id
FROM progress_test_item_score_overrides ov2
WHERE ov2.progress_test_item_id = pti.id
ORDER BY ov2.id DESC
LIMIT 1
)
WHERE pti.test_id = :test_id
ORDER BY pti.idx ASC, pti.id ASC
“);
$stmt->execute([
‘:test_id’ => $progressTestId,
]);
	
$rows = $stmt->fetchAll();

$total = 0;
$max = 0;

foreach ($rows as $r) {
    $points = $r['override_score_points'] !== null
        ? (int)$r['override_score_points']
        : (int)$r['score_points'];

    $total += $points;
    $max += (int)$r['max_points'];
}

$scorePct = $max > 0 ? (int)round(($total / $max) * 100) : 0;

$testRow = $this->getProgressTestRowById($progressTestId);
if (!$testRow) {
    throw new RuntimeException('Progress test not found during recompute.');
}

$policy = $this->resolveEffectivePolicySet((int)$testRow['cohort_id']);
$behaviorMode = $this->resolveBehaviorMode($policy);

$classificationInput = $testRow;
$classificationInput['completed_at'] = (string)($testRow['completed_at'] ?: gmdate('Y-m-d H:i:s'));

$classification = $this->classifyProgressTestResult(
    $classificationInput,
    $scorePct,
    $policy,
    $behaviorMode
);

return [
    'score_pct' => $scorePct,
    'timing_status' => (string)($classification['timing_status'] ?? 'unknown'),
    'pass_gate_met' => (int)($classification['pass_gate_met'] ?? 0),
    'counts_as_unsat' => (int)($classification['counts_as_unsat'] ?? 0),
    'formal_result_code' => (string)($classification['formal_result_code'] ?? ''),
    'formal_result_label' => (string)($classification['formal_result_label'] ?? ''),
];
	
}

private function buildProgressTestOverrideAutomationContext(
array $itemRow,
array $overridePayload,
array $oldOutcome,
array $newOutcome
): array
{
$progressTestId = (int)($itemRow[‘test_id’] ?? 0);
$userId = (int)($itemRow[‘user_id’] ?? 0);
$cohortId = (int)($itemRow[‘cohort_id’] ?? 0);
$lessonId = (int)($itemRow[‘lesson_id’] ?? 0);	
	
$progressionContext = $this->getProgressionContextForUserLesson($userId, $cohortId, $lessonId);
$studentRecipient = (array)($progressionContext['student_recipient'] ?? []);
$chiefRecipient = (array)($progressionContext['chief_instructor_recipient'] ?? []);

$studentName = trim((string)($studentRecipient['name'] ?? ''));
if ($studentName === '') {
    $studentName = 'Student';
}

$chiefName = trim((string)($chiefRecipient['name'] ?? ''));
if ($chiefName === '') {
    $chiefName = 'Chief Instructor';
}

return [
    'user_id' => $userId,
    'cohort_id' => $cohortId,
    'lesson_id' => $lessonId,
    'progress_test_id' => $progressTestId,
    'progress_test_item_id' => (int)($itemRow['id'] ?? 0),

    'student_name' => $studentName,
    'student_email' => trim((string)($studentRecipient['email'] ?? '')),
    'chief_instructor_name' => $chiefName,
    'chief_instructor_email' => trim((string)($chiefRecipient['email'] ?? '')),

    'lesson_title' => (string)($progressionContext['lesson_title'] ?? ''),
    'cohort_title' => (string)($progressionContext['cohort_title'] ?? ''),

    'attempt_count' => (string)((int)($itemRow['attempt'] ?? 0)),

    'original_is_correct' => isset($itemRow['is_correct']) && $itemRow['is_correct'] !== null ? (int)$itemRow['is_correct'] : 0,
    'original_score_points' => isset($itemRow['score_points']) && $itemRow['score_points'] !== null ? (int)$itemRow['score_points'] : 0,
    'original_max_points' => (int)($itemRow['max_points'] ?? 0),

    'override_is_correct' => (int)($overridePayload['override_is_correct'] ?? 0),
    'override_score_points' => (int)($overridePayload['override_score_points'] ?? 0),
    'override_reason' => trim((string)($overridePayload['override_reason'] ?? '')),

    'old_score_pct' => (int)($oldOutcome['score_pct'] ?? 0),
    'new_score_pct' => (int)($newOutcome['score_pct'] ?? 0),
    'old_pass_gate_met' => (int)($oldOutcome['pass_gate_met'] ?? 0),
    'new_pass_gate_met' => (int)($newOutcome['pass_gate_met'] ?? 0),
    'old_counts_as_unsat' => (int)($oldOutcome['counts_as_unsat'] ?? 0),
    'new_counts_as_unsat' => (int)($newOutcome['counts_as_unsat'] ?? 0),
    'old_formal_result_code' => (string)($oldOutcome['formal_result_code'] ?? ''),
    'new_formal_result_code' => (string)($newOutcome['formal_result_code'] ?? ''),
    'old_formal_result_label' => (string)($oldOutcome['formal_result_label'] ?? ''),
    'new_formal_result_label' => (string)($newOutcome['formal_result_label'] ?? ''),
    'old_timing_status' => (string)($oldOutcome['timing_status'] ?? ''),
    'new_timing_status' => (string)($newOutcome['timing_status'] ?? ''),
];
	
}	
	
	
	
    public function completeRequiredAction(int $actionId, string $responseText, ?string $ipAddress = null, ?string $userAgent = null): void
    {
        $sql = "
            UPDATE student_required_actions
            SET
                status = 'completed',
                student_response_text = :student_response_text,
                completed_at = :completed_at,
                ip_address = COALESCE(:ip_address, ip_address),
                user_agent = COALESCE(:user_agent, user_agent),
                updated_at = :updated_at
            WHERE id = :id
              AND status IN ('pending','opened')
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':student_response_text' => $responseText,
            ':completed_at' => gmdate('Y-m-d H:i:s'),
            ':ip_address' => $ipAddress,
            ':user_agent' => $userAgent,
            ':updated_at' => gmdate('Y-m-d H:i:s'),
            ':id' => $actionId,
        ]);
    }

    public function hasCompletedRequiredAction(int $userId, int $cohortId, int $lessonId, string $actionType): bool
    {
        $sql = "
            SELECT 1
            FROM student_required_actions
            WHERE user_id = :user_id
              AND cohort_id = :cohort_id
              AND lesson_id = :lesson_id
              AND action_type = :action_type
              AND status IN ('completed','approved')
            LIMIT 1
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':user_id' => $userId,
            ':cohort_id' => $cohortId,
            ':lesson_id' => $lessonId,
            ':action_type' => $actionType,
        ]);

        return (bool)$stmt->fetchColumn();
    }

    public function createOrReuseRequiredActionSafe(array $data): array
    {
        $existing = $this->getPendingRequiredAction(
            (int)$data['user_id'],
            (int)$data['cohort_id'],
            (int)$data['lesson_id'],
            (string)$data['action_type']
        );

        if ($existing) {
            return [
                'action_id' => (int)$existing['id'],
                'action' => $existing,
                'created_new' => false,
            ];
        }

        $actionId = $this->createRequiredAction($data);
        $action = $this->getRequiredActionById($actionId);

        return [
            'action_id' => $actionId,
            'action' => $action ?: [],
            'created_new' => true,
        ];
    }


public function createRequiredAction(array $data): int
{
    $required = [
        'user_id',
        'cohort_id',
        'lesson_id',
        'action_type',
        'token',
        'title',
    ];

    foreach ($required as $field) {
        if (!array_key_exists($field, $data)) {
            throw new InvalidArgumentException("Missing required action field: {$field}");
        }
    }

    $sql = "
        INSERT INTO student_required_actions
        (
            user_id,
            cohort_id,
            lesson_id,
            progress_test_id,
            action_type,
            token,
            status,
            title,
            instructions_html,
            instructions_text,
            student_response_text,
            email_id,
            opened_at,
            completed_at,
            approved_at,
            ip_address,
            user_agent,
            created_at,
            updated_at
        )
        VALUES
        (
            :user_id,
            :cohort_id,
            :lesson_id,
            :progress_test_id,
            :action_type,
            :token,
            :status,
            :title,
            :instructions_html,
            :instructions_text,
            :student_response_text,
            :email_id,
            :opened_at,
            :completed_at,
            :approved_at,
            :ip_address,
            :user_agent,
            :created_at,
            :updated_at
        )
    ";

    $stmt = $this->pdo->prepare($sql);
    $stmt->execute([
        ':user_id' => (int)$data['user_id'],
        ':cohort_id' => (int)$data['cohort_id'],
        ':lesson_id' => (int)$data['lesson_id'],
        ':progress_test_id' => isset($data['progress_test_id']) ? (int)$data['progress_test_id'] : null,
        ':action_type' => (string)$data['action_type'],
        ':token' => (string)$data['token'],
        ':status' => (string)($data['status'] ?? 'pending'),
        ':title' => (string)$data['title'],
        ':instructions_html' => isset($data['instructions_html']) ? (string)$data['instructions_html'] : null,
        ':instructions_text' => isset($data['instructions_text']) ? (string)$data['instructions_text'] : null,
        ':student_response_text' => isset($data['student_response_text']) ? (string)$data['student_response_text'] : null,
        ':email_id' => isset($data['related_email_id']) ? (int)$data['related_email_id'] : null,
        ':opened_at' => isset($data['opened_at']) ? (string)$data['opened_at'] : null,
        ':completed_at' => isset($data['completed_at']) ? (string)$data['completed_at'] : null,
        ':approved_at' => isset($data['approved_at']) ? (string)$data['approved_at'] : null,
        ':ip_address' => isset($data['ip_address']) ? (string)$data['ip_address'] : null,
        ':user_agent' => isset($data['user_agent']) ? (string)$data['user_agent'] : null,
        ':created_at' => (string)($data['created_at'] ?? gmdate('Y-m-d H:i:s')),
        ':updated_at' => (string)($data['updated_at'] ?? gmdate('Y-m-d H:i:s')),
    ]);

    return (int)$this->pdo->lastInsertId();
}	
	
public function ensureRequiredActionsForDecision(int $progressTestId, array $decision): array
{
    $test = $this->getProgressTestRowById($progressTestId);
    if (!$test) {
        throw new RuntimeException('Progress test not found.');
    }

    $result = [
        'should_create_any' => false,
        'actions' => [],
        'latest_instructor_action_id' => null,
    ];

    $requiredActionDecision = (array)($decision['required_action_decision'] ?? []);
    if (empty($requiredActionDecision['should_create_any'])) {
        return $result;
    }

    $result['should_create_any'] = true;

    foreach ((array)($requiredActionDecision['action_types'] ?? []) as $actionType) {
        $token = bin2hex(random_bytes(32));
        $lessonTitle = $this->getLessonTitle((int)$test['lesson_id']);

        $title = $actionType === 'instructor_approval'
            ? 'Instructor Approval Required - ' . $lessonTitle
            : 'Remedial Study Acknowledgement - ' . $lessonTitle;

        $actionData = [
            'user_id' => (int)$test['user_id'],
            'cohort_id' => (int)$test['cohort_id'],
            'lesson_id' => (int)$test['lesson_id'],
            'progress_test_id' => $progressTestId,
            'action_type' => (string)$actionType,
            'token' => $token,
            'title' => $title,
        ];

        if ($actionType === 'remediation_acknowledgement') {
            $weakAreasText = trim((string)($test['weak_areas'] ?? ''));
            $debriefText = trim((string)($test['ai_summary'] ?? ''));

            $instructionsText = "Please review the following before confirming your remedial study:\n\n";

            if ($weakAreasText !== '') {
                $instructionsText .= "Weak areas to review:\n" . $weakAreasText . "\n\n";
            }

            if ($debriefText !== '') {
                $instructionsText .= "Debrief summary:\n" . $debriefText . "\n\n";
            }

            $instructionsText .= 'After reviewing the material for lesson "' . $lessonTitle . '", confirm below that you completed the required remedial study.';

            $instructionsHtml = '<div>';
            $instructionsHtml .= '<p>Please review the following before confirming your remedial study:</p>';

            if ($weakAreasText !== '') {
                $instructionsHtml .= '<h3 style="margin:14px 0 6px 0;">Weak areas to review</h3>';
                $instructionsHtml .= '<div>' . nl2br($this->escapeHtml($weakAreasText)) . '</div>';
            }

            if ($debriefText !== '') {
                $instructionsHtml .= '<h3 style="margin:14px 0 6px 0;">Debrief summary</h3>';
                $instructionsHtml .= '<div>' . nl2br($this->escapeHtml($debriefText)) . '</div>';
            }

            $instructionsHtml .= '<p style="margin-top:14px;">After reviewing the material for lesson <strong>'
                . $this->escapeHtml($lessonTitle)
                . '</strong>, confirm below that you completed the required remedial study.</p>';
            $instructionsHtml .= '</div>';

            $actionData['instructions_text'] = $instructionsText;
            $actionData['instructions_html'] = $instructionsHtml;
        }

        $action = $this->createOrReuseRequiredActionSafe($actionData);

        $actionToken = (string)(($action['action']['token'] ?? '') ?: $token);
        $actionUrl = $actionType === 'instructor_approval'
            ? $this->buildInternalAppUrl('/instructor/instructor_approval.php?token=' . urlencode($actionToken))
            : $this->buildInternalAppUrl('/student/remediation_action.php?token=' . urlencode($actionToken));

        $action['action_url'] = $actionUrl;
        $result['actions'][] = $action;

        if ($actionType === 'instructor_approval') {
            $result['latest_instructor_action_id'] = (int)$action['action_id'];
        }
    }

    return $result;
}	
	
	

public function buildNotificationDecision(array $progressionContext, array $decision, array $context = []): array
{
    $behaviorMode = (array)($context['behavior_mode'] ?? []);
    $strategy = $this->resolveNotificationKeyStrategy($behaviorMode, $decision);

    $notifications = [];
    $priority = (string)($decision['priority_state'] ?? 'normal');
    $map = (array)($strategy['active_map'] ?? []);
    $requiredActions = (array)($context['required_actions'] ?? []);

    $remediationUrl = '';
    $approvalUrl = '';

    foreach ((array)($requiredActions['actions'] ?? []) as $actionItem) {
        $actionType = (string)($actionItem['action']['action_type'] ?? '');
        if ($actionType === 'remediation_acknowledgement') {
            $remediationUrl = (string)($actionItem['action_url'] ?? '');
        } elseif ($actionType === 'instructor_approval') {
            $approvalUrl = (string)($actionItem['action_url'] ?? '');
        }
    }

    $studentName = (string)(($progressionContext['student_recipient']['name'] ?? '') ?: 'Student');
    $lessonTitle = (string)($progressionContext['lesson_title'] ?? '');
    $cohortTitle = (string)($progressionContext['cohort_title'] ?? '');

    $attempt = (int)($context['attempt'] ?? 0);
    $scorePct = (int)($context['score_pct'] ?? 0);
    $weakAreasText = (string)($context['weak_areas'] ?? '');
    $writtenDebriefText = (string)($context['ai_summary'] ?? '');
    $formalResultCode = (string)(($context['classification']['formal_result_code'] ?? '') ?: '');

    if (isset($map[$priority])) {
        foreach ((array)$map[$priority] as $item) {
            $audience = (string)$item['audience'];
            $recipient = null;

            if ($audience === 'student') {
                $recipient = $progressionContext['student_recipient'] ?? null;
            } elseif ($audience === 'chief_instructor') {
                $recipient = $progressionContext['chief_instructor_recipient'] ?? null;
            }

            if (!$recipient) {
                continue;
            }

            $notificationContext = [
                'student_name' => $studentName,
                'lesson_title' => $lessonTitle,
                'cohort_title' => $cohortTitle,
                'attempt_count' => (string)$attempt,
                'score_pct' => (string)$scorePct,
                'weak_areas_text' => $weakAreasText,
                'written_debrief_text' => $writtenDebriefText,
                'decision_code' => $formalResultCode,
                'remediation_url' => $remediationUrl,
                'approval_url' => $approvalUrl,
                'summary_quality' => (string)($context['summary_quality'] ?? ''),
                'summary_issues' => (string)($context['summary_issues'] ?? ''),
                'summary_corrections' => (string)($context['summary_corrections'] ?? ''),
                'confirmed_misunderstandings' => (string)($context['confirmed_misunderstandings'] ?? ''),
            ];

            $notifications[] = [
                'audience' => $audience,
                'notification_key' => (string)$item['notification_key'],
                'email_type' => (string)$item['notification_key'],
                'recipient' => $recipient,
                'context' => $notificationContext,
            ];
        }
    }

    return [
        'should_notify' => !empty($notifications),
        'notifications' => $notifications,
        'active_key_map_name' => $strategy['active_key_map_name'],
        'fallback_map_used' => $strategy['fallback_map_used'],
        'notes' => $strategy['notes'],
    ];
}

    public function resolveNotificationKeyStrategy(array $behaviorMode, array $decision): array
    {
        $canonicalKeyMap = [
            'training_suspended' => [],
            'deadline_blocked' => [],
            'instructor_required' => [],
            'remediation_required' => [],
            'normal' => [],
        ];

        $liveCompatibleKeyMap = [
            'training_suspended' => [],
            'deadline_blocked' => [],
            'instructor_required' => [
                ['audience' => 'student', 'notification_key' => 'instructor_approval_required'],
                ['audience' => 'chief_instructor', 'notification_key' => 'instructor_approval_required_chief'],
            ],
            'remediation_required' => [
                ['audience' => 'student', 'notification_key' => 'third_fail_remediation'],
            ],
            'normal' => [],
        ];

        $useLive = true;

        return [
            'canonical_key_map' => $canonicalKeyMap,
            'live_compatible_key_map' => $liveCompatibleKeyMap,
            'active_map' => $useLive ? $liveCompatibleKeyMap : $canonicalKeyMap,
            'active_key_map_name' => $useLive ? 'live_compatible_key_map' : 'canonical_key_map',
            'fallback_map_used' => $useLive ? ['notification_keys' => 'live_compatible'] : [],
            'notes' => $useLive
                ? 'Using migration-safe live-compatible notification keys.'
                : 'Using canonical notification keys.',
        ];
    }

    public function getProgressionContextForUserLesson(int $userId, int $cohortId, int $lessonId): array
    {
        return [
            'user_id' => $userId,
            'cohort_id' => $cohortId,
            'lesson_id' => $lessonId,
            'lesson_title' => $this->getLessonTitle($lessonId),
            'cohort_title' => $this->getCohortTitle($cohortId),
            'student_recipient' => $this->getUserRecipient($userId),
            'chief_instructor_recipient' => $this->getChiefInstructorRecipient(['cohort_id' => $cohortId]),
            'activity_state' => $this->getLessonActivityProjectionRow($userId, $cohortId, $lessonId) ?? [],
            'latest_progress_test' => $this->getLatestProgressTestRowForLesson($userId, $cohortId, $lessonId),
            'summary' => $this->getLessonSummaryRow($userId, $cohortId, $lessonId),
        ];
    }

    public function getSummaryReviewPageState(array $requestContext): array
    {
        $resolved = $this->resolveSummaryReviewRequestContext($requestContext);
        $context = $this->getProgressionContextForUserLesson($resolved['user_id'], $resolved['cohort_id'], $resolved['lesson_id']);
        $summary = $this->getLessonSummaryRow($resolved['user_id'], $resolved['cohort_id'], $resolved['lesson_id']);

        if (!$summary) {
            throw new RuntimeException('Lesson summary not found.');
        }

        $summaryHtml = trim((string)($summary['summary_html'] ?? ''));
        $summaryText = trim((string)($summary['summary_plain'] ?? ''));
        $summaryStatus = trim((string)($summary['review_status'] ?? 'pending'));
        $reviewStatus = $summaryStatus;
        $selectedDecision = $summaryStatus === 'acceptable' ? 'approve' : ($summaryStatus === 'needs_revision' ? 'needs_revision' : '');
        $reviewNotes = isset($requestContext['post']['review_notes']) ? trim((string)$requestContext['post']['review_notes']) : '';
        $allowAi = isset($requestContext['post']['allow_ai_helper_context']) ? !empty($requestContext['post']['allow_ai_helper_context']) : false;

        $studentName = (string)(($context['student_recipient']['name'] ?? '') ?: 'Student');
        $backUrl = '/instructor/summary_review.php?user_id=' . urlencode((string)$resolved['user_id']) . '&cohort_id=' . urlencode((string)$resolved['cohort_id']) . '&lesson_id=' . urlencode((string)$resolved['lesson_id']);

        return [
            'page_title' => 'Summary Review',
            'page_subtitle' => 'Review and decide the student lesson summary.',
            'student_name' => $studentName,
            'lesson_title' => $context['lesson_title'],
            'cohort_title' => $context['cohort_title'],
            'summary_html' => $summaryHtml,
            'summary_text' => $summaryText,
            'summary_status' => $summaryStatus,
            'review_status' => $reviewStatus,
            'can_submit' => true,
            'is_read_only' => false,
            'back_url' => $backUrl,
            'meta_rows' => [
                ['label' => 'Student', 'value' => $studentName],
                ['label' => 'Lesson', 'value' => $context['lesson_title']],
                ['label' => 'Cohort', 'value' => $context['cohort_title']],
                ['label' => 'Summary status', 'value' => $summaryStatus],
            ],
            'decision_options' => [
                ['value' => 'approve', 'label' => 'Approve summary'],
                ['value' => 'needs_revision', 'label' => 'Needs revision'],
            ],
            'selected_decision' => $selectedDecision,
            'review_notes' => $reviewNotes,
            'ai_helper' => [
                'enabled' => $allowAi,
                'allow_context' => $allowAi ? 1 : 0,
                'allow_ai_helper_context' => $allowAi ? 1 : 0,
                'message' => $allowAi ? 'AI helper enabled.' : 'AI helper disabled.',
            ],
            'summary' => $summary,
            'progression_context' => $context,
            'latest_progress_test' => $context['latest_progress_test'],
        ];
    }

    public function processInstructorSummaryReviewDecision(array $requestContext, array $decisionPayload): array
    {
        $resolved = $this->resolveSummaryReviewRequestContext($requestContext);

        $decision = trim((string)($decisionPayload['decision'] ?? ''));
        $reviewNotes = trim((string)($decisionPayload['review_notes'] ?? ''));
        $allowAiHelperContext = !empty($decisionPayload['allow_ai_helper_context']);

        if (!in_array($decision, ['approve', 'needs_revision'], true)) {
            throw new RuntimeException('Invalid decision.');
        }

        $mappedReviewStatus = $decision === 'approve' ? 'acceptable' : 'needs_revision';

        $this->pdo->beginTransaction();

        try {
            $stmt = $this->pdo->prepare("
                UPDATE lesson_summaries
                SET review_status = :review_status,
                    updated_at = NOW()
                WHERE user_id = :user_id
                  AND cohort_id = :cohort_id
                  AND lesson_id = :lesson_id
            ");
            $stmt->execute([
                ':review_status' => $mappedReviewStatus,
                ':user_id' => $resolved['user_id'],
                ':cohort_id' => $resolved['cohort_id'],
                ':lesson_id' => $resolved['lesson_id'],
            ]);

			$latestTest = $this->getLatestProgressTestRowForLesson(
				$resolved['user_id'],
				$resolved['cohort_id'],
				$resolved['lesson_id']
			);

			$completionStatus = 'awaiting_summary_review';

			if ($mappedReviewStatus === 'acceptable') {
				if ($latestTest && !empty($latestTest['pass_gate_met'])) {
					$completionStatus = 'completed';
				} else {
					$completionStatus = 'in_progress';
				}
			}

            $projection = [
                'engine_projection' => true,
                'user_id' => $resolved['user_id'],
                'cohort_id' => $resolved['cohort_id'],
                'lesson_id' => $resolved['lesson_id'],
                'phase' => 'summary_review_decision',
                'fields' => [
                    'summary_status' => $mappedReviewStatus,
                    'completion_status' => $completionStatus,
                    'last_state_eval_at' => gmdate('Y-m-d H:i:s'),
                    'completed_at' => $completionStatus === 'completed' ? gmdate('Y-m-d H:i:s') : null,
                ],
            ];

            $projectionResult = $this->persistLessonActivityProjection(
                $resolved['user_id'],
                $resolved['cohort_id'],
                $resolved['lesson_id'],
                $projection
            );

            $this->logProgressionEvent([
                'user_id' => $resolved['user_id'],
                'cohort_id' => $resolved['cohort_id'],
                'lesson_id' => $resolved['lesson_id'],
                'progress_test_id' => $latestTest ? (int)$latestTest['id'] : null,
                'event_type' => 'summary_review',
                'event_code' => 'summary_review_decision_recorded',
                'event_status' => 'info',
                'actor_type' => 'admin',
                'actor_user_id' => $resolved['actor_user_id'],
                'event_time' => gmdate('Y-m-d H:i:s'),
                'payload' => [
                    'decision' => $decision,
                    'review_status' => $mappedReviewStatus,
                    'review_notes' => $reviewNotes,
                    'allow_ai_helper_context' => $allowAiHelperContext ? 1 : 0,
                    'projection_result' => $projectionResult,
                ],
                'legal_note' => 'Summary review decision recorded through CoursewareProgressionV2.',
            ]);

            $this->pdo->commit();

            return [
                'message' => $mappedReviewStatus === 'acceptable'
                    ? 'Summary approved successfully.'
                    : 'Summary marked as needs revision.',
                'state' => $this->getSummaryReviewPageState([
                    'user_id' => $resolved['user_id'],
                    'cohort_id' => $resolved['cohort_id'],
                    'lesson_id' => $resolved['lesson_id'],
                    'actor_user_id' => $resolved['actor_user_id'],
                    'post' => [
                        'review_notes' => $reviewNotes,
                        'allow_ai_helper_context' => $allowAiHelperContext ? '1' : '0',
                    ],
                ]),
            ];
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    private function resolveSummaryReviewRequestContext(array $requestContext): array
    {
        $get = isset($requestContext['get']) && is_array($requestContext['get']) ? $requestContext['get'] : [];
        $post = isset($requestContext['post']) && is_array($requestContext['post']) ? $requestContext['post'] : [];

        $userId = (int)($requestContext['user_id'] ?? $get['user_id'] ?? $post['user_id'] ?? 0);
        $cohortId = (int)($requestContext['cohort_id'] ?? $get['cohort_id'] ?? $post['cohort_id'] ?? 0);
        $lessonId = (int)($requestContext['lesson_id'] ?? $get['lesson_id'] ?? $post['lesson_id'] ?? 0);
        $actorUserId = (int)($requestContext['actor_user_id'] ?? 0);
        $actorRole = (string)($requestContext['actor_role'] ?? '');
        $ipAddress = (string)($requestContext['ip_address'] ?? '');
        $userAgent = (string)($requestContext['user_agent'] ?? '');

        if ($userId <= 0 || $cohortId <= 0 || $lessonId <= 0) {
            throw new RuntimeException('Invalid summary review request context.');
        }

        return [
            'user_id' => $userId,
            'cohort_id' => $cohortId,
            'lesson_id' => $lessonId,
            'actor_user_id' => $actorUserId,
            'actor_role' => $actorRole,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'get' => $get,
            'post' => $post,
        ];
    }


    private function dispatchAutomationEventIfAvailable(
        string $eventKey,
        array $automationContext,
        ?int $userId = null,
        ?int $cohortId = null,
        ?int $lessonId = null,
        ?int $progressTestId = null
    ): ?array {
        if ($eventKey === '' || !is_file(__DIR__ . '/automation_runtime.php')) {
            return null;
        }

        require_once __DIR__ . '/automation_runtime.php';

        $eventTime = gmdate('Y-m-d H:i:s');

        $this->logProgressionEvent([
            'user_id' => (int)($userId ?? ($automationContext['user_id'] ?? 0)),
            'cohort_id' => (int)($cohortId ?? ($automationContext['cohort_id'] ?? 0)),
            'lesson_id' => (int)($lessonId ?? ($automationContext['lesson_id'] ?? 0)),
            'progress_test_id' => $progressTestId ?? (isset($automationContext['progress_test_id']) ? (int)$automationContext['progress_test_id'] : null),
            'event_type' => 'automation',
            'event_code' => 'automation_dispatch_before',
            'event_status' => 'info',
            'actor_type' => 'system',
            'actor_user_id' => null,
            'event_time' => $eventTime,
            'payload' => [
                'event_key' => $eventKey,
                'source' => 'courseware_progression_v2',
            ],
        ]);

        $automation = new AutomationRuntime();
        $result = $automation->dispatchEvent($this->pdo, $eventKey, $automationContext);

        $this->logProgressionEvent([
            'user_id' => (int)($userId ?? ($automationContext['user_id'] ?? 0)),
            'cohort_id' => (int)($cohortId ?? ($automationContext['cohort_id'] ?? 0)),
            'lesson_id' => (int)($lessonId ?? ($automationContext['lesson_id'] ?? 0)),
            'progress_test_id' => $progressTestId ?? (isset($automationContext['progress_test_id']) ? (int)$automationContext['progress_test_id'] : null),
            'event_type' => 'automation',
            'event_code' => 'automation_dispatch_after',
            'event_status' => 'info',
            'actor_type' => 'system',
            'actor_user_id' => null,
            'event_time' => gmdate('Y-m-d H:i:s'),
            'payload' => [
                'event_key' => $eventKey,
                'automation_result' => $result,
            ],
        ]);

        return $result;
    }
	
	
	
	    private function countUnsatResultsForLesson(int $userId, int $cohortId, int $lessonId): int
    {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*)
            FROM progress_tests_v2
            WHERE user_id = :user_id
              AND cohort_id = :cohort_id
              AND lesson_id = :lesson_id
              AND counts_as_unsat = 1
              AND status = 'completed'
        ");
        $stmt->execute([
            ':user_id' => $userId,
            ':cohort_id' => $cohortId,
            ':lesson_id' => $lessonId,
        ]);

        return (int)$stmt->fetchColumn();
    }

    private function countUnsatResultsCoursewide(int $userId, int $windowDays): int
    {
        if ($windowDays <= 0) {
            $windowDays = 30;
        }

        $stmt = $this->pdo->prepare("
            SELECT COUNT(*)
            FROM progress_tests_v2
            WHERE user_id = :user_id
              AND counts_as_unsat = 1
              AND status = 'completed'
              AND completed_at IS NOT NULL
              AND completed_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL :window_days DAY)
        ");
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':window_days', $windowDays, PDO::PARAM_INT);
        $stmt->execute();

        return (int)$stmt->fetchColumn();
    }
	
	
	
    public function logProgressionEvent(array $event): int
    {
        $requiredFields = [
            'user_id',
            'cohort_id',
            'lesson_id',
            'event_type',
            'event_code',
        ];

        foreach ($requiredFields as $field) {
            if (!array_key_exists($field, $event)) {
                throw new InvalidArgumentException("Missing required event field: {$field}");
            }
        }

        $sql = "
            INSERT INTO training_progression_events (
                user_id,
                cohort_id,
                lesson_id,
                progress_test_id,
                event_type,
                event_code,
                event_status,
                actor_type,
                actor_user_id,
                event_time,
                payload_json,
                legal_note
            ) VALUES (
                :user_id,
                :cohort_id,
                :lesson_id,
                :progress_test_id,
                :event_type,
                :event_code,
                :event_status,
                :actor_type,
                :actor_user_id,
                :event_time,
                :payload_json,
                :legal_note
            )
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':user_id' => (int)$event['user_id'],
            ':cohort_id' => (int)$event['cohort_id'],
            ':lesson_id' => (int)$event['lesson_id'],
            ':progress_test_id' => isset($event['progress_test_id']) ? (int)$event['progress_test_id'] : null,
            ':event_type' => (string)$event['event_type'],
            ':event_code' => (string)$event['event_code'],
            ':event_status' => (string)($event['event_status'] ?? 'info'),
            ':actor_type' => (string)($event['actor_type'] ?? 'system'),
            ':actor_user_id' => isset($event['actor_user_id']) ? (int)$event['actor_user_id'] : null,
            ':event_time' => (string)($event['event_time'] ?? gmdate('Y-m-d H:i:s')),
            ':payload_json' => isset($event['payload'])
                ? json_encode($event['payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
                : null,
            ':legal_note' => isset($event['legal_note']) ? (string)$event['legal_note'] : null,
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    public function queueProgressionEmail(array $email): int
    {
        $requiredFields = [
            'user_id',
            'cohort_id',
            'lesson_id',
            'email_type',
            'recipients_to',
            'subject',
            'body_html',
        ];

        foreach ($requiredFields as $field) {
            if (!array_key_exists($field, $email)) {
                throw new InvalidArgumentException("Missing required email field: {$field}");
            }
        }

        $renderContextJson = null;
        if (array_key_exists('render_context_json', $email)) {
            $renderContextJson = $email['render_context_json'];
        } elseif (array_key_exists('render_context', $email)) {
            $renderContextJson = json_encode(
                $email['render_context'],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );
        }

        $sql = "
            INSERT INTO training_progression_emails (
                user_id,
                cohort_id,
                lesson_id,
                progress_test_id,
                email_type,
                recipients_to,
                recipients_cc,
                subject,
                body_html,
                body_text,
                ai_inputs_json,
                sent_status,
                sent_at,
                created_at,
                notification_template_id,
                notification_template_version_id,
                render_context_json
            ) VALUES (
                :user_id,
                :cohort_id,
                :lesson_id,
                :progress_test_id,
                :email_type,
                :recipients_to,
                :recipients_cc,
                :subject,
                :body_html,
                :body_text,
                :ai_inputs_json,
                :sent_status,
                :sent_at,
                :created_at,
                :notification_template_id,
                :notification_template_version_id,
                :render_context_json
            )
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':user_id' => (int)$email['user_id'],
            ':cohort_id' => (int)$email['cohort_id'],
            ':lesson_id' => (int)$email['lesson_id'],
            ':progress_test_id' => isset($email['progress_test_id']) ? (int)$email['progress_test_id'] : null,
            ':email_type' => (string)$email['email_type'],
            ':recipients_to' => $this->encodeMixedField($email['recipients_to']),
            ':recipients_cc' => array_key_exists('recipients_cc', $email)
                ? $this->encodeMixedField($email['recipients_cc'])
                : null,
            ':subject' => (string)$email['subject'],
            ':body_html' => (string)$email['body_html'],
            ':body_text' => isset($email['body_text']) ? (string)$email['body_text'] : null,
            ':ai_inputs_json' => isset($email['ai_inputs'])
                ? json_encode($email['ai_inputs'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
                : null,
            ':sent_status' => (string)($email['sent_status'] ?? 'queued'),
            ':sent_at' => isset($email['sent_at']) ? (string)$email['sent_at'] : null,
            ':created_at' => (string)($email['created_at'] ?? gmdate('Y-m-d H:i:s')),
            ':notification_template_id' => isset($email['notification_template_id']) ? (int)$email['notification_template_id'] : null,
            ':notification_template_version_id' => isset($email['notification_template_version_id']) ? (int)$email['notification_template_version_id'] : null,
            ':render_context_json' => $renderContextJson,
        ]);

        return (int)$this->pdo->lastInsertId();
    }

	
	    public function recordAutomationEmailAudit(array $email): int
    {
        $requiredFields = [
            'user_id',
            'cohort_id',
            'lesson_id',
            'email_type',
            'recipients_to',
            'subject',
            'body_html',
        ];

        foreach ($requiredFields as $field) {
            if (!array_key_exists($field, $email)) {
                throw new InvalidArgumentException("Missing required email audit field: {$field}");
            }
        }

        $sql = "
            INSERT INTO training_progression_emails (
                user_id,
                cohort_id,
                lesson_id,
                progress_test_id,
                email_type,
                recipients_to,
                recipients_cc,
                subject,
                body_html,
                body_text,
                ai_inputs_json,
                sent_status,
                sent_at,
                created_at,
                notification_template_id,
                notification_template_version_id,
                render_context_json
            ) VALUES (
                :user_id,
                :cohort_id,
                :lesson_id,
                :progress_test_id,
                :email_type,
                :recipients_to,
                :recipients_cc,
                :subject,
                :body_html,
                :body_text,
                :ai_inputs_json,
                :sent_status,
                :sent_at,
                :created_at,
                :notification_template_id,
                :notification_template_version_id,
                :render_context_json
            )
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':user_id' => (int)$email['user_id'],
            ':cohort_id' => (int)$email['cohort_id'],
            ':lesson_id' => (int)$email['lesson_id'],
            ':progress_test_id' => isset($email['progress_test_id']) ? (int)$email['progress_test_id'] : null,
            ':email_type' => (string)$email['email_type'],
            ':recipients_to' => $this->encodeMixedField($email['recipients_to']),
            ':recipients_cc' => array_key_exists('recipients_cc', $email)
                ? $this->encodeMixedField($email['recipients_cc'])
                : null,
            ':subject' => (string)$email['subject'],
            ':body_html' => (string)$email['body_html'],
            ':body_text' => isset($email['body_text']) ? (string)$email['body_text'] : null,
            ':ai_inputs_json' => isset($email['ai_inputs'])
                ? json_encode($email['ai_inputs'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
                : null,
            ':sent_status' => (string)($email['sent_status'] ?? 'sent'),
            ':sent_at' => isset($email['sent_at']) ? (string)$email['sent_at'] : null,
            ':created_at' => (string)($email['created_at'] ?? gmdate('Y-m-d H:i:s')),
            ':notification_template_id' => isset($email['notification_template_id']) ? (int)$email['notification_template_id'] : null,
            ':notification_template_version_id' => isset($email['notification_template_version_id']) ? (int)$email['notification_template_version_id'] : null,
            ':render_context_json' => isset($email['render_context'])
                ? json_encode($email['render_context'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
                : null,
        ]);

        return (int)$this->pdo->lastInsertId();
    }
	
	
    public function sendProgressionEmailById(int $emailId): array
    {
        $sql = "
            SELECT *
            FROM training_progression_emails
            WHERE id = :id
            LIMIT 1
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':id' => $emailId,
        ]);

        $emailRow = $stmt->fetch();
        if (!$emailRow) {
            throw new RuntimeException("Queued email not found: {$emailId}");
        }

        require_once __DIR__ . '/mailer.php';

        $to = $this->decodeRecipientField((string)$emailRow['recipients_to']);
        $cc = $this->decodeRecipientField((string)($emailRow['recipients_cc'] ?? ''));

        $result = cw_send_mail([
            'to' => $to,
            'cc' => $cc,
            'subject' => (string)$emailRow['subject'],
            'html' => (string)$emailRow['body_html'],
            'text' => (string)($emailRow['body_text'] ?? ''),
        ]);

        $sentStatus = !empty($result['ok']) ? 'sent' : 'failed';

        $upd = $this->pdo->prepare("
            UPDATE training_progression_emails
            SET sent_status = :sent_status,
                sent_at = :sent_at
            WHERE id = :id
        ");
        $upd->execute([
            ':sent_status' => $sentStatus,
            ':sent_at' => !empty($result['ok']) ? gmdate('Y-m-d H:i:s') : null,
            ':id' => $emailId,
        ]);

        $this->logProgressionEvent([
            'user_id' => (int)$emailRow['user_id'],
            'cohort_id' => (int)$emailRow['cohort_id'],
            'lesson_id' => (int)$emailRow['lesson_id'],
            'progress_test_id' => isset($emailRow['progress_test_id']) ? (int)$emailRow['progress_test_id'] : null,
            'event_type' => 'notification',
            'event_code' => !empty($result['ok']) ? 'progression_email_sent' : 'progression_email_failed',
            'event_status' => !empty($result['ok']) ? 'info' : 'warning',
            'actor_type' => 'system',
            'actor_user_id' => null,
            'event_time' => gmdate('Y-m-d H:i:s'),
            'payload' => [
                'email_id' => (int)$emailRow['id'],
                'email_type' => (string)$emailRow['email_type'],
                'provider' => (string)($result['provider'] ?? 'smtp'),
                'ok' => !empty($result['ok']) ? 1 : 0,
                'message_id' => $result['message_id'] ?? null,
                'error' => $result['error'] ?? null,
                'notification_channel' => self::NOTIFICATION_CHANNEL_EMAIL,
                'notification_template_id' => isset($emailRow['notification_template_id']) && $emailRow['notification_template_id'] !== null
                    ? (int)$emailRow['notification_template_id']
                    : null,
                'notification_template_version_id' => isset($emailRow['notification_template_version_id']) && $emailRow['notification_template_version_id'] !== null
                    ? (int)$emailRow['notification_template_version_id']
                    : null,
            ],
            'legal_note' => 'Progression email delivery attempt recorded by system.'
        ]);

        return $result;
    }

    public function getNotificationTemplateByKey(string $notificationKey): ?array
    {
        $sql = "
            SELECT *
            FROM notification_templates
            WHERE notification_key = :notification_key
              AND channel = 'email'
            LIMIT 1
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':notification_key' => $notificationKey,
        ]);

        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function getLatestNotificationTemplateVersionByTemplateId(int $notificationTemplateId): ?array
    {
        $sql = "
            SELECT *
            FROM notification_template_versions
            WHERE notification_template_id = :notification_template_id
            ORDER BY version_no DESC, id DESC
            LIMIT 1
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':notification_template_id' => $notificationTemplateId,
        ]);

        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function getNotificationTemplateVersionById(int $versionId): ?array
    {
        $sql = "
            SELECT *
            FROM notification_template_versions
            WHERE id = :id
            LIMIT 1
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':id' => $versionId,
        ]);

        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function renderAndQueueNotificationTemplate(array $data): array
    {
        $required = [
            'notification_key',
            'user_id',
            'cohort_id',
            'lesson_id',
            'email_type',
            'recipients_to',
            'context',
        ];

        foreach ($required as $field) {
            if (!array_key_exists($field, $data)) {
                throw new InvalidArgumentException("Missing notification render field: {$field}");
            }
        }

        $notificationKey = (string)$data['notification_key'];
        $template = $this->getNotificationTemplateByKey($notificationKey);

        if (!$template) {
            throw new RuntimeException("Notification template not found: {$notificationKey}");
        }

        $userId = (int)$data['user_id'];
        $cohortId = (int)$data['cohort_id'];
        $lessonId = (int)$data['lesson_id'];
        $progressTestId = isset($data['progress_test_id']) ? (int)$data['progress_test_id'] : null;
        $emailType = (string)$data['email_type'];
        $context = is_array($data['context']) ? $data['context'] : [];

        $isEnabled = (int)($template['is_enabled'] ?? 0) === 1;
        if (!$isEnabled) {
            $this->logProgressionEvent([
                'user_id' => $userId,
                'cohort_id' => $cohortId,
                'lesson_id' => $lessonId,
                'progress_test_id' => $progressTestId,
                'event_type' => 'notification',
                'event_code' => 'notification_suppressed_disabled',
                'event_status' => 'info',
                'actor_type' => 'system',
                'actor_user_id' => null,
                'event_time' => gmdate('Y-m-d H:i:s'),
                'payload' => [
                    'notification_key' => $notificationKey,
                    'email_type' => $emailType,
                    'notification_channel' => self::NOTIFICATION_CHANNEL_EMAIL,
                    'notification_template_id' => (int)$template['id'],
                ],
                'legal_note' => 'Notification was suppressed because the live template is disabled.'
            ]);

            return [
                'queued' => false,
                'suppressed' => true,
                'reason' => 'disabled',
                'notification_template_id' => (int)$template['id'],
                'notification_template_version_id' => null,
            ];
        }

        $duplicateStrategy = trim((string)($template['duplicate_strategy'] ?? ''));
        $isDuplicate = false;

        if ($duplicateStrategy === 'once_per_lesson') {
            $isDuplicate = $this->hasAnyProgressionEmailForLesson($userId, $cohortId, $lessonId, $emailType);
        } elseif (
            $duplicateStrategy === 'once_per_progress_test'
            && $progressTestId !== null
            && $progressTestId > 0
        ) {
            $isDuplicate = $this->progressionEmailExistsForProgressTest($progressTestId, $emailType);
        }

        if ($isDuplicate) {
            $this->logProgressionEvent([
                'user_id' => $userId,
                'cohort_id' => $cohortId,
                'lesson_id' => $lessonId,
                'progress_test_id' => $progressTestId,
                'event_type' => 'notification',
                'event_code' => 'notification_suppressed_duplicate',
                'event_status' => 'info',
                'actor_type' => 'system',
                'actor_user_id' => null,
                'event_time' => gmdate('Y-m-d H:i:s'),
                'payload' => [
                    'notification_key' => $notificationKey,
                    'email_type' => $emailType,
                    'duplicate_strategy' => $duplicateStrategy,
                    'notification_channel' => self::NOTIFICATION_CHANNEL_EMAIL,
                    'notification_template_id' => (int)$template['id'],
                ],
                'legal_note' => 'Notification was suppressed before queue creation due to duplicate strategy.'
            ]);

            return [
                'queued' => false,
                'suppressed' => true,
                'reason' => 'duplicate',
                'notification_template_id' => (int)$template['id'],
                'notification_template_version_id' => null,
            ];
        }

        $version = $this->getLatestNotificationTemplateVersionByTemplateId((int)$template['id']);
        if (!$version) {
            throw new RuntimeException("No notification template version found for: {$notificationKey}");
        }

        $rendered = $this->renderNotificationPayloadFromSavedTemplate($template, $version, $context);

        $emailId = $this->queueProgressionEmail([
            'user_id' => $userId,
            'cohort_id' => $cohortId,
            'lesson_id' => $lessonId,
            'progress_test_id' => $progressTestId,
            'email_type' => $emailType,
            'recipients_to' => $data['recipients_to'],
            'recipients_cc' => $data['recipients_cc'] ?? [],
            'subject' => $rendered['subject'],
            'body_html' => $rendered['html'],
            'body_text' => $rendered['text'],
            'ai_inputs' => $data['ai_inputs'] ?? null,
            'sent_status' => (string)($data['sent_status'] ?? 'queued'),
            'notification_template_id' => (int)$template['id'],
            'notification_template_version_id' => (int)$version['id'],
            'render_context' => $context,
        ]);

        return [
            'queued' => true,
            'suppressed' => false,
            'email_id' => $emailId,
            'notification_template_id' => (int)$template['id'],
            'notification_template_version_id' => (int)$version['id'],
            'delivery_mode' => (string)($template['delivery_mode'] ?? 'immediate'),
            'subject' => $rendered['subject'],
            'body_html' => $rendered['html'],
            'body_text' => $rendered['text'],
        ];
    }

    public function previewNotificationTemplate(
        string $notificationKey,
        array $draft,
        array $context,
        array $meta = []
    ): array {
        $rendered = $this->renderNotificationPayloadFromDraft($notificationKey, $draft, $context);

        $this->logProgressionEvent([
            'user_id' => (int)($meta['user_id'] ?? 0),
            'cohort_id' => (int)($meta['cohort_id'] ?? 0),
            'lesson_id' => (int)($meta['lesson_id'] ?? 0),
            'progress_test_id' => isset($meta['progress_test_id']) ? (int)$meta['progress_test_id'] : null,
            'event_type' => 'notification',
            'event_code' => 'notification_preview_rendered',
            'event_status' => 'info',
            'actor_type' => (string)($meta['actor_type'] ?? 'admin'),
            'actor_user_id' => isset($meta['actor_user_id']) ? (int)$meta['actor_user_id'] : null,
            'event_time' => gmdate('Y-m-d H:i:s'),
            'payload' => [
                'notification_key' => $notificationKey,
                'notification_channel' => self::NOTIFICATION_CHANNEL_EMAIL,
                'render_mode' => 'preview',
                'used_draft' => 1,
            ],
            'legal_note' => 'Admin preview render executed without queue creation or progression side effects.'
        ]);

        return $rendered;
    }

    public function sendTestNotificationTemplate(
        string $notificationKey,
        array $draft,
        array $context,
        string|array $to,
        array $meta = []
    ): array {
        require_once __DIR__ . '/mailer.php';

        $rendered = $this->renderNotificationPayloadFromDraft($notificationKey, $draft, $context);

        $testSubject = '[TEST] ' . $rendered['subject'];
        $testBannerHtml = $this->buildTestBannerHtml($notificationKey);
        $testBannerText = $this->buildTestBannerText($notificationKey);

        $testHtml = $testBannerHtml . $rendered['html'];
        $testText = $testBannerText . "\n\n" . $rendered['text'];

        $result = cw_send_mail([
            'to' => $to,
            'subject' => $testSubject,
            'html' => $testHtml,
            'text' => $testText,
        ]);

        $this->logProgressionEvent([
            'user_id' => (int)($meta['user_id'] ?? 0),
            'cohort_id' => (int)($meta['cohort_id'] ?? 0),
            'lesson_id' => (int)($meta['lesson_id'] ?? 0),
            'progress_test_id' => isset($meta['progress_test_id']) ? (int)$meta['progress_test_id'] : null,
            'event_type' => 'notification',
            'event_code' => !empty($result['ok'])
                ? 'notification_test_sent'
                : 'notification_test_failed',
            'event_status' => !empty($result['ok']) ? 'info' : 'warning',
            'actor_type' => (string)($meta['actor_type'] ?? 'admin'),
            'actor_user_id' => isset($meta['actor_user_id']) ? (int)$meta['actor_user_id'] : null,
            'event_time' => gmdate('Y-m-d H:i:s'),
            'payload' => [
                'notification_key' => $notificationKey,
                'notification_channel' => self::NOTIFICATION_CHANNEL_EMAIL,
                'render_mode' => 'test_send',
                'used_draft' => 1,
                'ok' => !empty($result['ok']) ? 1 : 0,
                'provider' => (string)($result['provider'] ?? 'smtp'),
                'message_id' => $result['message_id'] ?? null,
                'error' => $result['error'] ?? null,
            ],
            'legal_note' => 'Admin test send executed from current draft with no queue row and no progression side effects.'
        ]);

        return [
            'ok' => !empty($result['ok']),
            'provider' => (string)($result['provider'] ?? 'smtp'),
            'message_id' => $result['message_id'] ?? null,
            'error' => $result['error'] ?? null,
            'subject' => $testSubject,
            'html' => $testHtml,
            'text' => $testText,
        ];
    }

    public function getDefaultDummyContextForNotification(string $notificationKey): array
    {
        $template = $this->getNotificationTemplateByKey($notificationKey);
        if (!$template) {
            return [];
        }

        $definitions = $this->normalizeAllowedVariableDefinitions($template['allowed_variables_json'] ?? null);
        $result = [];

        foreach ($definitions as $def) {
            $name = (string)$def['name'];
            $sample = $def['sample_value'] ?? '';

            if ($name === '') {
                continue;
            }

            $result[$name] = $sample;
        }

        return $result;
    }

    private function persistProgressionConsequences(int $progressTestId, array $classification, array $decision, array $requiredActions, array $projection, array $options = []): array
    {
        $test = $this->getProgressTestRowById($progressTestId);
        if (!$test) {
            throw new RuntimeException('Progress test not found.');
        }

        $completedAt = (string)($options['completed_at'] ?? gmdate('Y-m-d H:i:s'));

        $stmt = $this->pdo->prepare("
            UPDATE progress_tests_v2
            SET
                score_pct = :score_pct,
                timing_status = :timing_status,
                pass_gate_met = :pass_gate_met,
                counts_as_unsat = :counts_as_unsat,
                formal_result_code = :formal_result_code,
                formal_result_label = :formal_result_label,
                finalized_by_logic_version = :logic_version,
                completed_at = :completed_at,
                status = 'completed',
                updated_at = NOW()
            WHERE id = :id
        ");
        $stmt->execute([
            ':score_pct' => (int)($classification['score_pct'] ?? 0),
            ':timing_status' => (string)($classification['timing_status'] ?? 'unknown'),
            ':pass_gate_met' => (int)($classification['pass_gate_met'] ?? 0),
            ':counts_as_unsat' => (int)($classification['counts_as_unsat'] ?? 0),
            ':formal_result_code' => (string)($classification['formal_result_code'] ?? ''),
            ':formal_result_label' => (string)($classification['formal_result_label'] ?? ''),
            ':logic_version' => self::LOGIC_VERSION,
            ':completed_at' => $completedAt,
            ':id' => $progressTestId,
        ]);

        $projectionResult = $this->persistLessonActivityProjection(
            (int)$test['user_id'],
            (int)$test['cohort_id'],
            (int)$test['lesson_id'],
            $projection
        );

        return [
            'ok' => true,
            'projection_result' => $projectionResult,
            'required_actions' => $requiredActions,
        ];
    }

    private function resolveSummaryState(int $userId, int $cohortId, int $lessonId, array $policy): array
    {
        $summaryRequired = !empty($this->resolveProgressionPolicyValue($policy, 'summary_required_before_test_start', [], false));
        $summary = $this->getLessonSummaryRow($userId, $cohortId, $lessonId);

        return [
            'summary_required_before_test_start' => $summaryRequired,
            'summary_exists' => $summary !== null,
            'summary_status' => $summary ? (string)($summary['review_status'] ?? 'pending') : 'missing',
        ];
    }

    private function renderNotificationPayloadFromSavedTemplate(array $template, array $version, array $context): array
    {
        $draft = [
            'subject_template' => (string)($version['subject_template'] ?? ''),
            'html_template' => (string)($version['html_template'] ?? ''),
            'text_template' => isset($version['text_template']) ? (string)$version['text_template'] : '',
            'allowed_variables_json' => $version['allowed_variables_json'] ?? ($template['allowed_variables_json'] ?? null),
        ];

        $rendered = $this->renderNotificationPayloadFromDraft(
            (string)$template['notification_key'],
            $draft,
            $context
        );

        $rendered['notification_template_id'] = (int)$template['id'];
        $rendered['notification_template_version_id'] = (int)$version['id'];

        return $rendered;
    }

    private function renderNotificationPayloadFromDraft(string $notificationKey, array $draft, array $context): array
    {
        $subjectTemplate = (string)($draft['subject_template'] ?? '');
        $htmlTemplate = (string)($draft['html_template'] ?? '');
        $textTemplate = (string)($draft['text_template'] ?? '');
        $allowedVariablesJson = $draft['allowed_variables_json'] ?? null;

        if (trim($subjectTemplate) === '') {
            throw new RuntimeException("Notification draft subject_template is empty for {$notificationKey}");
        }
        if (trim($htmlTemplate) === '') {
            throw new RuntimeException("Notification draft html_template is empty for {$notificationKey}");
        }

        $definitions = $this->normalizeAllowedVariableDefinitions($allowedVariablesJson);
        $this->validateRenderContextAgainstDefinitions($notificationKey, $definitions, $context);

        $subject = $this->renderTemplateString(
            $subjectTemplate,
            $definitions,
            $context,
            'subject'
        );

        $html = $this->renderTemplateString(
            $htmlTemplate,
            $definitions,
            $context,
            'html'
        );

        if (trim($textTemplate) === '') {
            require_once __DIR__ . '/mailer.php';
            $text = cw_mail_html_to_text($html);
        } else {
            $text = $this->renderTemplateString(
                $textTemplate,
                $definitions,
                $context,
                'text'
            );
        }

        return [
            'subject' => $subject,
            'html' => $html,
            'text' => $text,
            'allowed_variables' => $definitions,
        ];
    }

    private function validateRenderContextAgainstDefinitions(
        string $notificationKey,
        array $definitions,
        array $context
    ): void {
        $definitionMap = [];
        foreach ($definitions as $def) {
            $name = (string)($def['name'] ?? '');
            if ($name !== '') {
                $definitionMap[$name] = $def;
            }
        }

        foreach ($definitionMap as $name => $def) {
            $required = !empty($def['required']);
            if (!$required) {
                continue;
            }

            if (!array_key_exists($name, $context)) {
                throw new RuntimeException("Missing required template variable '{$name}' for {$notificationKey}");
            }

            $value = $context[$name];
            if ($value === null) {
                throw new RuntimeException("Required template variable '{$name}' is null for {$notificationKey}");
            }

            if (is_string($value) && trim($value) === '') {
                throw new RuntimeException("Required template variable '{$name}' is empty for {$notificationKey}");
            }
        }
    }

    private function normalizeAllowedVariableDefinitions(mixed $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }

        if (is_string($json)) {
            $decoded = json_decode($json, true);
            if (!is_array($decoded)) {
                throw new RuntimeException('Invalid allowed_variables_json on notification template.');
            }
            $json = $decoded;
        }

        if (!is_array($json)) {
            throw new RuntimeException('Invalid allowed_variables_json format.');
        }

        $definitions = [];
        foreach ($json as $row) {
            if (!is_array($row)) {
                continue;
            }

            $name = trim((string)($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $definitions[] = [
                'name' => $name,
                'type' => trim((string)($row['type'] ?? 'text')),
                'label' => trim((string)($row['label'] ?? $name)),
                'required' => !empty($row['required']),
                'safe_mode' => trim((string)($row['safe_mode'] ?? 'escaped')),
                'description' => trim((string)($row['description'] ?? '')),
                'sample_value' => $row['sample_value'] ?? '',
            ];
        }

        return $definitions;
    }

    private function renderTemplateString(
        string $template,
        array $definitions,
        array $context,
        string $renderMode
    ): string {
        $this->assertTemplateHasOnlyStrictTokens($template);

        $definitionMap = [];
        foreach ($definitions as $def) {
            $definitionMap[(string)$def['name']] = $def;
        }

        $self = $this;

        $rendered = preg_replace_callback(
            '/{{\s*([A-Za-z0-9_]+)\s*}}/',
            static function (array $matches) use ($definitionMap, $context, $renderMode, $self): string {
                $name = (string)$matches[1];

                if (!isset($definitionMap[$name])) {
                    throw new RuntimeException("Unknown template variable: {$name}");
                }

                if (!array_key_exists($name, $context)) {
                    throw new RuntimeException("Missing template render value for variable: {$name}");
                }

                return $self->renderTokenValue($definitionMap[$name], $context[$name], $renderMode);
            },
            $template
        );

        if (!is_string($rendered)) {
            throw new RuntimeException('Failed to render notification template.');
        }

        return $rendered;
    }

    private function assertTemplateHasOnlyStrictTokens(string $template): void
    {
        if ($template === '') {
            return;
        }

        if (preg_match_all('/{{(.*?)}}/s', $template, $allMatches)) {
            foreach ($allMatches[1] as $rawInner) {
                $rawInner = trim((string)$rawInner);

                if (!preg_match('/^[A-Za-z0-9_]+$/', $rawInner)) {
                    throw new RuntimeException(
                        'Invalid template token syntax detected. Only simple token names like {{lesson_title}} are allowed.'
                    );
                }

                if (str_contains($rawInner, '{{') || str_contains($rawInner, '}}')) {
                    throw new RuntimeException('Nested template tokens are not allowed.');
                }
            }
        }
    }

    private function renderTokenValue(array $definition, mixed $value, string $renderMode): string
    {
        $safeMode = (string)($definition['safe_mode'] ?? 'escaped');

        if (is_bool($value)) {
            $raw = $value ? '1' : '0';
        } elseif (is_scalar($value) || $value === null) {
            $raw = (string)$value;
        } else {
            $raw = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }

        if ($renderMode === 'html') {
            if ($safeMode === 'approved_html') {
                return $raw;
            }

            return $this->escapeHtml($raw);
        }

        if ($safeMode === 'approved_html') {
            return $this->htmlToTextSafe($raw);
        }

        return $raw;
    }

    private function buildTestBannerHtml(string $notificationKey): string
    {
        return ''
            . '<div style="background:#fff3cd;border:1px solid #ffe69c;color:#664d03;'
            . 'padding:12px 14px;margin:0 0 18px 0;font-family:Arial,Helvetica,sans-serif;'
            . 'font-size:14px;line-height:1.45;">'
            . '<strong>[TEST EMAIL]</strong><br>'
            . 'This is an administrator test send for notification key <strong>'
            . $this->escapeHtml($notificationKey)
            . '</strong>. No student progression state, required actions, deadlines, or real notifications were changed.'
            . '</div>';
    }

    private function buildTestBannerText(string $notificationKey): string
    {
        return '[TEST EMAIL]' . "\n"
            . 'This is an administrator test send for notification key ' . $notificationKey . '. '
            . 'No student progression state, required actions, deadlines, or real notifications were changed.';
    }

    private function escapeHtml(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function htmlToTextSafe(string $html): string
    {
        require_once __DIR__ . '/mailer.php';
        return cw_mail_html_to_text($html);
    }

    private function resolveProgressionPolicyValue(array $policy, string $canonicalKey, array $fallbackKeys = [], mixed $default = null): mixed
    {
        if ($this->policyKeyExistsInArray($policy, $canonicalKey)) {
            return $policy[$canonicalKey];
        }

        foreach ($fallbackKeys as $fallbackKey) {
            if ($this->policyKeyExistsInArray($policy, $fallbackKey)) {
                return $policy[$fallbackKey];
            }
        }

        return $default;
    }

    private function policyKeyExistsInArray(array $policy, string $key): bool
    {
        return array_key_exists($key, $policy);
    }

    private function getLatestAttemptNumber(int $userId, int $cohortId, int $lessonId): int
    {
        $stmt = $this->pdo->prepare("
            SELECT MAX(attempt)
            FROM progress_tests_v2
            WHERE user_id = :user_id
              AND cohort_id = :cohort_id
              AND lesson_id = :lesson_id
        ");
        $stmt->execute([
            ':user_id' => $userId,
            ':cohort_id' => $cohortId,
            ':lesson_id' => $lessonId,
        ]);

        return max(0, (int)$stmt->fetchColumn());
    }

    private function getLessonActivityProjectionRow(int $userId, int $cohortId, int $lessonId): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT *
            FROM lesson_activity
            WHERE user_id = :user_id
              AND cohort_id = :cohort_id
              AND lesson_id = :lesson_id
            LIMIT 1
        ");
        $stmt->execute([
            ':user_id' => $userId,
            ':cohort_id' => $cohortId,
            ':lesson_id' => $lessonId,
        ]);

        $row = $stmt->fetch();
        return $row ?: null;
    }

    private function getProgressTestRowById(int $progressTestId): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT *
            FROM progress_tests_v2
            WHERE id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $progressTestId]);

        $row = $stmt->fetch();
        return $row ?: null;
    }

    private function getLatestProgressTestRowForLesson(int $userId, int $cohortId, int $lessonId): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT *
            FROM progress_tests_v2
            WHERE user_id = :user_id
              AND cohort_id = :cohort_id
              AND lesson_id = :lesson_id
            ORDER BY attempt DESC, id DESC
            LIMIT 1
        ");
        $stmt->execute([
            ':user_id' => $userId,
            ':cohort_id' => $cohortId,
            ':lesson_id' => $lessonId,
        ]);

        $row = $stmt->fetch();
        return $row ?: null;
    }

    private function getLessonSummaryRow(int $userId, int $cohortId, int $lessonId): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT *
            FROM lesson_summaries
            WHERE user_id = :user_id
              AND cohort_id = :cohort_id
              AND lesson_id = :lesson_id
            LIMIT 1
        ");
        $stmt->execute([
            ':user_id' => $userId,
            ':cohort_id' => $cohortId,
            ':lesson_id' => $lessonId,
        ]);

        $row = $stmt->fetch();
        return $row ?: null;
    }

    private function buildInternalAppUrl(string $path): string
    {
        $base = trim((string)(getenv('CW_APP_BASE_URL') ?: ''));
        if ($base !== '') {
            return rtrim($base, '/') . '/' . ltrim($path, '/');
        }

        $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
        if ($host !== '') {
            return 'https://' . $host . '/' . ltrim($path, '/');
        }

        return '/' . ltrim($path, '/');
    }

    private function getPolicyDefinition(string $policyKey): ?array
    {
        $sql = "
            SELECT
                policy_key,
                value_type,
                default_value_text
            FROM system_policy_definitions
            WHERE policy_key = :policy_key
            LIMIT 1
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':policy_key' => $policyKey,
        ]);

        $row = $stmt->fetch();

        return $row ?: null;
    }

    private function resolvePolicyValueText(string $policyKey, array $scope): ?string
    {
        $cohortId = isset($scope['cohort_id']) ? (int)$scope['cohort_id'] : null;
        $courseId = isset($scope['course_id']) ? (int)$scope['course_id'] : null;

        if ($cohortId !== null) {
            $cohortValue = $this->findActivePolicyValue($policyKey, 'cohort', $cohortId);
            if ($cohortValue !== null) {
                return $cohortValue;
            }
        }

        if ($courseId !== null) {
            $courseValue = $this->findActivePolicyValue($policyKey, 'course', $courseId);
            if ($courseValue !== null) {
                return $courseValue;
            }
        }

        return $this->findActivePolicyValue($policyKey, 'global', null);
    }

    private function findActivePolicyValue(string $policyKey, string $scopeType, ?int $scopeId): ?string
    {
        if ($scopeType === 'global') {
            $sql = "
                SELECT value_text
                FROM system_policy_values
                WHERE policy_key = :policy_key
                  AND scope_type = 'global'
                  AND is_active = 1
                  AND (effective_to IS NULL OR effective_to > UTC_TIMESTAMP())
                ORDER BY effective_from DESC, id DESC
                LIMIT 1
            ";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                ':policy_key' => $policyKey,
            ]);
        } else {
            $sql = "
                SELECT value_text
                FROM system_policy_values
                WHERE policy_key = :policy_key
                  AND scope_type = :scope_type
                  AND scope_id = :scope_id
                  AND is_active = 1
                  AND (effective_to IS NULL OR effective_to > UTC_TIMESTAMP())
                ORDER BY effective_from DESC, id DESC
                LIMIT 1
            ";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                ':policy_key' => $policyKey,
                ':scope_type' => $scopeType,
                ':scope_id' => $scopeId,
            ]);
        }

        $row = $stmt->fetch();

        return $row ? (string)$row['value_text'] : null;
    }

    private function castPolicyValue(string $valueType, string $valueText): mixed
    {
        return match ($valueType) {
            'int' => (int)$valueText,
            'decimal' => (float)$valueText,
            'bool' => in_array(strtolower(trim($valueText)), ['1', 'true', 'yes', 'on'], true),
            'json' => $this->decodeJsonPolicy($valueText),
            'string' => $valueText,
            default => $valueText,
        };
    }

    private function decodeJsonPolicy(string $valueText): mixed
    {
        return json_decode($valueText, true, 512, JSON_THROW_ON_ERROR);
    }

    private function encodeMixedField(mixed $value): string
    {
        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }

        return (string)$value;
    }

private function decodeRecipientField(string $value): array
{
    $value = trim($value);
    if ($value === '') {
        return [];
    }

    $decoded = json_decode($value, true);

    if (!is_array($decoded)) {
        return [[
            'email' => $value,
            'name' => '',
        ]];
    }

    $normalized = [];

    foreach ($decoded as $item) {
        if (is_string($item)) {
            $item = trim($item);
            if ($item !== '') {
                $normalized[] = [
                    'email' => $item,
                    'name' => '',
                ];
            }
            continue;
        }

        if (is_array($item)) {
            $email = trim((string)($item['email'] ?? ''));
            $name = trim((string)($item['name'] ?? ''));

            if ($email === '') {
                continue;
            }

            $normalized[] = [
                'email' => $email,
                'name' => $name,
            ];
        }
    }

    return $normalized;
}
	
private function buildInstructorDecisionAutomationContext(array $action, array $decisionPayload, array $projectionFields = []): array
{
    $userId = (int)($action['user_id'] ?? 0);
    $cohortId = (int)($action['cohort_id'] ?? 0);
    $lessonId = (int)($action['lesson_id'] ?? 0);
    $progressTestId = isset($action['progress_test_id']) ? (int)$action['progress_test_id'] : null;

    $progressionContext = $this->getProgressionContextForUserLesson($userId, $cohortId, $lessonId);
    $latestProgressTest = (array)($progressionContext['latest_progress_test'] ?? []);
    $summary = (array)($progressionContext['summary'] ?? []);
    $activityState = (array)($progressionContext['activity_state'] ?? []);

    $studentRecipient = (array)($progressionContext['student_recipient'] ?? []);
    $chiefRecipient = (array)($progressionContext['chief_instructor_recipient'] ?? []);

    $studentName = trim((string)($studentRecipient['name'] ?? ''));
    if ($studentName === '') {
        $studentName = 'Student';
    }

    $chiefName = trim((string)($chiefRecipient['name'] ?? ''));
    if ($chiefName === '') {
        $chiefName = 'Chief Instructor';
    }

    $decisionCode = trim((string)($decisionPayload['decision_code'] ?? ''));
    $decisionNotes = trim((string)($decisionPayload['decision_notes'] ?? ''));

    $summaryRevisionRequired = !empty($decisionPayload['summary_revision_required']) ? 1 : 0;
    $oneOnOneRequired = !empty($decisionPayload['one_on_one_required']) ? 1 : 0;
    $trainingSuspended = !empty($decisionPayload['training_suspended']) ? 1 : 0;
    $majorInterventionFlag = !empty($decisionPayload['major_intervention_flag']) ? 1 : 0;
    $grantedExtraAttempts = max(0, (int)($decisionPayload['granted_extra_attempts'] ?? 0));

    $approvalUrl = '';
    $token = trim((string)($action['token'] ?? ''));
    if ($token !== '') {
        $approvalUrl = $this->buildInternalAppUrl('/instructor/instructor_approval.php?token=' . urlencode($token));
    }

    $summaryStatus = trim((string)($summary['review_status'] ?? ''));
    $summaryScore = isset($summary['review_score']) && $summary['review_score'] !== null
        ? (string)(int)$summary['review_score']
        : '';

    $scorePct = isset($latestProgressTest['score_pct']) && $latestProgressTest['score_pct'] !== null
        ? (string)(int)$latestProgressTest['score_pct']
        : '';

    $attemptCount = isset($latestProgressTest['attempt']) && $latestProgressTest['attempt'] !== null
        ? (string)(int)$latestProgressTest['attempt']
        : '';

    $decisionNotesHtml = $decisionNotes !== ''
        ? nl2br($this->escapeHtml($decisionNotes))
        : '';

    return [
        'user_id' => $userId,
        'cohort_id' => $cohortId,
        'lesson_id' => $lessonId,
        'progress_test_id' => $progressTestId,

        'student_name' => $studentName,
        'student_email' => trim((string)($studentRecipient['email'] ?? '')),
        'chief_instructor_name' => $chiefName,
        'chief_instructor_email' => trim((string)($chiefRecipient['email'] ?? '')),

        'lesson_title' => (string)($progressionContext['lesson_title'] ?? ''),
        'cohort_title' => (string)($progressionContext['cohort_title'] ?? ''),

        'attempt_count' => $attemptCount,
        'score_pct' => $scorePct,
        'formal_result_code' => (string)($latestProgressTest['formal_result_code'] ?? ''),
        'formal_result_label' => (string)($latestProgressTest['formal_result_label'] ?? ''),
        'timing_status' => (string)($latestProgressTest['timing_status'] ?? ''),
        'pass_gate_met' => !empty($latestProgressTest['pass_gate_met']) ? 1 : 0,
        'counts_as_unsat' => !empty($latestProgressTest['counts_as_unsat']) ? 1 : 0,

        'decision_code' => $decisionCode,
        'decision_notes_text' => $decisionNotes,
        'decision_notes_html' => $decisionNotesHtml,

        'granted_extra_attempts' => $grantedExtraAttempts,
        'summary_revision_required' => $summaryRevisionRequired,
        'one_on_one_required' => $oneOnOneRequired,
        'one_on_one_completed' => !empty($projectionFields['one_on_one_completed']) ? 1 : (!empty($activityState['one_on_one_completed']) ? 1 : 0),
        'training_suspended' => $trainingSuspended,
        'major_intervention_flag' => $majorInterventionFlag,

        'summary_status' => $summaryStatus,
        'summary_score' => $summaryScore,
        'review_notes_text' => trim((string)($summary['review_notes_by_instructor'] ?? '')),
        'review_feedback_text' => trim((string)($summary['review_feedback'] ?? '')),

        'weak_areas_text' => trim((string)($latestProgressTest['weak_areas'] ?? '')),
        'weak_areas_html' => nl2br($this->escapeHtml(trim((string)($latestProgressTest['weak_areas'] ?? '')))),
        'written_debrief_text' => trim((string)($latestProgressTest['ai_summary'] ?? '')),
        'written_debrief_html' => nl2br($this->escapeHtml(trim((string)($latestProgressTest['ai_summary'] ?? '')))),

        'completion_status' => (string)($projectionFields['completion_status'] ?? ($activityState['completion_status'] ?? '')),
        'latest_instructor_action_id' => isset($action['id']) ? (int)$action['id'] : 0,
        'approval_url' => $approvalUrl,
    ];
}	
	
}