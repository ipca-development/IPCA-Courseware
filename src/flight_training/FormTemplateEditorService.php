<?php
declare(strict_types=1);

require_once __DIR__ . '/../document/StructuredDocumentPayload.php';
require_once __DIR__ . '/FormTemplateService.php';

/**
 * Editor-specific operations for Flight Training form template versions.
 */
final class FormTemplateEditorService
{
    private FormTemplateService $templates;

    public function __construct(private PDO $pdo)
    {
        $this->templates = new FormTemplateService($pdo);
    }

    /**
     * @return array<string,mixed>
     */
    public function loadEditor(int $templateId, int $actorUserId): array
    {
        $ctx = $this->requireTemplateContext($templateId);
        $document = $this->decodeJsonObject($ctx['version']['content_json'] ?? null);
        if ($document === array()) {
            $document = $this->defaultDocument((string)$ctx['template']['title']);
        }
        $document = StructuredDocumentPayload::normalizeDocument($document);
        $fields = $this->listFields((int)$ctx['version']['id']);
        $editable = $this->isEditable($ctx['template'], $ctx['version']);

        $this->templates->writeAuditEvent(
            'template_editor_loaded',
            $actorUserId,
            array('editable' => $editable),
            (int)$ctx['template']['id'],
            (int)$ctx['version']['id']
        );

        return array(
            'template' => $ctx['template'],
            'version' => $ctx['version'],
            'document' => $document,
            'fields' => $fields,
            'variables' => self::variableCatalog(),
            'editable' => $editable,
        );
    }

