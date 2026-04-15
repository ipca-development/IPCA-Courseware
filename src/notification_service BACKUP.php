<?php
declare(strict_types=1);

require_once __DIR__ . '/courseware_progression_v2.php';
require_once __DIR__ . '/mailer.php';

final class NotificationService
{
    private const CHANNEL_EMAIL = 'email';

    private PDO $pdo;
    private CoursewareProgressionV2 $engine;

    public function __construct(PDO $pdo, ?CoursewareProgressionV2 $engine = null)
    {
        $this->pdo = $pdo;
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->engine = $engine ?: new CoursewareProgressionV2($pdo);
    }

    /**
     * Render preview for current draft.
     * No queue row.
     * No workflow side effects.
     * Logs notification_preview_rendered.
     */
    public function renderPreview(
        string $notificationKey,
        array $draft,
        ?int $actorUserId = null,
        array $contextOverrides = []
    ): array {
        $template = $this->getTemplateByKey($notificationKey);
        if (!$template) {
            throw new RuntimeException('Notification template not found: ' . $notificationKey);
        }

        $working = $this->applyDraftToTemplate($template, $draft);
        $variables = $this->decodeAllowedVariables((string)($working['allowed_variables_json'] ?? ''));
        $context = $this->buildDummyContext($notificationKey, $variables);

        if ($contextOverrides) {
            $context = array_merge($context, $this->normalizeContextValues($contextOverrides));
        }

        $validation = $this->validateTemplateVariables(
            (string)$working['subject_template'],
            (string)$working['html_template'],
            (string)($working['text_template'] ?? ''),
            $variables,
            $context
        );

        $rendered = $this->renderDraft(
            (string)$working['subject_template'],
            (string)$working['html_template'],
            (string)($working['text_template'] ?? ''),
            $variables,
            $context
        );

        $this->engine->logProgressionEvent([
            'user_id' => 0,
            'cohort_id' => 0,
            'lesson_id' => 0,
            'progress_test_id' => null,
            'event_type' => 'notification_admin',
            'event_code' => 'notification_preview_rendered',
            'event_status' => 'info',
            'actor_type' => 'admin',
            'actor_user_id' => $actorUserId,
            'event_time' => gmdate('Y-m-d H:i:s'),
            'payload' => [
                'notification_key' => $notificationKey,
                'notification_channel' => self::CHANNEL_EMAIL,
                'notification_template_id' => (int)$template['id'],
                'used_draft' => 1,
                'unknown_tokens' => $validation['unknown_tokens'],
                'missing_required_variables' => $validation['missing_required_variables'],
                'context_keys' => array_keys($context),
            ],
            'legal_note' => 'Admin preview render executed without progression side effects.'
        ]);

        return [
            'ok' => true,
            'notification_key' => $notificationKey,
            'template_id' => (int)$template['id'],
            'used_draft' => true,
            'validation' => $validation,
            'variables' => $variables,
            'context' => $context,
            'rendered_subject' => $rendered['subject'],
            'rendered_html' => $rendered['html'],
            'rendered_text' => $rendered['text'],
        ];
    }

    /**
     * Test send current draft with dummy/manual context.
     * No queue row.
     * No workflow side effects.
     * Sends directly via mailer with enforced test markers.
     */
    public function sendTest(
        string $notificationKey,
        array $draft,
        string $toEmail,
        ?string $toName = '',
        ?int $actorUserId = null,
        array $contextOverrides = []
    ): array {
        $toEmail = trim($toEmail);
        $toName = trim((string)$toName);

        if ($toEmail === '') {
            throw new InvalidArgumentException('Missing test recipient email.');
        }
        if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Invalid test recipient email.');
        }

        $template = $this->getTemplateByKey($notificationKey);
        if (!$template) {
            throw new RuntimeException('Notification template not found: ' . $notificationKey);
        }

        $working = $this->applyDraftToTemplate($template, $draft);
        $variables = $this->decodeAllowedVariables((string)($working['allowed_variables_json'] ?? ''));
        $context = $this->buildDummyContext($notificationKey, $variables);

        if ($contextOverrides) {
            $context = array_merge($context, $this->normalizeContextValues($contextOverrides));
        }

        $validation = $this->validateTemplateVariables(
            (string)$working['subject_template'],
            (string)$working['html_template'],
            (string)($working['text_template'] ?? ''),
            $variables,
            $context
        );

        $rendered = $this->renderDraft(
            (string)$working['subject_template'],
            (string)$working['html_template'],
            (string)($working['text_template'] ?? ''),
            $variables,
            $context
        );

        $testRendered = $this->injectTestMarkers(
            $rendered['subject'],
            $rendered['html'],
            $rendered['text']
        );

        $result = cw_send_mail([
            'to' => [[
                'email' => $toEmail,
                'name' => $toName
            ]],
            'subject' => $testRendered['subject'],
            'html' => $testRendered['html'],
            'text' => $testRendered['text'],
            'headers' => [
                'X-IPCA-Notification-Test' => '1',
                'X-IPCA-Notification-Key' => $notificationKey,
            ]
        ]);

        $this->engine->logProgressionEvent([
            'user_id' => 0,
            'cohort_id' => 0,
            'lesson_id' => 0,
            'progress_test_id' => null,
            'event_type' => 'notification_admin',
            'event_code' => !empty($result['ok']) ? 'notification_test_sent' : 'notification_test_failed',
            'event_status' => !empty($result['ok']) ? 'success' : 'warning',
            'actor_type' => 'admin',
            'actor_user_id' => $actorUserId,
            'event_time' => gmdate('Y-m-d H:i:s'),
            'payload' => [
                'notification_key' => $notificationKey,
                'notification_channel' => self::CHANNEL_EMAIL,
                'notification_template_id' => (int)$template['id'],
                'used_draft' => 1,
                'target_email' => $toEmail,
                'target_name' => $toName,
                'rendered_subject' => $testRendered['subject'],
                'unknown_tokens' => $validation['unknown_tokens'],
                'missing_required_variables' => $validation['missing_required_variables'],
                'provider' => (string)($result['provider'] ?? 'smtp'),
                'ok' => !empty($result['ok']) ? 1 : 0,
                'message_id' => $result['message_id'] ?? null,
                'error' => $result['error'] ?? null,
            ],
            'legal_note' => 'Admin test notification send executed without progression side effects.'
        ]);

