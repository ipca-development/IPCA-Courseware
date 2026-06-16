<?php
declare(strict_types=1);

/**
 * Phase 1 service for Flight Training form template lifecycle management.
 */
final class FormTemplateService
{
    private const REQUIRED_TABLES = array(
        'ipca_form_templates',
        'ipca_form_template_versions',
        'ipca_form_fields',
        'ipca_form_audit_log',
    );

    public function __construct(private PDO $pdo)
    {
    }

    public function schemaReady(): bool
    {
        foreach (self::REQUIRED_TABLES as $table) {
            if (!$this->tableExists($table)) {
                return false;
            }
        }
        return true;
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
    public function listTemplates(): array
    {
        $this->requireSchema();

        $stmt = $this->pdo->query("
            SELECT
              t.id,
              t.template_key,
              t.title,
              t.description,
              t.category,
              t.status,
              t.current_version_id,
              t.created_by,
              t.created_at,
              t.updated_at,
              cv.version_label AS current_version_label,
              cv.lifecycle_status AS current_version_status,
              cv.approved_at AS current_version_approved_at,
              creator.name AS created_by_name,
              (
                SELECT COUNT(*)
                FROM ipca_form_template_versions tv
                WHERE tv.template_id = t.id
              ) AS version_count
            FROM ipca_form_templates t
            LEFT JOIN ipca_form_template_versions cv ON cv.id = t.current_version_id
            LEFT JOIN users creator ON creator.id = t.created_by
            ORDER BY
              FIELD(t.status, 'active', 'draft', 'archived'),
              t.updated_at DESC,
              t.title ASC
        ");

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: array();
    }

    /**
     * @param array<string,mixed> $data
     */
    public function createTemplate(array $data, int $actorUserId): int
    {
        $this->requireSchema();

        $title = trim((string)($data['title'] ?? ''));
        if ($title === '') {
            throw new RuntimeException('Template title is required.');
        }

        $templateKey = $this->normalizeTemplateKey((string)($data['template_key'] ?? ''));
        if ($templateKey === '') {
            $templateKey = $this->normalizeTemplateKey($title);
        }
        if ($templateKey === '') {
            throw new RuntimeException('Template key is required.');
        }

        $description = trim((string)($data['description'] ?? ''));
        $category = trim((string)($data['category'] ?? ''));
        $versionLabel = trim((string)($data['version_label'] ?? '1.0'));
        if ($versionLabel === '') {
            $versionLabel = '1.0';
        }

        $content = array(
            'document_type' => 'flight_training_form',
            'schema_version' => 1,
            'sections' => array(),
            'blocks' => array(),
        );
        $contentJson = $this->encodeJson($content);
        $contentHash = hash('sha256', $contentJson);

        $this->pdo->beginTransaction();
        try {
            $insTemplate = $this->pdo->prepare("
                INSERT INTO ipca_form_templates
                    (template_key, title, description, category, status, metadata_json, created_by)
                VALUES
                    (:template_key, :title, :description, :category, 'draft', :metadata_json, :created_by)
            ");
            $insTemplate->execute(array(
                ':template_key' => $templateKey,
                ':title' => $title,
                ':description' => $description !== '' ? $description : null,
                ':category' => $category !== '' ? $category : null,
                ':metadata_json' => $this->encodeJson(array('phase' => 'foundation')),
                ':created_by' => $actorUserId > 0 ? $actorUserId : null,
            ));
            $templateId = (int)$this->pdo->lastInsertId();

            $insVersion = $this->pdo->prepare("
                INSERT INTO ipca_form_template_versions
                    (template_id, version_label, lifecycle_status, title, content_json,
                     variable_map_json, field_schema_json, content_hash, created_by)
                VALUES
                    (:template_id, :version_label, 'draft', :title, :content_json,
                     :variable_map_json, :field_schema_json, :content_hash, :created_by)
            ");
            $insVersion->execute(array(
                ':template_id' => $templateId,
                ':version_label' => $versionLabel,
                ':title' => $title . ' v' . $versionLabel,
                ':content_json' => $contentJson,
                ':variable_map_json' => $this->encodeJson(array()),
                ':field_schema_json' => $this->encodeJson(array()),
                ':content_hash' => $contentHash,
                ':created_by' => $actorUserId > 0 ? $actorUserId : null,
            ));
            $versionId = (int)$this->pdo->lastInsertId();

            $upd = $this->pdo->prepare('UPDATE ipca_form_templates SET current_version_id = :version_id WHERE id = :template_id');
            $upd->execute(array(
                ':version_id' => $versionId,
                ':template_id' => $templateId,
            ));

            $this->writeAuditEvent(
                'template_created',
                $actorUserId,
                array(
                    'template_key' => $templateKey,
                    'title' => $title,
                    'version_label' => $versionLabel,
                ),
                $templateId,
                $versionId
            );
            $this->writeAuditEvent(
                'template_version_created',
                $actorUserId,
                array('version_label' => $versionLabel, 'lifecycle_status' => 'draft'),
                $templateId,
                $versionId
            );

            $this->pdo->commit();
            return $templateId;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function archiveTemplate(int $templateId, int $actorUserId): void
    {
        $this->requireSchema();
        if ($templateId <= 0) {
            throw new RuntimeException('Invalid template.');
        }

        $template = $this->getTemplate($templateId);
        if ($template === null) {
            throw new RuntimeException('Template not found.');
        }
        if ((string)$template['status'] === 'archived') {
            return;
        }

        $stmt = $this->pdo->prepare("
            UPDATE ipca_form_templates
            SET status = 'archived',
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ");
        $stmt->execute(array(':id' => $templateId));

        $this->writeAuditEvent(
            'template_archived',
            $actorUserId,
            array('previous_status' => (string)$template['status']),
            $templateId,
            isset($template['current_version_id']) ? (int)$template['current_version_id'] : null
        );
    }

    public function activateTemplateVersion(int $templateVersionId, int $actorUserId): void
    {
        $this->requireSchema();
        if ($templateVersionId <= 0) {
            throw new RuntimeException('Invalid template version.');
        }

        $version = $this->getVersion($templateVersionId);
        if ($version === null) {
            throw new RuntimeException('Template version not found.');
        }

        $templateId = (int)$version['template_id'];

        $this->pdo->beginTransaction();
        try {
            $supersede = $this->pdo->prepare("
                UPDATE ipca_form_template_versions
                SET lifecycle_status = 'superseded'
                WHERE template_id = :template_id
                  AND lifecycle_status = 'active'
                  AND id <> :version_id
            ");
            $supersede->execute(array(
                ':template_id' => $templateId,
                ':version_id' => $templateVersionId,
            ));

            $activate = $this->pdo->prepare("
                UPDATE ipca_form_template_versions
                SET lifecycle_status = 'active',
                    approved_by = COALESCE(approved_by, :approved_by),
                    approved_at = COALESCE(approved_at, CURRENT_TIMESTAMP)
                WHERE id = :version_id
            ");
            $activate->execute(array(
                ':approved_by' => $actorUserId > 0 ? $actorUserId : null,
                ':version_id' => $templateVersionId,
            ));

            $updateTemplate = $this->pdo->prepare("
                UPDATE ipca_form_templates
                SET status = 'active',
                    current_version_id = :version_id,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :template_id
            ");
            $updateTemplate->execute(array(
                ':version_id' => $templateVersionId,
                ':template_id' => $templateId,
            ));

            $this->writeAuditEvent(
                'template_version_activated',
                $actorUserId,
                array(
                    'version_label' => (string)$version['version_label'],
                    'previous_lifecycle_status' => (string)$version['lifecycle_status'],
                ),
                $templateId,
                $templateVersionId
            );

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @return array<string,mixed>|null
     */
    public function loadCurrentVersion(int $templateId): ?array
    {
        $this->requireSchema();
        if ($templateId <= 0) {
            return null;
        }

        $stmt = $this->pdo->prepare("
            SELECT tv.*
            FROM ipca_form_templates t
            INNER JOIN ipca_form_template_versions tv ON tv.id = t.current_version_id
            WHERE t.id = :template_id
            LIMIT 1
        ");
        $stmt->execute(array(':template_id' => $templateId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string,mixed> $event
     */
    public function writeAuditEvent(
        string $eventType,
        ?int $actorUserId,
        array $event = array(),
        ?int $templateId = null,
        ?int $templateVersionId = null,
        string $actorType = 'admin'
    ): void {
        $this->requireSchema();

        $stmt = $this->pdo->prepare("
            INSERT INTO ipca_form_audit_log
                (template_id, template_version_id, actor_user_id, actor_type, event_type,
                 event_json, ip_address, user_agent)
            VALUES
                (:template_id, :template_version_id, :actor_user_id, :actor_type, :event_type,
                 :event_json, :ip_address, :user_agent)
        ");
        $stmt->execute(array(
            ':template_id' => $templateId !== null && $templateId > 0 ? $templateId : null,
            ':template_version_id' => $templateVersionId !== null && $templateVersionId > 0 ? $templateVersionId : null,
            ':actor_user_id' => $actorUserId !== null && $actorUserId > 0 ? $actorUserId : null,
            ':actor_type' => $actorType,
            ':event_type' => trim($eventType) !== '' ? trim($eventType) : 'unknown',
            ':event_json' => $this->encodeJson($event),
            ':ip_address' => $this->requestIpAddress(),
            ':user_agent' => $this->requestUserAgent(),
        ));
    }

    /**
     * @return array<string,mixed>|null
     */
    private function getTemplate(int $templateId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ipca_form_templates WHERE id = :id LIMIT 1');
        $stmt->execute(array(':id' => $templateId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function getVersion(int $templateVersionId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ipca_form_template_versions WHERE id = :id LIMIT 1');
        $stmt->execute(array(':id' => $templateVersionId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function requireSchema(): void
    {
        $missing = $this->missingTables();
        if ($missing !== array()) {
            throw new RuntimeException(
                'Flight Training Forms tables are not installed. Apply scripts/sql/2026_06_16_flight_training_forms_foundation.sql.'
            );
        }
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
        } catch (Throwable $e) {
            return false;
        }
    }

    private function normalizeTemplateKey(string $value): string
    {
        $value = strtoupper(trim($value));
        $value = preg_replace('/[^A-Z0-9]+/', '_', $value) ?? '';
        return trim($value, '_');
    }

    /**
     * @param array<string,mixed>|list<mixed> $value
     */
    private function encodeJson(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function requestIpAddress(): ?string
    {
        $ip = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
        return $ip !== '' ? substr($ip, 0, 64) : null;
    }

    private function requestUserAgent(): ?string
    {
        $ua = trim((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
        return $ua !== '' ? substr($ua, 0, 255) : null;
    }
}
