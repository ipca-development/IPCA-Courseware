<?php
declare(strict_types=1);

trait CoursewareProgressionV2Support
{
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

    private function escapeHtml(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function htmlToTextSafe(string $html): string
    {
        require_once __DIR__ . '/mailer.php';
        return cw_mail_html_to_text($html);
    }

    private function formatUtcForDisplay(?string $value): string
    {
        $value = trim((string)$value);
        if ($value === '') {
            return '';
        }

        try {
            $dt = new DateTime($value, new DateTimeZone('UTC'));
            return $dt->format('D M j, Y, H:i') . ' UTC';
        } catch (Throwable $e) {
            return $value . ' UTC';
        }
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

    private function buildDeadlineReasonRequiredActionData(array $data): array
    {
        $userId = (int)$data['user_id'];
        $cohortId = (int)$data['cohort_id'];
        $lessonId = (int)$data['lesson_id'];
        $progressTestId = isset($data['progress_test_id']) ? (int)$data['progress_test_id'] : null;
        $lessonTitle = trim((string)($data['lesson_title'] ?? ('Lesson ' . $lessonId)));
        $cohortTitle = trim((string)($data['cohort_title'] ?? ('Cohort ' . $cohortId)));
        $studentName = trim((string)($data['student_name'] ?? 'Student'));

        $oldEffectiveDeadlineUtc = trim((string)($data['old_effective_deadline_utc'] ?? ''));
        $oldEffectiveDeadlineDisplay = trim((string)($data['old_effective_deadline_display'] ?? ''));
        $newEffectiveDeadlineUtc = trim((string)($data['new_effective_deadline_utc'] ?? ''));
        $newEffectiveDeadlineDisplay = trim((string)($data['new_effective_deadline_display'] ?? ''));

        if ($oldEffectiveDeadlineDisplay === '') {
            $oldEffectiveDeadlineDisplay = $this->formatUtcForDisplay($oldEffectiveDeadlineUtc);
        }
        if ($newEffectiveDeadlineDisplay === '') {
            $newEffectiveDeadlineDisplay = $this->formatUtcForDisplay($newEffectiveDeadlineUtc);
        }

        $warningLevel = trim((string)($data['warning_level'] ?? 'standard'));

        $token = bin2hex(random_bytes(32));
        $title = 'Action Required - Deadline Reason Submission - ' . $lessonTitle;

        $warningHtml = '';
        $warningText = '';

        if ($warningLevel === 'final_warning') {
            $warningHtml = '<p><strong>Important:</strong> this is your final automatic deadline extension for this lesson. If this deadline is missed again, instructor intervention will be required.</p>';
            $warningText = "Important: this is your final automatic deadline extension for this lesson. If this deadline is missed again, instructor intervention will be required.\n\n";
        }

        $instructionsHtml =
            '<p>Dear ' . $this->escapeHtml($studentName) . ',</p>'
            . '<p>The deadline for <strong>' . $this->escapeHtml($lessonTitle) . '</strong> in <strong>' . $this->escapeHtml($cohortTitle) . '</strong> has been missed.</p>'
            . '<p><strong>Previous effective deadline:</strong> ' . $this->escapeHtml($oldEffectiveDeadlineDisplay) . '</p>'
            . '<p><strong>New effective deadline:</strong> ' . $this->escapeHtml($newEffectiveDeadlineDisplay) . '</p>'
            . $warningHtml
            . '<p>Please explain clearly why the deadline was missed, what the root cause was, and what you will do differently to prevent this from happening again.</p>';

        $instructionsText =
            'Dear ' . $studentName . ",\n\n"
            . 'The deadline for "' . $lessonTitle . '" in "' . $cohortTitle . "\" has been missed.\n\n"
            . 'Previous effective deadline: ' . $oldEffectiveDeadlineDisplay . "\n"
            . 'New effective deadline: ' . $newEffectiveDeadlineDisplay . "\n\n"
            . $warningText
            . 'Please explain clearly why the deadline was missed, what the root cause was, and what you will do differently to prevent this from happening again.';

        return [
            'user_id' => $userId,
            'cohort_id' => $cohortId,
            'lesson_id' => $lessonId,
            'progress_test_id' => $progressTestId,
            'action_type' => 'deadline_reason_submission',
            'token' => $token,
            'title' => $title,
            'instructions_html' => $instructionsHtml,
            'instructions_text' => $instructionsText,
        ];
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

    private function persistProgressionConsequences(
        int $progressTestId,
        array $classification,
        array $decision,
        array $requiredActions,
        array $projection,
        array $options = []
    ): array {
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
            ':logic_version' => static::LOGIC_VERSION,
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

    private function dispatchAutomationEventIfAvailable(
        string $eventKey,
        array $automationContext,
        ?int $userId = null,
        ?int $cohortId = null,
        ?int $lessonId = null,
        ?int $progressTestId = null
    ): ?array {
        if ($eventKey === '') {
            return null;
        }

        if (!is_file(__DIR__ . '/automation_runtime.php')) {
            $this->logProgressionEvent([
                'user_id' => (int)($userId ?? ($automationContext['user_id'] ?? 0)),
                'cohort_id' => (int)($cohortId ?? ($automationContext['cohort_id'] ?? 0)),
                'lesson_id' => (int)($lessonId ?? ($automationContext['lesson_id'] ?? 0)),
                'progress_test_id' => $progressTestId ?? (isset($automationContext['progress_test_id']) ? (int)$automationContext['progress_test_id'] : null),
                'event_type' => 'automation',
                'event_code' => 'automation_runtime_missing',
                'event_status' => 'warning',
                'actor_type' => 'system',
                'actor_user_id' => null,
                'event_time' => gmdate('Y-m-d H:i:s'),
                'payload' => [
                    'event_key' => $eventKey,
                    'source' => 'courseware_progression_v2',
                ],
                'legal_note' => 'Automation event skipped because automation_runtime.php is missing.',
            ]);
            return null;
        }

        if (!class_exists('AutomationRuntime')) {
            require_once __DIR__ . '/automation_runtime.php';
        }

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
}