        return [
            'ok' => !empty($result['ok']),
            'notification_key' => $notificationKey,
            'provider' => (string)($result['provider'] ?? 'smtp'),
            'message_id' => $result['message_id'] ?? null,
            'error' => $result['error'] ?? null,
            'validation' => $validation,
            'rendered_subject' => $testRendered['subject'],
        ];
    }

    /**
     * Send a non-progression system/account notification using the SAVED live template.
     * This is intended for auth/account flows such as password reset.
     * It does not create a training_progression_emails row.
     */
    public function sendSystemNotification(
        string $notificationKey,
        string $toEmail,
        ?string $toName = '',
        array $context = [],
        ?int $actorUserId = null,
        array $headers = []
    ): array {
        $toEmail = trim($toEmail);
        $toName = trim((string)$toName);

        if ($toEmail === '') {
            throw new InvalidArgumentException('Missing recipient email.');
        }
        if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Invalid recipient email.');
        }

        $template = $this->getTemplateByKey($notificationKey);
        if (!$template) {
            throw new RuntimeException('Notification template not found: ' . $notificationKey);
        }

        if ((int)($template['is_enabled'] ?? 0) !== 1) {
            $this->engine->logProgressionEvent([
                'user_id' => 0,
                'cohort_id' => 0,
                'lesson_id' => 0,
                'progress_test_id' => null,
                'event_type' => 'notification_system',
                'event_code' => 'system_notification_suppressed',
                'event_status' => 'info',
                'actor_type' => 'system',
                'actor_user_id' => $actorUserId,
                'event_time' => gmdate('Y-m-d H:i:s'),
                'payload' => [
                    'notification_key' => $notificationKey,
                    'notification_channel' => self::CHANNEL_EMAIL,
                    'notification_template_id' => (int)$template['id'],
                    'reason' => 'disabled',
                    'target_email' => $toEmail,
                    'target_name' => $toName,
                ],
                'legal_note' => 'System notification suppressed because template is disabled.'
            ]);

            return [
                'ok' => true,
                'suppressed' => true,
                'reason' => 'disabled',
                'notification_key' => $notificationKey,
                'provider' => null,
                'message_id' => null,
                'error' => null,
            ];
        }

        $version = $this->getLatestTemplateVersion((int)$template['id']);
        if (!$version) {
            throw new RuntimeException('No live template version found for notification: ' . $notificationKey);
        }

        $variables = $this->decodeAllowedVariables((string)($version['allowed_variables_json'] ?? ''));
        $normalizedContext = $this->normalizeContextValues($context);

        $validation = $this->validateTemplateVariables(
            (string)$version['subject_template'],
            (string)$version['html_template'],
            (string)($version['text_template'] ?? ''),
            $variables,
            $normalizedContext
        );

        if (!empty($validation['missing_required_variables'])) {
            throw new RuntimeException(
                'Missing required notification variables: ' . implode(', ', $validation['missing_required_variables'])
            );
        }

        if (!empty($validation['unknown_tokens'])) {
            throw new RuntimeException(
                'Unknown notification template tokens: ' . implode(', ', $validation['unknown_tokens'])
            );
        }

        $rendered = $this->renderDraft(
            (string)$version['subject_template'],
            (string)$version['html_template'],
            (string)($version['text_template'] ?? ''),
            $variables,
            $normalizedContext
        );

        $mailPayload = [
            'to' => [[
                'email' => $toEmail,
                'name' => $toName
            ]],
            'subject' => $rendered['subject'],
            'html' => $rendered['html'],
            'text' => $rendered['text'],
            'headers' => array_merge([
                'X-IPCA-Notification-Key' => $notificationKey,
                'X-IPCA-Notification-Type' => 'system',
            ], $headers),
        ];

        $result = cw_send_mail($mailPayload);

        $this->engine->logProgressionEvent([
            'user_id' => 0,
            'cohort_id' => 0,
            'lesson_id' => 0,
            'progress_test_id' => null,
            'event_type' => 'notification_system',
            'event_code' => !empty($result['ok']) ? 'system_notification_sent' : 'system_notification_failed',
            'event_status' => !empty($result['ok']) ? 'success' : 'warning',
            'actor_type' => 'system',
            'actor_user_id' => $actorUserId,
            'event_time' => gmdate('Y-m-d H:i:s'),
            'payload' => [
                'notification_key' => $notificationKey,
                'notification_channel' => self::CHANNEL_EMAIL,
                'notification_template_id' => (int)$template['id'],
                'notification_template_version_id' => (int)$version['id'],
                'target_email' => $toEmail,
                'target_name' => $toName,
                'provider' => (string)($result['provider'] ?? 'smtp'),
                'ok' => !empty($result['ok']) ? 1 : 0,
                'message_id' => $result['message_id'] ?? null,
                'error' => $result['error'] ?? null,
                'context_keys' => array_keys($normalizedContext),
            ],
            'legal_note' => 'System/account notification send executed outside progression email queue.'
        ]);

          return [
            'ok' => !empty($result['ok']),
            'suppressed' => false,
            'notification_key' => $notificationKey,
            'provider' => (string)($result['provider'] ?? 'smtp'),
            'message_id' => $result['message_id'] ?? null,
            'error' => $result['error'] ?? null,
            'validation' => $validation,
            'rendered_subject' => $rendered['subject'],
            'rendered_html' => $rendered['html'],
            'rendered_text' => $rendered['text'],
            'template_id' => (int)$template['id'],
            'template_version_id' => (int)$version['id'],
        ];
    }

    /**
     * Render a non-progression live system/account notification using the SAVED live template.
     * Useful for password-reset QA without sending.
     */
    public function renderSystemNotificationPreview(
        string $notificationKey,
        array $context = [],
        ?int $actorUserId = null
    ): array {
        $template = $this->getTemplateByKey($notificationKey);
        if (!$template) {
            throw new RuntimeException('Notification template not found: ' . $notificationKey);
        }

        $version = $this->getLatestTemplateVersion((int)$template['id']);
        if (!$version) {
            throw new RuntimeException('No live template version found for notification: ' . $notificationKey);
        }

        $variables = $this->decodeAllowedVariables((string)($version['allowed_variables_json'] ?? ''));
        $normalizedContext = $this->normalizeContextValues($context);

        $validation = $this->validateTemplateVariables(
            (string)$version['subject_template'],
            (string)$version['html_template'],
            (string)($version['text_template'] ?? ''),
            $variables,
            $normalizedContext
        );

        if (!empty($validation['missing_required_variables'])) {
            throw new RuntimeException(
                'Missing required notification variables: ' . implode(', ', $validation['missing_required_variables'])
            );
        }

        if (!empty($validation['unknown_tokens'])) {
            throw new RuntimeException(
                'Unknown notification template tokens: ' . implode(', ', $validation['unknown_tokens'])
            );
        }

        $rendered = $this->renderDraft(
            (string)$version['subject_template'],
            (string)$version['html_template'],
            (string)($version['text_template'] ?? ''),
            $variables,
            $normalizedContext
        );

        $this->engine->logProgressionEvent([
            'user_id' => 0,
            'cohort_id' => 0,
            'lesson_id' => 0,
            'progress_test_id' => null,
            'event_type' => 'notification_system',
            'event_code' => 'system_notification_preview_rendered',
            'event_status' => 'info',
            'actor_type' => 'system',
            'actor_user_id' => $actorUserId,
            'event_time' => gmdate('Y-m-d H:i:s'),
            'payload' => [
                'notification_key' => $notificationKey,
                'notification_channel' => self::CHANNEL_EMAIL,
                'notification_template_id' => (int)$template['id'],
                'notification_template_version_id' => (int)$version['id'],
                'context_keys' => array_keys($normalizedContext),
            ],
            'legal_note' => 'System/account notification preview rendered without sending.'
        ]);

        return [
            'ok' => true,
            'notification_key' => $notificationKey,
            'template_id' => (int)$template['id'],
            'template_version_id' => (int)$version['id'],
            'validation' => $validation,
            'rendered_subject' => $rendered['subject'],
            'rendered_html' => $rendered['html'],
            'rendered_text' => $rendered['text'],
        ];
    }

    /**
     * Queue/send real progression notification using SAVED live template.
     * This is a passive execution path only.
     * Caller must already decide notification_key, recipients, and context.
     * Duplicate suppression happens BEFORE queue creation.
     * email_type = notification_key ALWAYS.
     */
    public function queueRealNotification(array $payload): array
    {
        $required = [
            'notification_key',
            'user_id',
            'cohort_id',
            'lesson_id',
            'recipients_to',
            'context',
        ];

        foreach ($required as $field) {
            if (!array_key_exists($field, $payload)) {
                throw new InvalidArgumentException('Missing required field: ' . $field);
            }
        }

        $notificationKey = trim((string)$payload['notification_key']);
        if ($notificationKey === '') {
            throw new InvalidArgumentException('Missing required field: notification_key');
        }

        $template = $this->getTemplateByKey($notificationKey);
        if (!$template) {
            throw new RuntimeException('Notification template not found: ' . $notificationKey);
        }

        if ((int)($template['is_enabled'] ?? 0) !== 1) {
            $this->engine->logProgressionEvent([
                'user_id' => (int)$payload['user_id'],
                'cohort_id' => (int)$payload['cohort_id'],
                'lesson_id' => (int)$payload['lesson_id'],
                'progress_test_id' => isset($payload['progress_test_id']) && $payload['progress_test_id'] !== null
                    ? (int)$payload['progress_test_id']
                    : null,
                'event_type' => 'notification',
                'event_code' => 'progression_email_failed_configuration',
                'event_status' => 'warning',
                'actor_type' => trim((string)($payload['actor_type'] ?? 'system')) !== ''
                    ? (string)$payload['actor_type']
                    : 'system',
                'actor_user_id' => isset($payload['actor_user_id']) ? (int)$payload['actor_user_id'] : null,
                'event_time' => gmdate('Y-m-d H:i:s'),
                'payload' => [
                    'notification_key' => $notificationKey,
                    'notification_channel' => self::CHANNEL_EMAIL,
                    'notification_template_id' => (int)$template['id'],
                    'reason' => 'disabled',
                ],
                'legal_note' => 'Progression notification request failed because the live template is disabled.'
            ]);

            throw new RuntimeException('Notification template disabled: ' . $notificationKey);
        }

        $deliveryMode = (string)($template['delivery_mode'] ?? 'immediate');
        $userId = (int)$payload['user_id'];
        $cohortId = (int)$payload['cohort_id'];
        $lessonId = (int)$payload['lesson_id'];
        $progressTestId = isset($payload['progress_test_id']) && $payload['progress_test_id'] !== null
            ? (int)$payload['progress_test_id']
            : null;
        $actorType = trim((string)($payload['actor_type'] ?? 'system'));
        if ($actorType === '') {
            $actorType = 'system';
        }
        $actorUserId = isset($payload['actor_user_id']) ? (int)$payload['actor_user_id'] : null;

        $duplicateStrategy = trim((string)($template['duplicate_strategy'] ?? ''));
        if ($duplicateStrategy !== '') {
            $existingEmailId = $this->findExistingProgressionEmailByDuplicateStrategy(
                $notificationKey,
                $duplicateStrategy,
                $userId,
                $cohortId,
                $lessonId,
                $progressTestId
            );

            if ($existingEmailId !== null) {
                $this->engine->logProgressionEvent([
                    'user_id' => $userId,
                    'cohort_id' => $cohortId,
                    'lesson_id' => $lessonId,
                    'progress_test_id' => $progressTestId,
                    'event_type' => 'notification',
                    'event_code' => 'progression_email_suppressed',
                    'event_status' => 'info',
                    'actor_type' => $actorType,
                    'actor_user_id' => $actorUserId,
                    'event_time' => gmdate('Y-m-d H:i:s'),
                    'payload' => [
                        'notification_key' => $notificationKey,
                        'notification_channel' => self::CHANNEL_EMAIL,
                        'notification_template_id' => (int)$template['id'],
                        'reason' => 'duplicate_suppressed',
                        'duplicate_strategy' => $duplicateStrategy,
                        'existing_email_id' => $existingEmailId,
                        'recipients_to' => $payload['recipients_to'],
                        'recipients_cc' => $payload['recipients_cc'] ?? [],
                    ],
                    'legal_note' => 'Notification suppressed before queue creation by configured duplicate strategy.'
                ]);

                return [
                    'ok' => true,
                    'suppressed' => true,
                    'reason' => 'duplicate_suppressed',
                    'notification_key' => $notificationKey,
                    'duplicate_strategy' => $duplicateStrategy,
                    'email_id' => $existingEmailId,
                    'send_result' => null,
                ];
            }
        }

        $version = $this->getLatestTemplateVersion((int)$template['id']);
        if (!$version) {
            throw new RuntimeException('No live template version found for notification: ' . $notificationKey);
        }

        $variables = $this->decodeAllowedVariables((string)($version['allowed_variables_json'] ?? ''));
        $context = $this->normalizeContextValues((array)$payload['context']);

        $validation = $this->validateTemplateVariables(
            (string)$version['subject_template'],
            (string)$version['html_template'],
            (string)($version['text_template'] ?? ''),
            $variables,
            $context
        );

        if (!empty($validation['missing_required_variables'])) {
            throw new RuntimeException(
                'Missing required notification variables: ' . implode(', ', $validation['missing_required_variables'])
            );
        }

        if (!empty($validation['unknown_tokens'])) {
            throw new RuntimeException(
                'Unknown notification template tokens: ' . implode(', ', $validation['unknown_tokens'])
            );
        }

        $rendered = $this->renderDraft(
            (string)$version['subject_template'],
            (string)$version['html_template'],
            (string)($version['text_template'] ?? ''),
            $variables,
            $context
        );

        $emailId = $this->logProgressionNotificationFromTemplate(
            $notificationKey,
            $userId,
            $cohortId,
            $lessonId,
            $progressTestId,
            $payload['recipients_to'],
            $payload['recipients_cc'] ?? [],
            $rendered['subject'],
            $rendered['html'],
            $rendered['text'],
            $context,
            isset($payload['ai_inputs']) ? $payload['ai_inputs'] : null,
            (int)$template['id'],
            (int)$version['id']
        );

        $sendResult = null;
        if ($deliveryMode === 'immediate') {
            $sendResult = $this->sendLoggedProgressionEmailById($emailId);
        }

        return [
            'ok' => true,
            'suppressed' => false,
            'notification_key' => $notificationKey,
            'email_id' => $emailId,
            'delivery_mode' => $deliveryMode,
            'send_result' => $sendResult,
        ];
    }

    /**
     * Public progression log helper.
     * email_type = notification_key ALWAYS.
     */
    public function logProgressionNotificationFromTemplate(
        string $notificationKey,
        int $userId,
        int $cohortId,
        int $lessonId,
        ?int $progressTestId,
        mixed $recipientsTo,
        mixed $recipientsCc,
        string $subject,
        string $bodyHtml,
        ?string $bodyText,
        array $renderContext,
        mixed $aiInputs = null,
        ?int $notificationTemplateId = null,
        ?int $notificationTemplateVersionId = null
    ): int {
        return $this->queueProgressionEmailExtended([
            'user_id' => $userId,
            'cohort_id' => $cohortId,
            'lesson_id' => $lessonId,
            'progress_test_id' => $progressTestId,
            'email_type' => $notificationKey,
            'recipients_to' => $recipientsTo,
            'recipients_cc' => $recipientsCc,
            'subject' => $subject,
            'body_html' => $bodyHtml,
            'body_text' => $bodyText,
            'ai_inputs' => $aiInputs,
            'sent_status' => 'queued',
            'notification_template_id' => $notificationTemplateId,
            'notification_template_version_id' => $notificationTemplateVersionId,
            'render_context_json' => $renderContext,
        ]);
    }

    /**
     * Public logged progression email sender.
     * Sends exactly the already-logged row.
     */
    public function sendLoggedProgressionEmailById(int $emailId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT *
            FROM training_progression_emails
            WHERE id = :id
            LIMIT 1
        ");
        $stmt->execute([
            ':id' => $emailId,
        ]);
        $email = $stmt->fetch();

        if (!$email) {
            throw new RuntimeException('Progression email not found: ' . $emailId);
        }

        $recipientsTo = $this->decodeRecipientField((string)$email['recipients_to']);
        $recipientsCc = $this->decodeRecipientField((string)($email['recipients_cc'] ?? ''));

        $mailPayload = [
            'to' => $recipientsTo,
            'subject' => (string)$email['subject'],
            'html' => (string)$email['body_html'],
            'text' => (string)($email['body_text'] ?? ''),
            'headers' => [
                'X-IPCA-Notification-Key' => (string)$email['email_type'],
                'X-IPCA-Notification-Type' => 'progression',
            ],
        ];

        if (!empty($recipientsCc)) {
            $mailPayload['cc'] = $recipientsCc;
        }

        $result = cw_send_mail($mailPayload);

        $sentStatus = !empty($result['ok']) ? 'sent' : 'failed';
        $sentAt = !empty($result['ok']) ? gmdate('Y-m-d H:i:s') : null;

        if (!empty($result['ok'])) {
            $stmt = $this->pdo->prepare("
                UPDATE training_progression_emails
                SET
                    sent_status = :sent_status,
                    sent_at = :sent_at
                WHERE id = :id
                LIMIT 1
            ");
            $stmt->execute([
                ':sent_status' => $sentStatus,
                ':sent_at' => $sentAt,
                ':id' => $emailId,
            ]);
        } else {
            $stmt = $this->pdo->prepare("
                UPDATE training_progression_emails
                SET
                    sent_status = :sent_status
                WHERE id = :id
                LIMIT 1
            ");
            $stmt->execute([
                ':sent_status' => $sentStatus,
                ':id' => $emailId,
            ]);
        }

        $this->engine->logProgressionEvent([
            'user_id' => (int)$email['user_id'],
            'cohort_id' => (int)$email['cohort_id'],
            'lesson_id' => (int)$email['lesson_id'],
            'progress_test_id' => isset($email['progress_test_id']) && $email['progress_test_id'] !== null ? (int)$email['progress_test_id'] : null,
            'event_type' => 'notification',
            'event_code' => !empty($result['ok']) ? 'progression_email_sent' : 'progression_email_failed',
            'event_status' => !empty($result['ok']) ? 'success' : 'warning',
            'actor_type' => 'system',
            'actor_user_id' => null,
            'event_time' => gmdate('Y-m-d H:i:s'),
            'payload' => [
                'notification_key' => (string)$email['email_type'],
                'notification_channel' => self::CHANNEL_EMAIL,
                'email_id' => $emailId,
                'notification_template_id' => isset($email['notification_template_id']) && $email['notification_template_id'] !== null ? (int)$email['notification_template_id'] : null,
                'notification_template_version_id' => isset($email['notification_template_version_id']) && $email['notification_template_version_id'] !== null ? (int)$email['notification_template_version_id'] : null,
                'provider' => (string)($result['provider'] ?? 'smtp'),
                'ok' => !empty($result['ok']) ? 1 : 0,
                'message_id' => $result['message_id'] ?? null,
                'error' => $result['error'] ?? null,
            ],
            'legal_note' => 'Progression notification delivery executed from logged email row.'
        ]);

        return [
            'ok' => !empty($result['ok']),
            'provider' => (string)($result['provider'] ?? 'smtp'),
            'message_id' => $result['message_id'] ?? null,
            'error' => $result['error'] ?? null,
            'email_id' => $emailId,
            'notification_key' => (string)$email['email_type'],
        ];
    }

    /**
     * Pure helper only. No decision-making.
     */
    public function buildBaseProgressionContext(array $data): array
    {
        $context = [
            'student_name' => trim((string)($data['student_name'] ?? '')),
            'chief_name' => trim((string)($data['chief_name'] ?? '')),
            'lesson_title' => trim((string)($data['lesson_title'] ?? '')),
            'cohort_title' => trim((string)($data['cohort_title'] ?? ($data['cohort_name'] ?? ''))),
            'course_title' => trim((string)($data['course_title'] ?? '')),
            'attempt_count' => isset($data['attempt_count']) ? (string)$data['attempt_count'] : '',
            'score_pct' => isset($data['score_pct']) ? (string)$data['score_pct'] : '',
            'previous_deadline_text' => trim((string)($data['previous_deadline_text'] ?? '')),
            'new_deadline_text' => trim((string)($data['new_deadline_text'] ?? '')),
            'same_lesson_unsat_count' => isset($data['same_lesson_unsat_count']) ? (string)$data['same_lesson_unsat_count'] : '',
            'coursewide_unsat_count' => isset($data['coursewide_unsat_count']) ? (string)$data['coursewide_unsat_count'] : '',
            'decision_code' => trim((string)($data['decision_code'] ?? '')),
            'remediation_url' => trim((string)($data['remediation_url'] ?? '')),
            'approval_url' => trim((string)($data['approval_url'] ?? '')),
            'reason_submission_url' => trim((string)($data['reason_submission_url'] ?? '')),
            'weak_areas_html' => trim((string)($data['weak_areas_html'] ?? '')),
            'weak_areas_text' => trim((string)($data['weak_areas_text'] ?? '')),
            'written_debrief_html' => trim((string)($data['written_debrief_html'] ?? '')),
            'written_debrief_text' => trim((string)($data['written_debrief_text'] ?? '')),
            'decision_notes_html' => trim((string)($data['decision_notes_html'] ?? '')),
            'decision_notes_text' => trim((string)($data['decision_notes_text'] ?? '')),
            'review_notes_html' => trim((string)($data['review_notes_html'] ?? '')),
            'review_notes_text' => trim((string)($data['review_notes_text'] ?? '')),
        ];

        if (isset($data['extra']) && is_array($data['extra'])) {
            foreach ($data['extra'] as $k => $v) {
                $key = trim((string)$k);
                if ($key === '') {
                    continue;
                }

                if (is_scalar($v) || $v === null) {
                    $context[$key] = $v === null ? '' : (string)$v;
                } else {
                    $context[$key] = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }
            }
        }

        return $context;
    }

    /**
     * Passive wrapper only.
     * Caller already knows notification_key, recipients, and context.
     */
    public function queueStandardizedProgressionNotification(
        string $notificationKey,
        array $context,
        int $userId,
        int $cohortId,
        int $lessonId,
        ?int $progressTestId,
        mixed $recipientsTo,
        mixed $recipientsCc = [],
        ?string $actorType = 'system',
        ?int $actorUserId = null,
        mixed $aiInputs = null
    ): array {
        return $this->queueRealNotification([
            'notification_key' => $notificationKey,
            'user_id' => $userId,
            'cohort_id' => $cohortId,
            'lesson_id' => $lessonId,
            'progress_test_id' => $progressTestId,
            'recipients_to' => $recipientsTo,
            'recipients_cc' => $recipientsCc,
            'context' => $context,
            'actor_type' => $actorType ?: 'system',
            'actor_user_id' => $actorUserId,
            'ai_inputs' => $aiInputs,
        ]);
    }

    /**
     * Passive wrapper only.
     */
    public function queueStudentProgressionNotification(
        string $notificationKey,
        array $context,
        int $userId,
        int $cohortId,
        int $lessonId,
        ?int $progressTestId,
        string $studentEmail,
        ?string $studentName = '',
        mixed $recipientsCc = [],
        ?string $actorType = 'system',
        ?int $actorUserId = null,
        mixed $aiInputs = null
    ): array {
        $recipientsTo = [[
            'email' => trim($studentEmail),
            'name' => trim((string)$studentName),
        ]];

        return $this->queueStandardizedProgressionNotification(
            $notificationKey,
            $context,
            $userId,
            $cohortId,
            $lessonId,
            $progressTestId,
            $recipientsTo,
            $recipientsCc,
            $actorType,
            $actorUserId,
            $aiInputs
        );
    }

    /**
     * Passive wrapper only.
     */
    public function queueChiefProgressionNotification(
        string $notificationKey,
        array $context,
        int $userId,
        int $cohortId,
        int $lessonId,
        ?int $progressTestId,
        string $chiefEmail,
        ?string $chiefName = '',
        mixed $recipientsCc = [],
        ?string $actorType = 'system',
        ?int $actorUserId = null,
        mixed $aiInputs = null
    ): array {
        $recipientsTo = [[
            'email' => trim($chiefEmail),
            'name' => trim((string)$chiefName),
        ]];

        return $this->queueStandardizedProgressionNotification(
            $notificationKey,
            $context,
            $userId,
            $cohortId,
            $lessonId,
            $progressTestId,
            $recipientsTo,
            $recipientsCc,
            $actorType,
            $actorUserId,
            $aiInputs
        );
    }

    /**
     * Operational dedupe only:
     * once_per_lesson
     * once_per_progress_test
     */
    public function findExistingProgressionEmailByDuplicateStrategy(
        string $notificationKey,
        string $duplicateStrategy,
        int $userId,
        int $cohortId,
        int $lessonId,
        ?int $progressTestId = null
    ): ?int {
        $notificationKey = trim($notificationKey);
        $duplicateStrategy = trim($duplicateStrategy);

        if ($notificationKey === '' || $duplicateStrategy === '') {
            return null;
        }

        if ($duplicateStrategy === 'once_per_lesson') {
            $stmt = $this->pdo->prepare("
                SELECT id
                FROM training_progression_emails
                WHERE user_id = :user_id
                  AND cohort_id = :cohort_id
                  AND lesson_id = :lesson_id
                  AND email_type = :email_type
                ORDER BY id DESC
                LIMIT 1
            ");
            $stmt->execute([
                ':user_id' => $userId,
                ':cohort_id' => $cohortId,
                ':lesson_id' => $lessonId,
                ':email_type' => $notificationKey,
            ]);
            $id = $stmt->fetchColumn();

            return $id !== false ? (int)$id : null;
        }

        if ($duplicateStrategy === 'once_per_progress_test') {
            if ($progressTestId === null || $progressTestId <= 0) {
                return null;
            }

            $stmt = $this->pdo->prepare("
                SELECT id
                FROM training_progression_emails
                WHERE user_id = :user_id
                  AND cohort_id = :cohort_id
                  AND lesson_id = :lesson_id
                  AND progress_test_id = :progress_test_id
                  AND email_type = :email_type
                ORDER BY id DESC
                LIMIT 1
            ");
            $stmt->execute([
                ':user_id' => $userId,
                ':cohort_id' => $cohortId,
                ':lesson_id' => $lessonId,
                ':progress_test_id' => $progressTestId,
                ':email_type' => $notificationKey,
            ]);
            $id = $stmt->fetchColumn();

            return $id !== false ? (int)$id : null;
        }

        return null;
    }

    public function getTemplateByKey(string $notificationKey): ?array
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

    public function getLatestTemplateVersion(int $templateId): ?array
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
            ':notification_template_id' => $templateId,
        ]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function listTemplates(): array
    {
        $sql = "
            SELECT nt.*,
                   (
                     SELECT MAX(ntv.version_no)
                     FROM notification_template_versions ntv
                     WHERE ntv.notification_template_id = nt.id
                   ) AS live_version_no
            FROM notification_templates nt
            ORDER BY nt.notification_key ASC
        ";
        $stmt = $this->pdo->query($sql);
        return $stmt->fetchAll();
    }

    public function listTemplateVersions(int $templateId): array
    {
        $sql = "
            SELECT *
            FROM notification_template_versions
            WHERE notification_template_id = :notification_template_id
            ORDER BY version_no DESC, id DESC
        ";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':notification_template_id' => $templateId,
        ]);
        return $stmt->fetchAll();
    }

    public function saveTemplate(
        int $templateId,
        array $draft,
        ?int $actorUserId = null,
        ?string $changeNote = null
    ): array {
        $template = $this->getTemplateById($templateId);
        if (!$template) {
            throw new RuntimeException('Notification template not found.');
        }

        $subject = trim((string)($draft['subject_template'] ?? ''));
        $html = (string)($draft['html_template'] ?? '');
        $text = array_key_exists('text_template', $draft) ? (string)$draft['text_template'] : null;
        $isEnabled = array_key_exists('is_enabled', $draft) ? (int)(!empty($draft['is_enabled']) ? 1 : 0) : (int)$template['is_enabled'];

        if ($subject === '') {
            throw new InvalidArgumentException('subject_template is required.');
        }
        if (trim($html) === '') {
            throw new InvalidArgumentException('html_template is required.');
        }

        $allowedVariablesJson = (string)$template['allowed_variables_json'];
        $variables = $this->decodeAllowedVariables($allowedVariablesJson);

        $validation = $this->validateTemplateVariables(
            $subject,
            $html,
            $text !== null ? $text : (string)$template['text_template'],
            $variables,
            $this->buildDummyContext((string)$template['notification_key'], $variables)
        );

        if (!empty($validation['unknown_tokens'])) {
            throw new InvalidArgumentException(
                'Unknown notification template tokens: ' . implode(', ', $validation['unknown_tokens'])
            );
        }

        $this->pdo->beginTransaction();
        try {
            $nextVersionNo = $this->getNextVersionNumber($templateId);

            $upd = $this->pdo->prepare("
                UPDATE notification_templates
                SET
                    is_enabled = :is_enabled,
                    subject_template = :subject_template,
                    html_template = :html_template,
                    text_template = :text_template,
                    updated_by_user_id = :updated_by_user_id,
                    updated_at = NOW()
                WHERE id = :id
                LIMIT 1
            ");
            $upd->execute([
                ':is_enabled' => $isEnabled,
                ':subject_template' => $subject,
                ':html_template' => $html,
                ':text_template' => $text,
                ':updated_by_user_id' => $actorUserId,
                ':id' => $templateId,
            ]);

            $ins = $this->pdo->prepare("
                INSERT INTO notification_template_versions
                (
                    notification_template_id,
                    version_no,
                    notification_key,
                    subject_template,
                    html_template,
                    text_template,
                    allowed_variables_json,
                    changed_by_user_id,
                    change_note,
                    created_at
                )
                VALUES
                (
                    :notification_template_id,
                    :version_no,
                    :notification_key,
                    :subject_template,
                    :html_template,
                    :text_template,
                    :allowed_variables_json,
                    :changed_by_user_id,
                    :change_note,
                    NOW()
                )
            ");
            $ins->execute([
                ':notification_template_id' => $templateId,
                ':version_no' => $nextVersionNo,
                ':notification_key' => (string)$template['notification_key'],
                ':subject_template' => $subject,
                ':html_template' => $html,
                ':text_template' => $text,
                ':allowed_variables_json' => $allowedVariablesJson,
                ':changed_by_user_id' => $actorUserId,
                ':change_note' => $changeNote,
            ]);

            $versionId = (int)$this->pdo->lastInsertId();
            $this->pdo->commit();

            return [
                'ok' => true,
                'template_id' => $templateId,
                'version_id' => $versionId,
                'version_no' => $nextVersionNo,
                'validation' => $validation,
            ];
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function restoreVersion(
        int $templateId,
        int $versionId,
        ?int $actorUserId = null,
        ?string $changeNote = null
    ): array {
        $template = $this->getTemplateById($templateId);
        if (!$template) {
            throw new RuntimeException('Notification template not found.');
        }

        $version = $this->getTemplateVersionById($versionId);
        if (!$version || (int)$version['notification_template_id'] !== $templateId) {
            throw new RuntimeException('Notification template version not found.');
        }

        $note = trim((string)$changeNote);
        if ($note === '') {
            $note = 'Restored from version ' . (int)$version['version_no'];
        }

        return $this->saveTemplate($templateId, [
            'is_enabled' => (int)$template['is_enabled'],
            'subject_template' => (string)$version['subject_template'],
            'html_template' => (string)$version['html_template'],
            'text_template' => (string)$version['text_template'],
        ], $actorUserId, $note);
    }
    public function buildDummyContext(string $notificationKey, array $variables = []): array
    {
        $base = [
            'student_name' => 'John Smith',
            'chief_name' => 'Capt. Reynolds',
            'lesson_title' => 'Aerodynamics Basics',
            'cohort_title' => 'PPL Spring 2026',
            'course_title' => 'Private Pilot',
            'attempt_count' => '3',
            'score_pct' => '58',
            'previous_deadline_text' => '2026-03-20 00:00 UTC',
            'new_deadline_text' => '2026-03-23 00:00 UTC',
            'same_lesson_unsat_count' => '3',
            'coursewide_unsat_count' => '5',
            'decision_code' => 'approve_additional_attempts',
            'remediation_url' => 'https://ipca.training/student/remediation_action.php?token=EXAMPLE',
            'approval_url' => 'https://ipca.training/instructor/instructor_approval.php?token=EXAMPLE',
            'reason_submission_url' => 'https://ipca.training/student/deadline_reason.php?token=EXAMPLE',
            'weak_areas_html' => 'Checklist discipline<br>Memory item sequencing',
            'weak_areas_text' => "Checklist discipline\nMemory item sequencing",
            'written_debrief_html' => 'You showed effort but must review airflow concepts.',
            'written_debrief_text' => 'You showed effort but must review airflow concepts.',
            'decision_notes_html' => 'You may continue with two additional attempts.',
            'decision_notes_text' => 'You may continue with two additional attempts.',
            'review_notes_html' => 'Please clarify induced drag versus parasite drag.',
            'review_notes_text' => 'Please clarify induced drag versus parasite drag.',
        ];

        switch ($notificationKey) {
            case 'instructor_approval_required_chief':
                $base['attempt_count'] = '5';
                $base['score_pct'] = '54';
                $base['weak_areas_html'] = 'Checklist discipline<br>Callout accuracy';
                $base['weak_areas_text'] = "Checklist discipline\nCallout accuracy";
                break;

            case 'multiple_unsat_remedial_meeting':
                $base['same_lesson_unsat_count'] = '3';
                $base['coursewide_unsat_count'] = '5';
                break;

            case 'deadline_missed_extension_1':
            case 'final_extension_granted_last_warning':
                $base['new_deadline_text'] = '2026-03-28 00:00 UTC';
                break;

            case 'reason_rejected':
                $base['decision_notes_html'] = 'Additional documentation was required.';
                $base['decision_notes_text'] = 'Additional documentation was required.';
                break;
        }

        if (!$variables) {
            return $base;
        }

        $out = [];
        foreach ($variables as $meta) {
            $name = (string)($meta['name'] ?? '');
            if ($name === '') {
                continue;
            }

            if (array_key_exists('sample_value', $meta) && trim((string)$meta['sample_value']) !== '') {
                $out[$name] = (string)$meta['sample_value'];
                continue;
            }

            $out[$name] = array_key_exists($name, $base) ? $base[$name] : '';
        }

        return $out;
    }

    public function validateTemplateVariables(
        string $subjectTemplate,
        string $htmlTemplate,
        string $textTemplate,
        array $allowedVariables,
        array $context
    ): array {
        $tokenMap = $this->buildVariableMap($allowedVariables);
        $usedTokens = array_values(array_unique(array_merge(
            $this->extractTokens($subjectTemplate),
            $this->extractTokens($htmlTemplate),
            $this->extractTokens($textTemplate)
        )));

        $unknown = [];
        foreach ($usedTokens as $token) {
            if (!isset($tokenMap[$token])) {
                $unknown[] = $token;
            }
        }

        $missingRequired = [];
        foreach ($allowedVariables as $meta) {
            $name = (string)($meta['name'] ?? '');
            $required = !empty($meta['required']);
            if ($name === '' || !$required) {
                continue;
            }

            if (
                !array_key_exists($name, $context) ||
                (
                    !is_array($context[$name]) &&
                    trim((string)$context[$name]) === ''
                )
            ) {
                $missingRequired[] = $name;
            }
        }

        return [
            'used_tokens' => $usedTokens,
            'unknown_tokens' => array_values(array_unique($unknown)),
            'missing_required_variables' => array_values(array_unique($missingRequired)),
        ];
    }

    public function renderDraft(
        string $subjectTemplate,
        string $htmlTemplate,
        string $textTemplate,
        array $allowedVariables,
        array $context
    ): array {
        $variableMap = $this->buildVariableMap($allowedVariables);

        $subject = $this->renderTemplateString($subjectTemplate, $variableMap, $context, false);
		$subject = html_entity_decode($subject, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $html = $this->renderTemplateString($htmlTemplate, $variableMap, $context, true);
        $text = trim($textTemplate) !== ''
            ? $this->renderTemplateString($textTemplate, $variableMap, $context, false)
            : cw_mail_html_to_text($html);

        return [
            'subject' => $subject,
            'html' => $html,
            'text' => $text,
        ];
    }

    private function getTemplateById(int $templateId): ?array
    {
        $sql = "
            SELECT *
            FROM notification_templates
            WHERE id = :id
            LIMIT 1
        ";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':id' => $templateId,
        ]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private function getTemplateVersionById(int $versionId): ?array
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

    private function getNextVersionNumber(int $templateId): int
    {
        $sql = "
            SELECT COALESCE(MAX(version_no), 0) + 1
            FROM notification_template_versions
            WHERE notification_template_id = :notification_template_id
        ";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':notification_template_id' => $templateId,
        ]);
        $next = (int)$stmt->fetchColumn();
        return $next > 0 ? $next : 1;
    }

    private function applyDraftToTemplate(array $template, array $draft): array
    {
        $working = $template;

        if (array_key_exists('subject_template', $draft)) {
            $working['subject_template'] = (string)$draft['subject_template'];
        }
        if (array_key_exists('html_template', $draft)) {
            $working['html_template'] = (string)$draft['html_template'];
        }
        if (array_key_exists('text_template', $draft)) {
            $working['text_template'] = (string)$draft['text_template'];
        }
        if (array_key_exists('is_enabled', $draft)) {
            $working['is_enabled'] = !empty($draft['is_enabled']) ? 1 : 0;
        }

        return $working;
    }

    private function decodeAllowedVariables(string $json): array
    {
        $json = trim($json);
        if ($json === '') {
            return [];
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Invalid allowed_variables_json on notification template.');
        }

        $out = [];
        foreach ($decoded as $row) {
            if (!is_array($row)) {
                continue;
            }

            $name = trim((string)($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $out[] = [
                'name' => $name,
                'label' => trim((string)($row['label'] ?? $name)),
                'type' => trim((string)($row['type'] ?? 'text')),
                'safe_mode' => trim((string)($row['safe_mode'] ?? 'escaped')),
                'required' => !empty($row['required']),
                'sample_value' => (string)($row['sample_value'] ?? ''),
                'description' => trim((string)($row['description'] ?? '')),
            ];
        }

        return $out;
    }

    private function buildVariableMap(array $allowedVariables): array
    {
        $map = [];
        foreach ($allowedVariables as $meta) {
            $name = (string)($meta['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $map[$name] = $meta;
        }
        return $map;
    }

    private function extractTokens(string $template): array
    {
        if ($template === '') {
            return [];
        }

        preg_match_all('/\{\{([a-zA-Z0-9_]+)\}\}/', $template, $matches);
        $tokens = isset($matches[1]) && is_array($matches[1]) ? $matches[1] : [];

        $out = [];
        foreach ($tokens as $token) {
            $token = trim((string)$token);
            if ($token === '') {
                continue;
            }
            if (strpos($token, '{{') !== false || strpos($token, '}}') !== false) {
                continue;
            }
            $out[] = $token;
        }

        return array_values(array_unique($out));
    }

    private function renderTemplateString(
        string $template,
        array $variableMap,
        array $context,
        bool $htmlMode
    ): string {
        return (string)preg_replace_callback(
            '/\{\{([a-zA-Z0-9_]+)\}\}/',
            function (array $m) use ($variableMap, $context, $htmlMode): string {
                $name = trim((string)($m[1] ?? ''));
                if ($name === '' || !isset($variableMap[$name])) {
                    return '';
                }

                $meta = $variableMap[$name];
                $safeMode = (string)($meta['safe_mode'] ?? 'escaped');
                $value = array_key_exists($name, $context) ? $context[$name] : '';

                if (is_array($value)) {
                    $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }
                $value = (string)$value;

                if ($htmlMode && $safeMode === 'approved_html') {
                    return $value;
                }

                return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            },
            $template
        );
    }

    private function normalizeContextValues(array $context): array
    {
        $out = [];
        foreach ($context as $k => $v) {
            $key = trim((string)$k);
            if ($key === '') {
                continue;
            }

            if (is_scalar($v) || $v === null) {
                $out[$key] = $v === null ? '' : (string)$v;
            } else {
                $out[$key] = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }
        return $out;
    }

    private function injectTestMarkers(string $subject, string $html, string $text): array
    {
        $subject = '[TEST] ' . ltrim($subject);

        $bannerHtml = ''
            . '<div style="padding:12px 14px;margin:0 0 16px 0;'
            . 'background:#fff3cd;border:1px solid #ffe69c;'
            . 'color:#664d03;font-family:Arial,sans-serif;'
            . 'font-size:14px;font-weight:700;">'
            . 'TEST EMAIL — This message was generated from a notification draft using dummy data. '
            . 'It does not represent a real progression event.'
            . '</div>';

        $bannerText = "TEST EMAIL — This message was generated from a notification draft using dummy data. It does not represent a real progression event.\n\n";

        return [
            'subject' => $subject,
            'html' => $bannerHtml . $html,
            'text' => $bannerText . $text,
        ];
    }

    private function queueProgressionEmailExtended(array $email): int
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
            ':progress_test_id' => isset($email['progress_test_id']) && $email['progress_test_id'] !== null ? (int)$email['progress_test_id'] : null,
            ':email_type' => (string)$email['email_type'],
            ':recipients_to' => $this->encodeMixedField($email['recipients_to']),
            ':recipients_cc' => array_key_exists('recipients_cc', $email)
                ? $this->encodeMixedField($email['recipients_cc'])
                : null,
            ':subject' => (string)$email['subject'],
            ':body_html' => (string)$email['body_html'],
            ':body_text' => isset($email['body_text']) ? (string)$email['body_text'] : null,
            ':ai_inputs_json' => isset($email['ai_inputs']) && $email['ai_inputs'] !== null
                ? json_encode($email['ai_inputs'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
                : null,
            ':sent_status' => (string)($email['sent_status'] ?? 'queued'),
            ':sent_at' => isset($email['sent_at']) ? (string)$email['sent_at'] : null,
            ':created_at' => (string)($email['created_at'] ?? gmdate('Y-m-d H:i:s')),
            ':notification_template_id' => isset($email['notification_template_id']) ? (int)$email['notification_template_id'] : null,
            ':notification_template_version_id' => isset($email['notification_template_version_id']) ? (int)$email['notification_template_version_id'] : null,
            ':render_context_json' => isset($email['render_context_json'])
                ? json_encode($email['render_context_json'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
                : null,
        ]);

        return (int)$this->pdo->lastInsertId();
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
        if (is_array($decoded)) {
            $out = [];
            foreach ($decoded as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $email = trim((string)($row['email'] ?? ''));
                if ($email === '') {
                    continue;
                }

                $out[] = [
                    'email' => $email,
                    'name' => trim((string)($row['name'] ?? '')),
                ];
            }

            return $out;
        }

        return [[
            'email' => $value,
            'name' => '',
        ]];
    }
}