    /**
     * @param array<string,mixed> $document
     * @return array<string,mixed>
     */
    public function saveContent(int $templateId, int $templateVersionId, array $document, int $actorUserId): array
    {
        $ctx = $this->requireTemplateContext($templateId, $templateVersionId);
        $this->requireEditable($ctx['template'], $ctx['version']);

        $document = StructuredDocumentPayload::normalizeDocument($document);
        $contentJson = $this->encodeJson($document);
        $fields = StructuredDocumentPayload::collectFields($document['blocks'] ?? array());
        $fieldSchemaJson = $this->encodeJson($fields);
        $contentHash = hash('sha256', $contentJson . $fieldSchemaJson);

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare("
                UPDATE ipca_form_template_versions
                SET content_json = :content_json,
                    field_schema_json = :field_schema_json,
                    variable_map_json = :variable_map_json,
                    content_hash = :content_hash
                WHERE id = :version_id
                  AND template_id = :template_id
            ");
            $stmt->execute(array(
                ':content_json' => $contentJson,
                ':field_schema_json' => $fieldSchemaJson,
                ':variable_map_json' => $this->encodeJson($this->variablesUsed($fields)),
                ':content_hash' => $contentHash,
                ':version_id' => $templateVersionId,
                ':template_id' => $templateId,
            ));

            $this->syncFields($templateVersionId, $fields);

            $this->templates->writeAuditEvent(
                'template_content_saved',
                $actorUserId,
                array('block_count' => count($document['blocks'] ?? array()), 'field_count' => count($fields), 'content_hash' => $contentHash),
                $templateId,
                $templateVersionId
            );
            $this->templates->writeAuditEvent(
                'template_fields_synced',
                $actorUserId,
                array('field_count' => count($fields)),
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

        return array(
            'document' => $document,
            'fields' => $this->listFields($templateVersionId),
            'content_hash' => $contentHash,
        );
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listFields(int $templateVersionId): array
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
     * @return list<array<string,mixed>>
     */
    public static function variableCatalog(): array
    {
        return array(
            array('group' => 'Student profile', 'variables' => array(
                array('key' => 'student.full_name', 'token' => '{{student.full_name}}', 'label' => 'Student full name'),
                array('key' => 'student.phone', 'token' => '{{student.phone}}', 'label' => 'Student phone'),
                array('key' => 'student.email', 'token' => '{{student.email}}', 'label' => 'Student email'),
            )),
            array('group' => 'Instructor profile', 'variables' => array(
                array('key' => 'instructor.full_name', 'token' => '{{instructor.full_name}}', 'label' => 'Instructor full name'),
                array('key' => 'instructor.phone', 'token' => '{{instructor.phone}}', 'label' => 'Instructor phone'),
                array('key' => 'instructor.email', 'token' => '{{instructor.email}}', 'label' => 'Instructor email'),
            )),
            array('group' => 'Course enrollment', 'variables' => array(
                array('key' => 'course.name', 'token' => '{{course.name}}', 'label' => 'Course name'),
            )),
            array('group' => 'Theory / groundschool', 'variables' => array(
                array('key' => 'theory.completion', 'token' => '{{theory.completion}}', 'label' => 'Theory completion'),
            )),
            array('group' => 'Knowledge test', 'variables' => array(
                array('key' => 'knowledge_test.score', 'token' => '{{knowledge_test.score}}', 'label' => 'Knowledge test score'),
                array('key' => 'knowledge_test.deficient_codes', 'token' => '{{knowledge_test.deficient_codes}}', 'label' => 'Knowledge test deficient codes'),
            )),
            array('group' => 'Flight totals', 'variables' => array(
                array('key' => 'flight.total_time', 'token' => '{{flight.total_time}}', 'label' => 'Total time'),
                array('key' => 'flight.pic_time', 'token' => '{{flight.pic_time}}', 'label' => 'PIC time'),
                array('key' => 'flight.dual_time', 'token' => '{{flight.dual_time}}', 'label' => 'Dual received time'),
                array('key' => 'flight.solo_time', 'token' => '{{flight.solo_time}}', 'label' => 'Solo time'),
                array('key' => 'flight.cross_country_time', 'token' => '{{flight.cross_country_time}}', 'label' => 'Cross-country time'),
                array('key' => 'flight.night_time', 'token' => '{{flight.night_time}}', 'label' => 'Night time'),
                array('key' => 'flight.instrument_time', 'token' => '{{flight.instrument_time}}', 'label' => 'Instrument time'),
                array('key' => 'flight.basic_instrument_flying', 'token' => '{{flight.basic_instrument_flying}}', 'label' => 'Basic instrument flying'),
                array('key' => 'flight.day_landings', 'token' => '{{flight.day_landings}}', 'label' => 'Day landings'),
                array('key' => 'flight.night_landings', 'token' => '{{flight.night_landings}}', 'label' => 'Night landings'),
                array('key' => 'flight.instructor_time', 'token' => '{{flight.instructor_time}}', 'label' => 'Instructor time'),
            )),
            array('group' => 'FAA Part 61 PPL', 'variables' => array(
                array('key' => 'faa61.ppl.first_solo.status', 'token' => '{{faa61.ppl.first_solo.status}}', 'label' => 'First solo status'),
                array('key' => 'faa61.ppl.long_solo_cross_country.status', 'token' => '{{faa61.ppl.long_solo_cross_country.status}}', 'label' => 'Long solo cross-country status'),
                array('key' => 'faa61.ppl.solo_cross_country_150_nm.status', 'token' => '{{faa61.ppl.solo_cross_country_150_nm.status}}', 'label' => 'Solo cross-country 150 NM status'),
                array('key' => 'faa61.ppl.towered_airport_landings.value', 'token' => '{{faa61.ppl.towered_airport_landings.value}}', 'label' => 'Towered airport landings'),
                array('key' => 'faa61.ppl.basic_instrument_flying.status', 'token' => '{{faa61.ppl.basic_instrument_flying.status}}', 'label' => 'Basic instrument flying status'),
            )),
            array('group' => 'EASA PPL', 'variables' => array(
                array('key' => 'easa.ppl.dual_instruction.status', 'token' => '{{easa.ppl.dual_instruction.status}}', 'label' => 'Dual instruction status'),
                array('key' => 'easa.ppl.solo_flight.status', 'token' => '{{easa.ppl.solo_flight.status}}', 'label' => 'Solo flight status'),
                array('key' => 'easa.ppl.navigation_flight.status', 'token' => '{{easa.ppl.navigation_flight.status}}', 'label' => 'Navigation flight status'),
                array('key' => 'easa.ppl.basic_instrument_flying.status', 'token' => '{{easa.ppl.basic_instrument_flying.status}}', 'label' => 'Basic instrument flying status'),
            )),
            array('group' => 'IACRA / FAA 8710', 'variables' => array(
                array('key' => 'iacra.ftn', 'token' => '{{iacra.ftn}}', 'label' => 'IACRA FTN'),
                array('key' => 'iacra.username', 'token' => '{{iacra.username}}', 'label' => 'IACRA username'),
                array('key' => 'iacra.total_time', 'token' => '{{iacra.total_time}}', 'label' => 'IACRA total time'),
                array('key' => 'iacra.pic_time', 'token' => '{{iacra.pic_time}}', 'label' => 'IACRA PIC time'),
                array('key' => 'iacra.solo_time', 'token' => '{{iacra.solo_time}}', 'label' => 'IACRA solo time'),
                array('key' => 'iacra.cross_country_time', 'token' => '{{iacra.cross_country_time}}', 'label' => 'IACRA cross-country time'),
                array('key' => 'iacra.night_time', 'token' => '{{iacra.night_time}}', 'label' => 'IACRA night time'),
                array('key' => 'iacra.instrument_time', 'token' => '{{iacra.instrument_time}}', 'label' => 'IACRA instrument time'),
                array('key' => 'iacra.basic_instrument_flying', 'token' => '{{iacra.basic_instrument_flying}}', 'label' => 'IACRA basic instrument flying'),
                array('key' => 'iacra.dual_received', 'token' => '{{iacra.dual_received}}', 'label' => 'IACRA dual received'),
            )),
        );
    }

    /**
     * @return array{template:array<string,mixed>,version:array<string,mixed>}
     */
    private function requireTemplateContext(int $templateId, ?int $versionId = null): array
    {
        if (!$this->templates->schemaReady()) {
            throw new RuntimeException('Flight Training Forms tables are not installed.');
        }
        $stmt = $this->pdo->prepare("
            SELECT *
            FROM ipca_form_templates
            WHERE id = :template_id
            LIMIT 1
        ");
        $stmt->execute(array(':template_id' => $templateId));
        $template = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($template)) {
            throw new RuntimeException('Template not found.');
        }

        $targetVersionId = $versionId !== null && $versionId > 0 ? $versionId : (int)($template['current_version_id'] ?? 0);
        $stmt = $this->pdo->prepare("
            SELECT *
            FROM ipca_form_template_versions
            WHERE id = :version_id
              AND template_id = :template_id
            LIMIT 1
        ");
        $stmt->execute(array(':version_id' => $targetVersionId, ':template_id' => $templateId));
        $version = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($version)) {
            throw new RuntimeException('Template version not found.');
        }

        return array('template' => $template, 'version' => $version);
    }

    /**
     * @param array<string,mixed> $template
     * @param array<string,mixed> $version
     */
    private function isEditable(array $template, array $version): bool
    {
        return (string)($template['status'] ?? '') !== 'archived'
            && (string)($version['lifecycle_status'] ?? '') === 'draft';
    }

    /**
     * @param array<string,mixed> $template
     * @param array<string,mixed> $version
     */
    private function requireEditable(array $template, array $version): void
    {
        if (!$this->isEditable($template, $version)) {
            throw new RuntimeException('Only draft versions on non-archived templates can be edited.');
        }
    }

    /**
     * @param list<array<string,mixed>> $fields
     */
    private function syncFields(int $templateVersionId, array $fields): void
    {
        $this->pdo->prepare('DELETE FROM ipca_form_fields WHERE template_version_id = :version_id')
            ->execute(array(':version_id' => $templateVersionId));

        if ($fields === array()) {
            return;
        }

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
                ':field_key' => (string)$field['field_key'],
                ':field_type' => (string)$field['field_type'],
                ':label' => trim((string)($field['label'] ?? '')) !== '' ? (string)$field['label'] : null,
                ':required' => !empty($field['required']) ? 1 : 0,
                ':assigned_role' => (string)$field['assigned_role'],
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

    /**
     * @return array<string,mixed>
     */
    private function defaultDocument(string $title): array
    {
        return StructuredDocumentPayload::normalizeDocument(array(
            'document_type' => 'flight_training_form',
            'schema_version' => 1,
            'title' => $title,
            'layout' => array('page' => 'letter', 'orientation' => 'portrait'),
            'blocks' => array(
                array('block_type' => 'heading', 'block_key' => 'template_title', 'payload' => array('text' => $title, 'level' => 1)),
                array('block_type' => 'paragraph', 'block_key' => 'intro_text', 'payload' => array('html' => 'Add form instructions or checklist content here.')),
            ),
        ));
    }

    /**
     * @param mixed $raw
     * @return array<string,mixed>
     */
    private function decodeJsonObject(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (!is_string($raw) || trim($raw) === '') {
            return array();
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : array();
    }

    /**
     * @param array<string,mixed>|list<mixed> $value
     */
    private function encodeJson(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
