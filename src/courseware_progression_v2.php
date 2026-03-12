<?php
declare(strict_types=1);

/**
 * Courseware Progression Engine V2
 *
 * Foundation layer:
 * - policy loading
 * - effective deadline resolution
 * - event logging
 * - email queue record creation
 * - queued email sending
 *
 * Requirements:
 * - PHP 8.2+
 * - MySQL 8+
 * - PDO
 */
final class CoursewareProgressionV2
{
    public const LOGIC_VERSION = 'v2.0';

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

    /**
     * Check whether an email record already exists for this progress test + email type.
     */
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
	
/**
 * Return most recent pending/open required action for user/cohort/lesson/type.
 */
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

	
/**
 * Return most recent completed/approved required action for user/cohort/lesson/type.
 */
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
	
	
/**
 * Fetch required action by token.
 */
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
	
	
/**
 * Mark required action as opened.
 */
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
    $action = $this->getRequiredActionById($actionId);
    if (!$action) {
        throw new RuntimeException('Instructor approval action not found.');
    }

    if ((string)$action['action_type'] !== 'instructor_approval') {
        throw new RuntimeException('Action is not instructor_approval.');
    }

    if (!in_array((string)$action['status'], ['pending', 'opened'], true)) {
        throw new RuntimeException('This instructor action has already been decided.');
    }

    $decisionCode = trim((string)($decision['decision_code'] ?? ''));
    $allowedDecisionCodes = [
        'approve_additional_attempts',
        'approve_with_summary_revision',
        'approve_with_one_on_one',
        'suspend_training',
    ];

    if (!in_array($decisionCode, $allowedDecisionCodes, true)) {
        throw new RuntimeException('Invalid decision_code.');
    }

    $grantedExtraAttempts = max(0, min(5, (int)($decision['granted_extra_attempts'] ?? 0)));
    $summaryRevisionRequired = !empty($decision['summary_revision_required']) ? 1 : 0;
    $oneOnOneRequired = !empty($decision['one_on_one_required']) ? 1 : 0;
    $trainingSuspended = !empty($decision['training_suspended']) ? 1 : 0;
    $majorInterventionFlag = !empty($decision['major_intervention_flag']) ? 1 : 0;
    $decisionNotes = trim((string)($decision['decision_notes'] ?? ''));

