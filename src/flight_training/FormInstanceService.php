<?php
declare(strict_types=1);

require_once __DIR__ . '/FormTemplateService.php';
require_once __DIR__ . '/FlightTotalsService.php';
require_once __DIR__ . '/Iacra8710Mapper.php';

final class FormInstanceService
{
    private const REQUIRED_TABLES = array(
        'ipca_form_templates',
        'ipca_form_template_versions',
        'ipca_form_fields',
        'ipca_form_audit_log',
        'ipca_form_instances',
        'ipca_form_instance_recipients',
        'ipca_form_instance_field_values',
        'ipca_internal_inbox_items',
    );

    private FormTemplateService $templates;

    public function __construct(private PDO $pdo)
    {
        $this->templates = new FormTemplateService($pdo);
    }

    public function schemaReady(): bool
    {
        return $this->missingTables() === array();
    }

    /**
     * @return list<string>
     */
    public function missingTables(): array
    {
        $missing = array();
        foreach (self::REQUIRED_TABLES as $table) {
            if (!$this->tableExists($table)) {
                $missing[] = $table;
            }
        }
        return $missing;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listSendableTemplates(): array
    {
        $this->requireSchema();
        $stmt = $this->pdo->query("
            SELECT
                t.id,
                t.template_key,
                t.title,
                t.category,
                t.status,
                t.current_version_id,
                tv.version_label,
                tv.lifecycle_status,
                COUNT(f.id) AS field_count
            FROM ipca_form_templates t
            INNER JOIN ipca_form_template_versions tv ON tv.id = t.current_version_id
            LEFT JOIN ipca_form_fields f ON f.template_version_id = tv.id
            WHERE t.status <> 'archived'
            GROUP BY t.id, tv.id
            ORDER BY t.updated_at DESC, t.title ASC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: array();
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listInternalUsers(array $roles): array
    {
        $cleanRoles = array_values(array_filter(array_map(static fn($r): string => strtolower(trim((string)$r)), $roles)));
        if ($cleanRoles === array()) {
            return array();
        }

        $placeholders = implode(',', array_fill(0, count($cleanRoles), '?'));
        $stmt = $this->pdo->prepare("
            SELECT id, name, email, role
            FROM users
            WHERE LOWER(role) IN ($placeholders)
            ORDER BY COALESCE(NULLIF(name, ''), email) ASC, id ASC
            LIMIT 1000
        ");
        $stmt->execute($cleanRoles);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: array();
    }

    /**
     * @param array<string,mixed> $data
     */
    public function createAndSend(array $data, int $actorUserId): int
    {
        $this->requireSchema();

        $templateId = (int)($data['template_id'] ?? 0);
        $studentUserId = (int)($data['student_user_id'] ?? 0);
        $instructorUserId = (int)($data['instructor_user_id'] ?? 0);
        if ($templateId <= 0) {
            throw new RuntimeException('Choose a form template.');
        }
        if ($studentUserId <= 0) {
            throw new RuntimeException('Choose a student recipient.');
        }
        if ($instructorUserId <= 0) {
            throw new RuntimeException('Choose an instructor recipient.');
        }

        $ctx = $this->loadTemplateContext($templateId);
        $fields = $this->listTemplateFields((int)$ctx['version']['id']);
        if ($fields === array()) {
            throw new RuntimeException('This template has no fields to send.');
        }

        $student = $this->loadUser($studentUserId);
        $instructor = $this->loadUser($instructorUserId);
        if (!$student || strtolower((string)($student['role'] ?? '')) !== 'student') {
            throw new RuntimeException('Student recipient is invalid.');
        }
        if (!$instructor || !in_array(strtolower((string)($instructor['role'] ?? '')), array('instructor', 'supervisor', 'chief_instructor', 'admin'), true)) {
            throw new RuntimeException('Instructor recipient is invalid.');
        }

        $title = trim((string)($data['title'] ?? ''));
        if ($title === '') {
            $title = (string)$ctx['template']['title'] . ' - ' . (string)($student['name'] ?? 'Student');
        }

        $context = array(
            'student_user_id' => $studentUserId,
            'instructor_user_id' => $instructorUserId,
            'student_name' => (string)($student['name'] ?? ''),
            'instructor_name' => (string)($instructor['name'] ?? ''),
        );
        $autoFill = $this->buildAutoFillSnapshot($student, $instructor);

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO ipca_form_instances
                    (template_id, template_version_id, title, status, student_user_id, instructor_user_id,
                     created_by, sent_at, context_json, auto_fill_snapshot_json)
                VALUES
                    (:template_id, :template_version_id, :title, 'sent', :student_user_id, :instructor_user_id,
                     :created_by, CURRENT_TIMESTAMP, :context_json, :auto_fill_snapshot_json)
            ");
            $stmt->execute(array(
                ':template_id' => (int)$ctx['template']['id'],
                ':template_version_id' => (int)$ctx['version']['id'],
                ':title' => $title,
                ':student_user_id' => $studentUserId,
                ':instructor_user_id' => $instructorUserId,
                ':created_by' => $actorUserId > 0 ? $actorUserId : null,
                ':context_json' => $this->encodeJson($context),
                ':auto_fill_snapshot_json' => $this->encodeJson($autoFill),
            ));
            $instanceId = (int)$this->pdo->lastInsertId();

            $this->seedFieldValues($instanceId, $fields, $autoFill, $actorUserId);

            $studentRecipientId = $this->createRecipient($instanceId, $student, 'student', 10);
            $instructorRecipientId = $this->createRecipient($instanceId, $instructor, 'instructor', 20);
            $this->createInboxItem($studentUserId, $studentRecipientId, $title, 'Student fields and signatures are ready for review.', '/student/forms/task.php?recipient_id=' . $studentRecipientId);
            $this->createInboxItem($instructorUserId, $instructorRecipientId, $title, 'Instructor fields and signatures are ready for review.', '/instructor/forms/task.php?recipient_id=' . $instructorRecipientId);

            $this->templates->writeAuditEvent(
                'form_instance_sent',
                $actorUserId,
                array(
                    'instance_id' => $instanceId,
                    'student_user_id' => $studentUserId,
                    'instructor_user_id' => $instructorUserId,
                    'field_count' => count($fields),
                    'recipient_count' => 2,
                ),
                (int)$ctx['template']['id'],
                (int)$ctx['version']['id']
            );

            $this->pdo->commit();
            return $instanceId;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listInboxItems(int $userId): array
    {
        $this->requireSchema();
        $stmt = $this->pdo->prepare("
            SELECT *
            FROM ipca_internal_inbox_items
            WHERE recipient_user_id = :user_id
              AND item_type IN ('form', 'document')
            ORDER BY
              FIELD(status, 'pending', 'opened', 'completed', 'cancelled'),
              updated_at DESC,
              id DESC
        ");
        $stmt->execute(array(':user_id' => $userId));
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: array();
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listAdminInstances(): array
    {
        $this->requireSchema();
        $stmt = $this->pdo->query("
            SELECT
                i.*,
                t.title AS template_title,
                tv.version_label,
                su.name AS student_name,
                su.email AS student_email,
                iu.name AS instructor_name,
                iu.email AS instructor_email,
                (
                    SELECT COUNT(*)
                    FROM ipca_form_instance_recipients r
                    WHERE r.form_instance_id = i.id
                ) AS recipient_count,
                (
                    SELECT COUNT(*)
                    FROM ipca_form_instance_recipients r
                    WHERE r.form_instance_id = i.id
                      AND r.status = 'completed'
                ) AS completed_recipient_count
            FROM ipca_form_instances i
            INNER JOIN ipca_form_templates t ON t.id = i.template_id
            INNER JOIN ipca_form_template_versions tv ON tv.id = i.template_version_id
            LEFT JOIN users su ON su.id = i.student_user_id
            LEFT JOIN users iu ON iu.id = i.instructor_user_id
            ORDER BY i.updated_at DESC, i.id DESC
            LIMIT 250
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: array();
    }

    /**
     * @return array<string,mixed>
     */
    public function loadRecipientTask(int $recipientId, int $userId): array
    {
        $this->requireSchema();
        $stmt = $this->pdo->prepare("
            SELECT
                r.*,
                i.title AS instance_title,
                i.status AS instance_status,
                i.template_id,
                i.template_version_id,
                t.title AS template_title,
                tv.version_label
            FROM ipca_form_instance_recipients r
            INNER JOIN ipca_form_instances i ON i.id = r.form_instance_id
            INNER JOIN ipca_form_templates t ON t.id = i.template_id
            INNER JOIN ipca_form_template_versions tv ON tv.id = i.template_version_id
            WHERE r.id = :recipient_id
              AND r.recipient_user_id = :user_id
            LIMIT 1
        ");
        $stmt->execute(array(':recipient_id' => $recipientId, ':user_id' => $userId));
        $recipient = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($recipient)) {
            throw new RuntimeException('Form task not found.');
        }

        if ((string)$recipient['status'] === 'pending') {
            $this->markRecipientOpened((int)$recipient['id'], (int)$recipient['form_instance_id'], $userId);
            $recipient['status'] = 'opened';
        }

        $fieldsStmt = $this->pdo->prepare("
            SELECT *
            FROM ipca_form_instance_field_values
            WHERE form_instance_id = :instance_id
            ORDER BY id ASC
        ");
        $fieldsStmt->execute(array(':instance_id' => (int)$recipient['form_instance_id']));
        $fields = $fieldsStmt->fetchAll(PDO::FETCH_ASSOC) ?: array();

        return array('recipient' => $recipient, 'fields' => $fields);
    }

    /**
     * @param array<string,mixed> $values
     */
    public function saveRecipientTask(int $recipientId, int $userId, array $values, bool $complete): void
    {
        $this->requireSchema();
        $task = $this->loadRecipientTask($recipientId, $userId);
        $recipient = $task['recipient'];
        $role = strtolower(trim((string)($recipient['recipient_role'] ?? '')));
        if ((string)($recipient['status'] ?? '') === 'completed') {
            throw new RuntimeException('This form task is already completed.');
        }

        $this->pdo->beginTransaction();
        try {
            foreach ($task['fields'] as $field) {
                $assignedRole = strtolower(trim((string)($field['assigned_role'] ?? '')));
                if ($assignedRole !== $role) {
                    continue;
                }
                $fieldKey = (string)$field['field_key'];
                if (!array_key_exists($fieldKey, $values)) {
                    continue;
                }

                $fieldType = strtolower(trim((string)($field['field_type'] ?? 'text')));
                $value = $this->normalizeSubmittedValue($values[$fieldKey], $fieldType);
                $signatureJson = null;
                $signedAtSql = 'signed_at';
                if (in_array($fieldType, array('signature', 'initial'), true) && trim($value) !== '') {
                    $signatureJson = $this->encodeJson(array(
                        'typed_name' => $value,
                        'signed_by_user_id' => $userId,
                        'signed_at' => gmdate('c'),
                        'method' => 'typed_name',
                    ));
                    $signedAtSql = 'CURRENT_TIMESTAMP';
                }

                $stmt = $this->pdo->prepare("
                    UPDATE ipca_form_instance_field_values
                    SET value_text = :value_text,
                        value_json = :value_json,
                        source = 'user',
                        filled_by_user_id = :filled_by_user_id,
                        signed_at = $signedAtSql,
                        signature_json = COALESCE(:signature_json, signature_json)
                    WHERE id = :field_value_id
                ");
                $stmt->execute(array(
                    ':value_text' => $value !== '' ? $value : null,
                    ':value_json' => $this->encodeJson(array('value' => $value)),
                    ':filled_by_user_id' => $userId,
                    ':signature_json' => $signatureJson,
                    ':field_value_id' => (int)$field['id'],
                ));
            }

            if ($complete) {
                $this->assertRecipientComplete((int)$recipient['form_instance_id'], $role);
                $this->completeRecipient((int)$recipient['id'], (int)$recipient['form_instance_id'], $userId);
            }

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @return array{template:array<string,mixed>,version:array<string,mixed>}
     */
    private function loadTemplateContext(int $templateId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT *
            FROM ipca_form_templates
            WHERE id = :template_id
              AND status <> 'archived'
            LIMIT 1
        ");
        $stmt->execute(array(':template_id' => $templateId));
        $template = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($template)) {
            throw new RuntimeException('Template not found.');
        }

        $versionId = (int)($template['current_version_id'] ?? 0);
        $stmt = $this->pdo->prepare("
            SELECT *
            FROM ipca_form_template_versions
            WHERE id = :version_id
              AND template_id = :template_id
            LIMIT 1
        ");
        $stmt->execute(array(':version_id' => $versionId, ':template_id' => $templateId));
        $version = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($version)) {
            throw new RuntimeException('Current template version not found.');
        }

        return array('template' => $template, 'version' => $version);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function listTemplateFields(int $templateVersionId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT *
            FROM ipca_form_fields
            WHERE template_version_id = :version_id
            ORDER BY sort_order, id
        ");
        $stmt->execute(array(':version_id' => $templateVersionId));
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: array();
    }

    /**
     * @return array<string,mixed>|null
     */
    private function loadUser(int $userId): ?array
    {
        if ($userId <= 0) {
            return null;
        }
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
        $stmt->execute(array(':id' => $userId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string,mixed> $student
     * @param array<string,mixed> $instructor
     * @return array<string,string>
     */
    private function buildAutoFillSnapshot(array $student, array $instructor): array
    {
        $snapshot = array(
            'student.full_name' => $this->userName($student),
            'student.email' => (string)($student['email'] ?? ''),
            'student.phone' => $this->userPhone($student),
            'instructor.full_name' => $this->userName($instructor),
            'instructor.email' => (string)($instructor['email'] ?? ''),
            'instructor.phone' => $this->userPhone($instructor),
        );

        foreach ($this->studentFlightVariables((int)($student['id'] ?? 0)) as $key => $value) {
            $snapshot[$key] = $value;
        }

        return $snapshot;
    }

    /**
     * @return array<string,string>
     */
    private function studentFlightVariables(int $studentUserId): array
    {
        if ($studentUserId <= 0 || !$this->tableExists('ipca_admin_logbooks') || !$this->tableExists('ipca_admin_logbook_entries')) {
            return array();
        }

        $stmt = $this->pdo->prepare("
            SELECT id
            FROM ipca_admin_logbooks
            WHERE student_user_id = :student_user_id
            ORDER BY updated_at DESC, id DESC
            LIMIT 1
        ");
        $stmt->execute(array(':student_user_id' => $studentUserId));
        $logbookId = (int)$stmt->fetchColumn();
        if ($logbookId <= 0) {
            return array();
        }

        $entries = $this->pdo->prepare("
            SELECT *
            FROM ipca_admin_logbook_entries
            WHERE logbook_id = :logbook_id
              AND review_status <> 'deleted'
        ");
        $entries->execute(array(':logbook_id' => $logbookId));
        $rows = $entries->fetchAll(PDO::FETCH_ASSOC) ?: array();
        $totals = (new FlightTotalsService())->calculate($rows);
        $iacra = (new Iacra8710Mapper())->map($totals);

        return array(
            'flight.total_time' => $this->formatNumber($totals['total_flight_time'] ?? 0),
            'flight.pic_time' => $this->formatNumber($totals['pic_time'] ?? 0),
            'flight.dual_time' => $this->formatNumber($totals['dual_received_time'] ?? 0),
            'flight.solo_time' => $this->formatNumber($totals['solo_time'] ?? 0),
            'flight.cross_country_time' => $this->formatNumber($totals['cross_country_time'] ?? 0),
            'flight.night_time' => $this->formatNumber($totals['night_time'] ?? 0),
            'flight.instrument_time' => $this->formatNumber($totals['instrument_time'] ?? 0),
            'flight.basic_instrument_flying' => $this->formatNumber($totals['basic_instrument_flying_time'] ?? 0),
            'flight.day_landings' => (string)(int)($totals['day_landings'] ?? 0),
            'flight.night_landings' => (string)(int)($totals['night_landings'] ?? 0),
            'flight.instructor_time' => $this->formatNumber($totals['instructor_time'] ?? 0),
            'iacra.total_time' => $this->formatNumber($iacra['total_time'] ?? 0),
            'iacra.pic_time' => $this->formatNumber($iacra['pic_time'] ?? 0),
            'iacra.solo_time' => $this->formatNumber($iacra['solo_time'] ?? 0),
            'iacra.cross_country_time' => $this->formatNumber($iacra['cross_country_time'] ?? 0),
            'iacra.night_time' => $this->formatNumber($iacra['night_time'] ?? 0),
            'iacra.instrument_time' => $this->formatNumber($iacra['instrument_time'] ?? 0),
            'iacra.basic_instrument_flying' => $this->formatNumber($iacra['basic_instrument_flying'] ?? 0),
            'iacra.dual_received' => $this->formatNumber($iacra['dual_received'] ?? 0),
        );
    }

    /**
     * @param list<array<string,mixed>> $fields
     * @param array<string,string> $autoFill
     */
    private function seedFieldValues(int $instanceId, array $fields, array $autoFill, int $actorUserId): void
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO ipca_form_instance_field_values
                (form_instance_id, template_field_id, field_key, field_type, label, required,
                 assigned_role, variable_key, value_text, value_json, source, filled_by_user_id)
            VALUES
                (:form_instance_id, :template_field_id, :field_key, :field_type, :label, :required,
                 :assigned_role, :variable_key, :value_text, :value_json, :source, :filled_by_user_id)
        ");

        foreach ($fields as $field) {
            $variableKey = trim((string)($field['variable_key'] ?? ''));
            $value = $variableKey !== '' ? (string)($autoFill[$variableKey] ?? '') : '';
            $source = $value !== '' ? 'auto_fill' : 'blank';
            $stmt->execute(array(
                ':form_instance_id' => $instanceId,
                ':template_field_id' => (int)($field['id'] ?? 0) > 0 ? (int)$field['id'] : null,
                ':field_key' => (string)($field['field_key'] ?? ''),
                ':field_type' => (string)($field['field_type'] ?? 'text'),
                ':label' => trim((string)($field['label'] ?? '')) !== '' ? (string)$field['label'] : null,
                ':required' => !empty($field['required']) ? 1 : 0,
                ':assigned_role' => (string)($field['assigned_role'] ?? 'instructor'),
                ':variable_key' => $variableKey !== '' ? $variableKey : null,
                ':value_text' => $value !== '' ? $value : null,
                ':value_json' => $this->encodeJson(array('value' => $value)),
                ':source' => $source,
                ':filled_by_user_id' => $source === 'auto_fill' && $actorUserId > 0 ? $actorUserId : null,
            ));
        }
    }

    /**
     * @param array<string,mixed> $user
     */
    private function createRecipient(int $instanceId, array $user, string $role, int $signingOrder): int
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO ipca_form_instance_recipients
                (form_instance_id, recipient_user_id, recipient_role, recipient_name, recipient_email, status, signing_order, metadata_json)
            VALUES
                (:form_instance_id, :recipient_user_id, :recipient_role, :recipient_name, :recipient_email, 'pending', :signing_order, :metadata_json)
        ");
        $stmt->execute(array(
            ':form_instance_id' => $instanceId,
            ':recipient_user_id' => (int)($user['id'] ?? 0),
            ':recipient_role' => $role,
            ':recipient_name' => $this->userName($user),
            ':recipient_email' => (string)($user['email'] ?? ''),
            ':signing_order' => $signingOrder,
            ':metadata_json' => $this->encodeJson(array('delivery' => 'internal_inbox')),
        ));
        return (int)$this->pdo->lastInsertId();
    }

    private function createInboxItem(int $userId, int $recipientId, string $title, string $summary, string $actionUrl): void
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO ipca_internal_inbox_items
                (recipient_user_id, item_type, entity_type, entity_id, title, summary, status, action_url, metadata_json)
            VALUES
                (:recipient_user_id, 'form', 'form_instance_recipient', :entity_id, :title, :summary, 'pending', :action_url, :metadata_json)
        ");
        $stmt->execute(array(
            ':recipient_user_id' => $userId,
            ':entity_id' => $recipientId,
            ':title' => $title,
            ':summary' => $summary,
            ':action_url' => $actionUrl,
            ':metadata_json' => $this->encodeJson(array('source' => 'flight_training_forms')),
        ));
    }

    private function markRecipientOpened(int $recipientId, int $instanceId, int $userId): void
    {
        $this->pdo->prepare("
            UPDATE ipca_form_instance_recipients
            SET status = IF(status = 'pending', 'opened', status),
                opened_at = COALESCE(opened_at, CURRENT_TIMESTAMP),
                last_accessed_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ")->execute(array(':id' => $recipientId));
        $this->pdo->prepare("
            UPDATE ipca_internal_inbox_items
            SET status = IF(status = 'pending', 'opened', status),
                opened_at = COALESCE(opened_at, CURRENT_TIMESTAMP)
            WHERE entity_type = 'form_instance_recipient'
              AND entity_id = :recipient_id
              AND recipient_user_id = :user_id
        ")->execute(array(':recipient_id' => $recipientId, ':user_id' => $userId));
        $this->pdo->prepare("
            UPDATE ipca_form_instances
            SET status = IF(status = 'sent', 'in_progress', status)
            WHERE id = :instance_id
        ")->execute(array(':instance_id' => $instanceId));
    }

    private function completeRecipient(int $recipientId, int $instanceId, int $userId): void
    {
        $this->pdo->prepare("
            UPDATE ipca_form_instance_recipients
            SET status = 'completed',
                completed_at = CURRENT_TIMESTAMP,
                last_accessed_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ")->execute(array(':id' => $recipientId));
        $this->pdo->prepare("
            UPDATE ipca_internal_inbox_items
            SET status = 'completed',
                completed_at = CURRENT_TIMESTAMP
            WHERE entity_type = 'form_instance_recipient'
              AND entity_id = :recipient_id
              AND recipient_user_id = :user_id
        ")->execute(array(':recipient_id' => $recipientId, ':user_id' => $userId));

        $remaining = $this->pdo->prepare("
            SELECT COUNT(*)
            FROM ipca_form_instance_recipients
            WHERE form_instance_id = :instance_id
              AND status <> 'completed'
        ");
        $remaining->execute(array(':instance_id' => $instanceId));
        if ((int)$remaining->fetchColumn() === 0) {
            $this->pdo->prepare("
                UPDATE ipca_form_instances
                SET status = 'completed',
                    completed_at = CURRENT_TIMESTAMP
                WHERE id = :instance_id
            ")->execute(array(':instance_id' => $instanceId));
        } else {
            $this->pdo->prepare("
                UPDATE ipca_form_instances
                SET status = 'in_progress'
                WHERE id = :instance_id
            ")->execute(array(':instance_id' => $instanceId));
        }
    }

    private function assertRecipientComplete(int $instanceId, string $role): void
    {
        $stmt = $this->pdo->prepare("
            SELECT label, field_type, value_text
            FROM ipca_form_instance_field_values
            WHERE form_instance_id = :instance_id
              AND assigned_role = :role
              AND required = 1
              AND (value_text IS NULL OR TRIM(value_text) = '')
            ORDER BY id ASC
        ");
        $stmt->execute(array(':instance_id' => $instanceId, ':role' => $role));
        $missing = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: array();
        if ($missing !== array()) {
            $labels = array_map(static fn(array $row): string => trim((string)($row['label'] ?? 'Field')), $missing);
            throw new RuntimeException('Complete required fields before submitting: ' . implode(', ', array_slice($labels, 0, 5)) . '.');
        }
    }

    private function normalizeSubmittedValue(mixed $raw, string $fieldType): string
    {
        if ($fieldType === 'checkbox') {
            return !empty($raw) ? '1' : '';
        }
        return trim((string)$raw);
    }

    private function userName(array $user): string
    {
        $name = trim((string)($user['name'] ?? ''));
        if ($name !== '') {
            return $name;
        }
        $first = trim((string)($user['first_name'] ?? ''));
        $last = trim((string)($user['last_name'] ?? ''));
        $full = trim($first . ' ' . $last);
        return $full !== '' ? $full : (string)($user['email'] ?? '');
    }

    private function userPhone(array $user): string
    {
        foreach (array('phone', 'cellphone', 'cell_phone', 'mobile', 'mobile_phone') as $key) {
            $value = trim((string)($user[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }
        return '';
    }

    private function formatNumber(mixed $value): string
    {
        $num = round((float)$value, 1);
        return number_format($num, 1, '.', '');
    }

    private function tableExists(string $table): bool
    {
        try {
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*)
                FROM information_schema.TABLES
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = :table
            ");
            $stmt->execute(array(':table' => $table));
            return (int)$stmt->fetchColumn() > 0;
        } catch (Throwable) {
            return false;
        }
    }

    private function requireSchema(): void
    {
        $missing = $this->missingTables();
        if ($missing !== array()) {
            throw new RuntimeException('Forms inbox tables are not installed. Apply scripts/sql/2026_06_19_flight_training_form_instances_inbox.sql. Missing: ' . implode(', ', $missing));
        }
    }

    /**
     * @param array<string,mixed>|list<mixed> $value
     */
    private function encodeJson(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
