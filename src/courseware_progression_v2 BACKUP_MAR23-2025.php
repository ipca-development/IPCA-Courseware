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
                    'notification_channel' => self::NOTIFICATION_CHANNEL_EMAIL,
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
                    'required_action_id' => $actionId,
                    'notification_channel' => self::NOTIFICATION_CHANNEL_EMAIL,
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
     *
     * Legacy callers remain supported.
     * New fields supported:
     * - notification_template_id
     * - notification_template_version_id
     * - render_context_json OR render_context
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

    /**
     * Return live notification template by canonical notification_key.
     */
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

    /**
     * Return latest saved immutable version row for a live template.
     */
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

    /**
     * Render current saved live template and queue the real progression email.
     *
     * Suppression is checked before queue creation.
     * Returns:
     * - ['queued' => true, 'email_id' => int, ...]
     * - ['queued' => false, 'suppressed' => true, ...]
     */
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
        if ($duplicateStrategy === 'once_per_lesson' && $this->hasAnyProgressionEmailForLesson($userId, $cohortId, $lessonId, $emailType)) {
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

    /**
     * Preview render using current draft, without queue row creation.
     */
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

    /**
     * Test-send using current draft and dummy/manual context, without queue row creation.
     */
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

    /**
     * Code-based default dummy context for v1.
     */
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

    private function renderNotificationPayloadFromSavedTemplate(array $template, array $version, array $context): array
    {
        $draft = [
            'subject_template' => (string)$template['subject_template'],
            'html_template' => (string)$template['html_template'],
            'text_template' => isset($template['text_template']) ? (string)$template['text_template'] : '',
            'allowed_variables_json' => $template['allowed_variables_json'] ?? null,
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