    if ($decisionNotes === '') {
        throw new RuntimeException('decision_notes is required.');
    }

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
        $trainingSuspended = 1;
        $summaryRevisionRequired = 0;
        $oneOnOneRequired = 0;
        $grantedExtraAttempts = 0;
        $majorInterventionFlag = 1;
    }

    if (!$trainingSuspended && $grantedExtraAttempts < 0) {
        throw new RuntimeException('Invalid granted_extra_attempts.');
    }

    $decisionPayload = [
        'decision_code' => $decisionCode,
        'granted_extra_attempts' => $grantedExtraAttempts,
        'summary_revision_required' => $summaryRevisionRequired,
        'one_on_one_required' => $oneOnOneRequired,
        'training_suspended' => $trainingSuspended,
        'major_intervention_flag' => $majorInterventionFlag,
        'decision_notes' => $decisionNotes,
    ];

    $nowUtc = gmdate('Y-m-d H:i:s');

    $this->pdo->beginTransaction();

    try {
        $updAction = $this->pdo->prepare("
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
        $updAction->execute([
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
            ':id' => $actionId,
        ]);

        if ($summaryRevisionRequired === 1) {
            $updSummary = $this->pdo->prepare("
                UPDATE lesson_summaries
                SET
                    review_status = 'needs_revision',
                    updated_at = NOW()
                WHERE user_id = ?
                  AND cohort_id = ?
                  AND lesson_id = ?
            ");
            $updSummary->execute([
                (int)$action['user_id'],
                (int)$action['cohort_id'],
                (int)$action['lesson_id']
            ]);
        }

        $activitySel = $this->pdo->prepare("
            SELECT id
            FROM lesson_activity
            WHERE user_id = ?
              AND cohort_id = ?
              AND lesson_id = ?
            LIMIT 1
        ");
        $activitySel->execute([
            (int)$action['user_id'],
            (int)$action['cohort_id'],
            (int)$action['lesson_id']
        ]);
        $activityRow = $activitySel->fetch();

        $mappedCompletionStatus = 'remediation_required';
        if ($trainingSuspended === 1) {
            $mappedCompletionStatus = 'blocked_final';
        } elseif ($summaryRevisionRequired === 1) {
            $mappedCompletionStatus = 'awaiting_summary_review';
        } elseif ($oneOnOneRequired === 1) {
            $mappedCompletionStatus = 'remediation_required';
        } else {
            $mappedCompletionStatus = 'in_progress';
        }

        if ($activityRow) {
            $updActivity = $this->pdo->prepare("
                UPDATE lesson_activity
                SET
                    completion_status = :completion_status,
                    granted_extra_attempts = :granted_extra_attempts,
                    one_on_one_required = :one_on_one_required,
                    one_on_one_completed = CASE
                        WHEN :one_on_one_required = 1 THEN 0
                        ELSE one_on_one_completed
                    END,
                    training_suspended = :training_suspended,
                    latest_instructor_action_id = :latest_instructor_action_id,
                    last_state_eval_at = :last_state_eval_at,
                    updated_at = NOW()
                WHERE id = :id
            ");
            $updActivity->execute([
                ':completion_status' => $mappedCompletionStatus,
                ':granted_extra_attempts' => $grantedExtraAttempts,
                ':one_on_one_required' => $oneOnOneRequired,
                ':training_suspended' => $trainingSuspended,
                ':latest_instructor_action_id' => $actionId,
                ':last_state_eval_at' => $nowUtc,
                ':id' => (int)$activityRow['id'],
            ]);
        }

        $this->logProgressionEvent([
            'user_id' => (int)$action['user_id'],
            'cohort_id' => (int)$action['cohort_id'],
            'lesson_id' => (int)$action['lesson_id'],
            'progress_test_id' => isset($action['progress_test_id']) ? (int)$action['progress_test_id'] : null,
            'event_type' => 'instructor_intervention',
            'event_code' => 'instructor_decision',
            'event_status' => 'warning',
            'actor_type' => 'admin',
            'actor_user_id' => $actorUserId,
            'event_time' => $nowUtc,
            'payload' => [
                'required_action_id' => $actionId,
                'decision_code' => $decisionCode,
                'granted_extra_attempts' => $grantedExtraAttempts,
                'summary_revision_required' => $summaryRevisionRequired,
                'one_on_one_required' => $oneOnOneRequired,
                'training_suspended' => $trainingSuspended,
                'major_intervention_flag' => $majorInterventionFlag,
            ],
            'legal_note' => 'Instructor intervention decision recorded after escalation.'
        ]);

        $this->pdo->commit();

        return [
            'decision_code' => $decisionCode,
            'granted_extra_attempts' => $grantedExtraAttempts,
            'summary_revision_required' => $summaryRevisionRequired,
            'one_on_one_required' => $oneOnOneRequired,
            'training_suspended' => $trainingSuspended,
            'major_intervention_flag' => $majorInterventionFlag,
            'decision_notes' => $decisionNotes,
            'mapped_completion_status' => $mappedCompletionStatus,
        ];
    } catch (Throwable $e) {
        $this->pdo->rollBack();
        throw $e;
    }
}
	
	
	
public function markInstructorSessionCompleted(
    int $actionId,
    int $actorUserId,
    ?string $ipAddress = null,
    ?string $userAgent = null
): void {
    $action = $this->getRequiredActionById($actionId);
    if (!$action) {
        throw new RuntimeException('Instructor approval action not found.');
    }

    if ((string)$action['action_type'] !== 'instructor_approval') {
        throw new RuntimeException('Action is not instructor_approval.');
    }

    if ((int)($action['one_on_one_required'] ?? 0) !== 1) {
        throw new RuntimeException('This action does not require an instructor session.');
    }

    if ((string)$action['status'] !== 'approved') {
        throw new RuntimeException('Instructor decision must already be approved.');
    }

    $nowUtc = gmdate('Y-m-d H:i:s');

    $this->pdo->beginTransaction();

    try {
        $activitySel = $this->pdo->prepare("
            SELECT id
            FROM lesson_activity
            WHERE user_id = ?
              AND cohort_id = ?
              AND lesson_id = ?
            LIMIT 1
        ");
        $activitySel->execute([
            (int)$action['user_id'],
            (int)$action['cohort_id'],
            (int)$action['lesson_id']
        ]);
        $activityRow = $activitySel->fetch();

        if (!$activityRow) {
            throw new RuntimeException('lesson_activity row not found.');
        }

        $updActivity = $this->pdo->prepare("
            UPDATE lesson_activity
            SET
                one_on_one_completed = 1,
                completion_status = 'in_progress',
                last_state_eval_at = :last_state_eval_at,
                updated_at = NOW()
            WHERE id = :id
        ");
        $updActivity->execute([
            ':last_state_eval_at' => $nowUtc,
            ':id' => (int)$activityRow['id']
        ]);

        $updAction = $this->pdo->prepare("
            UPDATE student_required_actions
            SET
                ip_address = COALESCE(:ip_address, ip_address),
                user_agent = COALESCE(:user_agent, user_agent),
                updated_at = :updated_at
            WHERE id = :id
        ");
        $updAction->execute([
            ':ip_address' => $ipAddress,
            ':user_agent' => $userAgent,
            ':updated_at' => $nowUtc,
            ':id' => $actionId,
        ]);

        $this->logProgressionEvent([
            'user_id' => (int)$action['user_id'],
            'cohort_id' => (int)$action['cohort_id'],
            'lesson_id' => (int)$action['lesson_id'],
            'progress_test_id' => isset($action['progress_test_id']) ? (int)$action['progress_test_id'] : null,
            'event_type' => 'instructor_intervention',
            'event_code' => 'instructor_session_completed',
            'event_status' => 'warning',
            'actor_type' => 'admin',
            'actor_user_id' => $actorUserId,
            'event_time' => $nowUtc,
            'payload' => [
                'required_action_id' => $actionId
            ],
            'legal_note' => 'Required instructor one-on-one session marked completed.'
        ]);

        $this->pdo->commit();
    } catch (Throwable $e) {
        $this->pdo->rollBack();
        throw $e;
    }
}	
	
	
/**
 * Complete required action after student confirmation.
 */
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

/**
 * Check if a required action was completed.
 */
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
	
	
    /**
     * Create a new required action record.
     */
    public function createRequiredAction(array $data): int
    {
        $required = [
            'user_id',
            'cohort_id',
            'lesson_id',
            'action_type',
            'token',
            'title'
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
	
	
    /**
     * Write one audit event into training_progression_events.
     */
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

    /**
     * Store one queued email record into training_progression_emails.
     */
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
                created_at
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
                :created_at
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
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Send one queued progression email by DB id and update sent_status.
     * Returns the mail transport result.
     */
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

        $to = $this->decodeMixedField((string)$emailRow['recipients_to']);
        $cc = $this->decodeMixedField((string)($emailRow['recipients_cc'] ?? ''));

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
            ],
            'legal_note' => 'Progression email delivery attempt recorded by system.'
        ]);

        return $result;
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

    private function decodeMixedField(string $value): array|string
    {
        $value = trim($value);
        if ($value === '') {
            return [];
        }

        $decoded = json_decode($value, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        return $value;
    }
}