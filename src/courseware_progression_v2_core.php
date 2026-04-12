<?php
declare(strict_types=1);

trait CoursewareProgressionV2Core
{


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
        $stmt->execute([
            ':id' => $progressTestId,
        ]);

        $row = $stmt->fetch();
        return $row ?: null;
    }

    private function getProgressTestByIdempotencyKey(string $key): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT *
            FROM progress_tests_v2
            WHERE idempotency_key = :key
            LIMIT 1
        ");
        $stmt->execute([
            ':key' => $key,
        ]);

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

    public function createOrReuseRequiredActionSafe(array $data): array
    {
        try {
            $actionId = $this->createRequiredAction($data);
            $action = $this->getRequiredActionById($actionId);

            return [
                'action_id' => $actionId,
                'action' => $action ?: [],
                'created_new' => true,
            ];
        } catch (PDOException $e) {
            if ((int)$e->getCode() === 23000) {
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
            }

            throw $e;
        }
    }

    private function replaceActiveDeadlineOverride(array $data): int
    {
        $required = [
            'user_id',
            'cohort_id',
            'lesson_id',
            'override_type',
            'base_deadline_utc',
            'new_deadline_utc',
            'approval_source',
        ];

        foreach ($required as $field) {
            if (!array_key_exists($field, $data)) {
                throw new InvalidArgumentException('Missing deadline override field: ' . $field);
            }
        }

        $deactivate = $this->pdo->prepare("
            UPDATE student_lesson_deadline_overrides
            SET is_active = 0
            WHERE user_id = :user_id
              AND cohort_id = :cohort_id
              AND lesson_id = :lesson_id
              AND is_active = 1
        ");
        $deactivate->execute([
            ':user_id' => (int)$data['user_id'],
            ':cohort_id' => (int)$data['cohort_id'],
            ':lesson_id' => (int)$data['lesson_id'],
        ]);

        $stmt = $this->pdo->prepare("
            INSERT INTO student_lesson_deadline_overrides
            (
                user_id,
                cohort_id,
                lesson_id,
                override_type,
                base_deadline_utc,
                new_deadline_utc,
                granted_reason_code,
                granted_reason_text,
                approval_source,
                is_active,
                granted_at,
                granted_by_user_id,
                logic_version
            )
            VALUES
            (
                :user_id,
                :cohort_id,
                :lesson_id,
                :override_type,
                :base_deadline_utc,
                :new_deadline_utc,
                :granted_reason_code,
                :granted_reason_text,
                :approval_source,
                1,
                :granted_at,
                :granted_by_user_id,
                :logic_version
            )
        ");
        $stmt->execute([
            ':user_id' => (int)$data['user_id'],
            ':cohort_id' => (int)$data['cohort_id'],
            ':lesson_id' => (int)$data['lesson_id'],
            ':override_type' => (string)$data['override_type'],
            ':base_deadline_utc' => (string)$data['base_deadline_utc'],
            ':new_deadline_utc' => (string)$data['new_deadline_utc'],
            ':granted_reason_code' => isset($data['granted_reason_code']) ? (string)$data['granted_reason_code'] : null,
            ':granted_reason_text' => isset($data['granted_reason_text']) ? (string)$data['granted_reason_text'] : null,
            ':approval_source' => (string)$data['approval_source'],
            ':granted_at' => gmdate('Y-m-d H:i:s'),
            ':granted_by_user_id' => isset($data['granted_by_user_id']) ? (int)$data['granted_by_user_id'] : null,
            ':logic_version' => static::LOGIC_VERSION,
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    public function persistLessonActivityProjection(int $userId, int $cohortId, int $lessonId, array $projection): array
    {
        if (empty($projection['engine_projection']) || !is_array($projection['fields'] ?? null)) {
            throw new InvalidArgumentException('persistLessonActivityProjection only accepts canonical output from computeLessonActivityProjection().');
        }

        if (
            (int)$projection['user_id'] !== $userId
            || (int)$projection['cohort_id'] !== $cohortId
            || (int)$projection['lesson_id'] !== $lessonId
        ) {
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
}