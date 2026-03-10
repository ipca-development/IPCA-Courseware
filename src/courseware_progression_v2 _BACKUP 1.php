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
     * Real sending will be implemented later.
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
}