<?php
declare(strict_types=1);

require_once __DIR__ . '/../document/StructuredDocumentPayload.php';

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

    /**
     * @param array<string,mixed> $data
     * @param array<string,mixed> $upload
     */
    public function importPdfTemplate(array $data, array $upload, int $actorUserId): int
    {
        $this->requireSchema();
        $this->validatePdfUpload($upload);

        $originalName = trim((string)($upload['name'] ?? 'Imported form.pdf'));
        $title = trim((string)($data['title'] ?? ''));
        if ($title === '') {
            $title = $this->titleFromFilename($originalName);
        }
        if ($title === '') {
            throw new RuntimeException('Template title is required.');
        }

        $templateKey = $this->normalizeTemplateKey((string)($data['template_key'] ?? ''));
        if ($templateKey === '') {
            $templateKey = $this->normalizeTemplateKey($title);
        }
        $templateKey = $this->ensureUniqueTemplateKey($templateKey);

        $category = trim((string)($data['category'] ?? 'Checkride'));
        $description = trim((string)($data['description'] ?? ''));
        $versionLabel = trim((string)($data['version_label'] ?? '1.0'));
        if ($versionLabel === '') {
            $versionLabel = '1.0';
        }
        $profile = trim((string)($data['import_profile'] ?? 'private_sel_practical_test'));

        $tmpName = (string)($upload['tmp_name'] ?? '');
        $sourceHash = hash_file('sha256', $tmpName);
        if (!is_string($sourceHash) || $sourceHash === '') {
            throw new RuntimeException('Unable to hash uploaded PDF.');
        }
        $sourceMimeType = $this->detectPdfMimeType($tmpName);

        $storedFilePath = null;
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
                ':metadata_json' => $this->encodeJson(array('phase' => 'pdf_import_pending')),
                ':created_by' => $actorUserId > 0 ? $actorUserId : null,
            ));
            $templateId = (int)$this->pdo->lastInsertId();

            $sourcePdf = $this->storeImportedPdf($upload, $templateId, $templateKey, $sourceHash);
            $storedFilePath = $sourcePdf['path'];

            $metadata = array(
                'phase' => 'pdf_import',
                'source_document_type' => 'pdf',
                'import_profile' => $profile,
                'source_pdf' => array(
                    'original_filename' => $originalName,
                    'stored_filename' => basename($sourcePdf['path']),
                    'public_url' => $sourcePdf['url'],
                    'sha256' => $sourceHash,
                    'size_bytes' => (int)($upload['size'] ?? 0),
                    'mime_type' => $sourceMimeType,
                ),
                'autofill_status' => 'field_bindings_seeded',
            );
            $updTemplate = $this->pdo->prepare('UPDATE ipca_form_templates SET metadata_json = :metadata_json WHERE id = :id');
            $updTemplate->execute(array(
                ':metadata_json' => $this->encodeJson($metadata),
                ':id' => $templateId,
            ));

            $document = $this->importedPdfDocument($title, $profile, $sourcePdf['url'], $originalName);
            $contentJson = $this->encodeJson($document);
            $fields = StructuredDocumentPayload::collectFields($document['blocks'] ?? array());
            $fieldSchemaJson = $this->encodeJson($fields);
            $variableMapJson = $this->encodeJson($this->variablesUsed($fields));
            $contentHash = hash('sha256', $contentJson . $fieldSchemaJson);

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
                ':variable_map_json' => $variableMapJson,
                ':field_schema_json' => $fieldSchemaJson,
                ':content_hash' => $contentHash,
                ':created_by' => $actorUserId > 0 ? $actorUserId : null,
            ));
            $versionId = (int)$this->pdo->lastInsertId();

            $upd = $this->pdo->prepare('UPDATE ipca_form_templates SET current_version_id = :version_id WHERE id = :template_id');
            $upd->execute(array(
                ':version_id' => $versionId,
                ':template_id' => $templateId,
            ));

            $this->insertFieldSchemas($versionId, $fields);

            $this->writeAuditEvent(
                'template_pdf_imported',
                $actorUserId,
                array(
                    'template_key' => $templateKey,
                    'title' => $title,
                    'version_label' => $versionLabel,
                    'source_pdf_sha256' => $sourceHash,
                    'import_profile' => $profile,
                    'field_count' => count($fields),
                ),
                $templateId,
                $versionId
            );
            $this->writeAuditEvent(
                'template_fields_seeded',
                $actorUserId,
                array('field_count' => count($fields), 'source' => 'pdf_import_profile'),
                $templateId,
                $versionId
            );

            $this->pdo->commit();
            return $templateId;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if (is_string($storedFilePath) && $storedFilePath !== '' && is_file($storedFilePath)) {
                @unlink($storedFilePath);
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

    /**
     * @param array<string,mixed> $upload
     */
    private function validatePdfUpload(array $upload): void
    {
        $error = (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            throw new RuntimeException($this->uploadErrorMessage($error));
        }

        $tmpName = (string)($upload['tmp_name'] ?? '');
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            throw new RuntimeException('Uploaded PDF was not received correctly.');
        }

        $size = (int)($upload['size'] ?? 0);
        if ($size <= 0) {
            throw new RuntimeException('Uploaded PDF is empty.');
        }
        if ($size > 30 * 1024 * 1024) {
            throw new RuntimeException('Uploaded PDF is too large. Maximum size is 30 MB.');
        }

        $name = strtolower((string)($upload['name'] ?? ''));
        $mime = strtolower($this->detectPdfMimeType($tmpName));
        if (!str_ends_with($name, '.pdf') && !in_array($mime, array('application/pdf', 'application/x-pdf'), true)) {
            throw new RuntimeException('Only PDF files can be imported.');
        }
    }

    private function uploadErrorMessage(int $error): string
    {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Uploaded PDF is too large.',
            UPLOAD_ERR_PARTIAL => 'Uploaded PDF was only partially received.',
            UPLOAD_ERR_NO_FILE => 'Choose a PDF to import.',
            default => 'Unable to import the uploaded PDF.',
        };
    }

    private function detectPdfMimeType(string $path): string
    {
        if ($path !== '' && is_file($path) && function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $mime = finfo_file($finfo, $path);
                finfo_close($finfo);
                if (is_string($mime) && $mime !== '') {
                    return $mime;
                }
            }
        }
        return 'application/pdf';
    }

    /**
     * @param array<string,mixed> $upload
     * @return array{path:string,url:string}
     */
    private function storeImportedPdf(array $upload, int $templateId, string $templateKey, string $sourceHash): array
    {
        $root = dirname(__DIR__, 2);
        $relativeDir = '/uploads/flight_training/form_templates/' . $templateId . '_' . strtolower($templateKey);
        $targetDir = $root . '/public' . $relativeDir;
        if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
            throw new RuntimeException('Unable to create form import storage directory.');
        }

        $filename = 'source_' . substr($sourceHash, 0, 16) . '.pdf';
        $targetPath = $targetDir . '/' . $filename;
        $tmpName = (string)($upload['tmp_name'] ?? '');
        if (!move_uploaded_file($tmpName, $targetPath)) {
            throw new RuntimeException('Unable to store uploaded PDF.');
        }
        @chmod($targetPath, 0664);

        return array(
            'path' => $targetPath,
            'url' => $relativeDir . '/' . $filename,
        );
    }

    private function titleFromFilename(string $filename): string
    {
        $base = pathinfo($filename, PATHINFO_FILENAME);
        $base = preg_replace('/[_-]+/', ' ', (string)$base) ?? '';
        return trim($base);
    }

    private function ensureUniqueTemplateKey(string $baseKey): string
    {
        $baseKey = $baseKey !== '' ? $baseKey : 'IMPORTED_FORM';
        $key = $baseKey;
        $suffix = 2;
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM ipca_form_templates WHERE template_key = :template_key');
        while (true) {
            $stmt->execute(array(':template_key' => $key));
            if ((int)$stmt->fetchColumn() === 0) {
                return $key;
            }
            $key = $baseKey . '_' . $suffix;
            $suffix++;
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function importedPdfDocument(string $title, string $profile, string $sourcePdfUrl, string $originalName): array
    {
        if ($profile !== 'private_sel_practical_test') {
            $profile = 'private_sel_practical_test';
        }

        $escapedUrl = htmlspecialchars($sourcePdfUrl, ENT_QUOTES, 'UTF-8');
        $escapedName = htmlspecialchars($originalName, ENT_QUOTES, 'UTF-8');

        return StructuredDocumentPayload::normalizeDocument(array(
            'document_type' => 'flight_training_form',
            'schema_version' => 1,
            'title' => $title,
            'layout' => array('page' => 'letter', 'orientation' => 'portrait'),
            'page_header' => array('enabled' => false),
            'page_footer' => array('enabled' => false),
            'sections' => array(
                array('id' => 1, 'section_key' => 'source_pdf', 'title' => 'Source PDF', 'sort_order' => 10),
                array('id' => 2, 'section_key' => 'applicant_cfi', 'title' => 'Applicant and CFI Information', 'sort_order' => 20),
                array('id' => 3, 'section_key' => 'iacra_faa', 'title' => 'IACRA / FAA Application', 'sort_order' => 30),
                array('id' => 4, 'section_key' => 'flight_experience', 'title' => 'Flight Experience Auto-Fill', 'sort_order' => 40),
                array('id' => 5, 'section_key' => 'checkride_readiness', 'title' => 'Checkride Readiness', 'sort_order' => 50),
            ),
            'blocks' => array_merge(
                array(
                    $this->headingBlock(1, 1, 'Private Pilot: Single-Engine Land Practical Test Guide', 1),
                    $this->paragraphBlock(
                        2,
                        1,
                        'Imported from <a href="' . $escapedUrl . '" target="_blank" rel="noopener">' . $escapedName . '</a>. This imported draft preserves the PDF as the source document and seeds the fields IPCA can auto-fill from student, instructor, logbook, requirements, and IACRA data.'
                    ),
                    $this->calloutBlock(3, 1, 'info', 'Import v1.0', 'This first import creates the structured field map. Exact PDF overlay coordinates can be calibrated next without changing the source file.'),
                    $this->headingBlock(4, 2, 'Applicant Information', 2),
                ),
                $this->fieldBlocks(5, 2, array(
                    array('applicant_name', 'Applicant name', 'student.full_name', 'student', true),
                    array('applicant_phone', 'Applicant phone', 'student.phone', 'student', true),
                    array('applicant_email', 'Applicant email', 'student.email', 'student', true),
                )),
                array($this->headingBlock(8, 2, 'CFI Information', 2)),
                $this->fieldBlocks(9, 2, array(
                    array('cfi_name', 'CFI name', 'instructor.full_name', 'instructor', true),
                    array('cfi_phone', 'CFI phone', 'instructor.phone', 'instructor', true),
                    array('cfi_email', 'CFI email', 'instructor.email', 'instructor', true),
                )),
                array($this->headingBlock(12, 3, 'IACRA Application Details', 2)),
                $this->fieldBlocks(13, 3, array(
                    array('iacra_ftn', 'IACRA FTN', 'iacra.ftn', 'student', true),
                    array('iacra_username', 'IACRA username', 'iacra.username', 'student', false),
                    array('knowledge_test_score', 'Knowledge test score', 'knowledge_test.score', 'instructor', false),
                    array('knowledge_test_deficient_codes', 'Paste written test report deficient codes', 'knowledge_test.deficient_codes', 'instructor', false),
                )),
                array($this->headingBlock(17, 4, 'FAA 8710 / Logbook Totals', 2)),
                $this->fieldBlocks(18, 4, array(
                    array('faa_total_time', 'Total time', 'iacra.total_time', 'instructor', true),
                    array('faa_pic_time', 'PIC time', 'iacra.pic_time', 'instructor', true),
                    array('faa_solo_time', 'Solo time', 'iacra.solo_time', 'instructor', true),
                    array('faa_cross_country_time', 'Cross-country time', 'iacra.cross_country_time', 'instructor', true),
                    array('faa_night_time', 'Night time', 'iacra.night_time', 'instructor', true),
                    array('faa_instrument_time', 'Instrument time', 'iacra.instrument_time', 'instructor', true),
                    array('faa_dual_received', 'Dual received', 'iacra.dual_received', 'instructor', true),
                )),
                array($this->headingBlock(25, 5, 'Private Pilot SEL Readiness Checks', 2)),
                $this->fieldBlocks(26, 5, array(
                    array('ground_training_complete', 'Ground training / theory completion', 'theory.completion', 'instructor', false),
                    array('first_solo_event', 'Tagged first solo flight', 'faa61.ppl.first_solo.events', 'instructor', false),
                    array('dual_xc_training_events', 'Tagged dual cross-country training flight(s)', 'faa61.ppl.dual_cross_country_training.events', 'instructor', false),
                    array('dual_night_training_events', 'Tagged dual night training flight(s)', 'faa61.ppl.dual_night_training.events', 'instructor', false),
                    array('dual_night_xc_event', 'Tagged dual night cross-country flight incl. distance', 'faa61.ppl.dual_night_cross_country.events', 'instructor', false),
                    array('dual_night_takeoffs_landings_events', 'Tagged dual night takeoffs and landings', 'faa61.ppl.dual_night_takeoffs_landings.events', 'instructor', false),
                    array('dual_instrument_training_events', 'Tagged dual instrument flight training', 'faa61.ppl.dual_instrument_flight_training.events', 'instructor', false),
                    array('solo_xc_event', 'Tagged solo cross-country flight', 'faa61.ppl.solo_cross_country_flight.events', 'instructor', false),
                    array('long_150nm_solo_xc_event', 'Tagged long 150 NM solo cross-country flight', 'faa61.ppl.long_150nm_solo_cross_country_flight.events', 'instructor', false),
                    array('towered_airport_takeoffs_landings_events', 'Tagged towered airport takeoffs and landings', 'faa61.ppl.towered_airport_takeoffs_landings.events', 'instructor', false),
                )),
                array(
                    $this->signatureBlock(40, 5, 'cfi_signature', 'CFI signature', 'instructor'),
                    $this->signatureBlock(41, 5, 'applicant_signature', 'Applicant signature', 'student'),
                )
            ),
        ));
    }

    /**
     * @return array<string,mixed>
     */
    private function headingBlock(int $id, int $sectionId, string $text, int $level): array
    {
        return array(
            'id' => $id,
            'section_id' => $sectionId,
            'block_key' => 'heading_' . $id,
            'stable_anchor' => 'form-block-' . $id,
            'block_type' => 'heading',
            'payload' => array('text' => $text, 'level' => $level, 'paragraph_style' => $level === 1 ? 'title' : 'subtitle_2'),
            'sort_order' => $id * 10,
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function paragraphBlock(int $id, int $sectionId, string $html): array
    {
        return array(
            'id' => $id,
            'section_id' => $sectionId,
            'block_key' => 'paragraph_' . $id,
            'stable_anchor' => 'form-block-' . $id,
            'block_type' => 'paragraph',
            'payload' => array('html' => $html, 'paragraph_style' => 'body'),
            'sort_order' => $id * 10,
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function calloutBlock(int $id, int $sectionId, string $type, string $title, string $text): array
    {
        return array(
            'id' => $id,
            'section_id' => $sectionId,
            'block_key' => 'callout_' . $id,
            'stable_anchor' => 'form-block-' . $id,
            'block_type' => 'callout',
            'payload' => array('callout_type' => $type, 'title' => $title, 'text' => $text),
            'sort_order' => $id * 10,
        );
    }

    /**
     * @param list<array{0:string,1:string,2:string,3:string,4:bool}> $definitions
     * @return list<array<string,mixed>>
     */
    private function fieldBlocks(int $firstId, int $sectionId, array $definitions): array
    {
        $blocks = array();
        foreach ($definitions as $idx => $definition) {
            $id = $firstId + $idx;
            $blocks[] = array(
                'id' => $id,
                'section_id' => $sectionId,
                'block_key' => 'field_' . $definition[0],
                'stable_anchor' => 'form-block-' . $id,
                'block_type' => 'field',
                'payload' => array(
                    'field_key' => $definition[0],
                    'field_type' => 'text',
                    'label' => $definition[1],
                    'variable_key' => $definition[2],
                    'assigned_role' => $definition[3],
                    'required' => $definition[4],
                    'placeholder' => '{{' . $definition[2] . '}}',
                ),
                'sort_order' => $id * 10,
            );
        }
        return $blocks;
    }

    /**
     * @return array<string,mixed>
     */
    private function signatureBlock(int $id, int $sectionId, string $fieldKey, string $label, string $role): array
    {
        return array(
            'id' => $id,
            'section_id' => $sectionId,
            'block_key' => 'signature_' . $fieldKey,
            'stable_anchor' => 'form-block-' . $id,
            'block_type' => 'signature',
            'payload' => array(
                'field_key' => $fieldKey,
                'field_type' => 'signature',
                'label' => $label,
                'assigned_role' => $role,
                'required' => true,
                'variable_key' => '',
                'placeholder' => '',
            ),
            'sort_order' => $id * 10,
        );
    }

    /**
     * @param list<array<string,mixed>> $fields
     */
    private function insertFieldSchemas(int $templateVersionId, array $fields): void
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO ipca_form_fields
                (template_version_id, field_key, field_type, label, required, assigned_role,
                 variable_key, validation_json, position_json, metadata_json, sort_order)
            VALUES
                (:template_version_id, :field_key, :field_type, :label, :required, :assigned_role,
                 :variable_key, :validation_json, :position_json, :metadata_json, :sort_order)
        ");
        $sort = 10;
        foreach ($fields as $field) {
            $stmt->execute(array(
                ':template_version_id' => $templateVersionId,
                ':field_key' => (string)($field['field_key'] ?? ''),
                ':field_type' => (string)($field['field_type'] ?? 'text'),
                ':label' => trim((string)($field['label'] ?? '')) !== '' ? (string)$field['label'] : null,
                ':required' => !empty($field['required']) ? 1 : 0,
                ':assigned_role' => (string)($field['assigned_role'] ?? 'instructor'),
                ':variable_key' => trim((string)($field['variable_key'] ?? '')) !== '' ? (string)$field['variable_key'] : null,
                ':validation_json' => $this->encodeJson(is_array($field['validation'] ?? null) ? $field['validation'] : array()),
                ':position_json' => $this->encodeJson(is_array($field['position'] ?? null) ? $field['position'] : array()),
                ':metadata_json' => $this->encodeJson(is_array($field['metadata'] ?? null) ? $field['metadata'] : array()),
                ':sort_order' => $sort,
            ));
            $sort += 10;
        }
    }

    /**
     * @param list<array<string,mixed>> $fields
     * @return array<string,mixed>
     */
    private function variablesUsed(array $fields): array
    {
        $vars = array();
        foreach ($fields as $field) {
            $key = trim((string)($field['variable_key'] ?? ''));
            if ($key !== '') {
                $vars[$key] = array('field_key' => (string)$field['field_key']);
            }
        }
        return $vars;
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
