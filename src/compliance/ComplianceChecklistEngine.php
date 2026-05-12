<?php
declare(strict_types=1);

require_once __DIR__ . '/ComplianceAutomationDispatch.php';

/**
 * Phase 4 — Checklist templates, versions, items (editable until version locked).
 */
final class ComplianceChecklistEngine
{
    /** @var list<string> */
    private const ITEM_TYPES = array(
        'SECTION', 'QUESTION', 'MULTI_CHOICE', 'YES_NO', 'NUMERIC', 'TEXT', 'EVIDENCE_UPLOAD',
    );

    /** @var list<string> */
    private const VERSION_STATUSES = array(
        'DRAFT', 'PENDING_APPROVAL', 'APPROVED', 'ARCHIVED',
    );

    public static function normalizeItemType(string $v): string
    {
        $v = strtoupper(trim($v));

        return in_array($v, self::ITEM_TYPES, true) ? $v : 'QUESTION';
    }

    public static function normalizeVersionStatus(string $v): string
    {
        $v = strtoupper(trim($v));

        return in_array($v, self::VERSION_STATUSES, true) ? $v : 'DRAFT';
    }

    /**
     * @return list<array<string,mixed>>
     */
    public static function listTemplates(PDO $pdo, int $limit = 200): array
    {
        $limit = max(1, min(500, $limit));
        $sql = 'SELECT * FROM ipca_compliance_checklist_templates ORDER BY title ASC, id ASC LIMIT ' . (int)$limit;
        $st = $pdo->query($sql);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : array();
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function getTemplate(PDO $pdo, int $id): ?array
    {
        $st = $pdo->prepare('SELECT * FROM ipca_compliance_checklist_templates WHERE id = ? LIMIT 1');
        $st->execute(array($id));
        $row = $st->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    public static function generateTemplateCode(PDO $pdo, string $prefix = 'CLT'): string
    {
        $year = (int)date('Y');
        $p = $prefix . '-' . $year . '-';
        $st = $pdo->prepare('SELECT template_code FROM ipca_compliance_checklist_templates WHERE template_code LIKE ? ORDER BY id DESC LIMIT 50');
        $st->execute(array($p . '%'));
        $max = 0;
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $code = (string)($row['template_code'] ?? '');
            if (!str_starts_with($code, $p)) {
                continue;
            }
            $n = (int)substr($code, strlen($p));
            if ($n > $max) {
                $max = $n;
            }
        }

        return $p . str_pad((string)($max + 1), 4, '0', STR_PAD_LEFT);
    }

    /**
     * @param array{template_code?:string,title:string,description?:string,authority?:string,scope_tags?:string,is_active?:int} $data
     */
    public static function createTemplate(PDO $pdo, array $data, int $userId): int
    {
        $code = trim((string)($data['template_code'] ?? ''));
        if ($code === '') {
            $code = self::generateTemplateCode($pdo);
        }
        $title = trim((string)($data['title'] ?? ''));
        if ($title === '') {
            throw new InvalidArgumentException('Title is required.');
        }
        $ins = $pdo->prepare(
            'INSERT INTO ipca_compliance_checklist_templates (
                template_code, title, description, authority, scope_tags, is_active,
                current_version_id, created_by, updated_by
            ) VALUES (?, ?, ?, ?, ?, ?, NULL, ?, ?)'
        );
        $ins->execute(array(
            substr($code, 0, 64),
            substr($title, 0, 255),
            isset($data['description']) ? trim((string)$data['description']) : null,
            isset($data['authority']) ? trim((string)$data['authority']) ?: null : null,
            isset($data['scope_tags']) ? trim((string)$data['scope_tags']) ?: null : null,
            isset($data['is_active']) ? (int)(bool)$data['is_active'] : 1,
            $userId > 0 ? $userId : null,
            $userId > 0 ? $userId : null,
        ));

        return (int)$pdo->lastInsertId();
    }

    /**
     * @param array{title?:string,description?:string,authority?:string,scope_tags?:string,is_active?:int} $data
     */
    public static function updateTemplate(PDO $pdo, int $templateId, array $data, int $userId): void
    {
        $t = self::getTemplate($pdo, $templateId);
        if ($t === null) {
            throw new RuntimeException('Template not found.');
        }
        $title = array_key_exists('title', $data) ? trim((string)$data['title']) : (string)$t['title'];
        if ($title === '') {
            throw new InvalidArgumentException('Title is required.');
        }
        $st = $pdo->prepare(
            'UPDATE ipca_compliance_checklist_templates SET
                title = ?, description = ?, authority = ?, scope_tags = ?, is_active = ?, updated_by = ?
             WHERE id = ?'
        );
        $st->execute(array(
            substr($title, 0, 255),
            array_key_exists('description', $data) ? (trim((string)$data['description']) ?: null) : $t['description'],
            array_key_exists('authority', $data) ? (trim((string)$data['authority']) ?: null) : $t['authority'],
            array_key_exists('scope_tags', $data) ? (trim((string)$data['scope_tags']) ?: null) : $t['scope_tags'],
            array_key_exists('is_active', $data) ? (int)(bool)$data['is_active'] : (int)$t['is_active'],
            $userId > 0 ? $userId : null,
            $templateId,
        ));
    }

    /**
     * @return list<array<string,mixed>>
     */
    public static function listVersions(PDO $pdo, int $templateId): array
    {
        $st = $pdo->prepare(
            'SELECT * FROM ipca_compliance_checklist_versions WHERE template_id = ? ORDER BY version_no DESC'
        );
        $st->execute(array($templateId));
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : array();
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function getVersion(PDO $pdo, int $versionId): ?array
    {
        $st = $pdo->prepare('SELECT * FROM ipca_compliance_checklist_versions WHERE id = ? LIMIT 1');
        $st->execute(array($versionId));
        $row = $st->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    public static function nextVersionNo(PDO $pdo, int $templateId): int
    {
        $st = $pdo->prepare('SELECT COALESCE(MAX(version_no), 0) FROM ipca_compliance_checklist_versions WHERE template_id = ?');
        $st->execute(array($templateId));

        return (int)$st->fetchColumn() + 1;
    }

    public static function createVersion(PDO $pdo, int $templateId, ?string $description, int $userId): int
    {
        if (self::getTemplate($pdo, $templateId) === null) {
            throw new RuntimeException('Template not found.');
        }
        $vn = self::nextVersionNo($pdo, $templateId);
        $ins = $pdo->prepare(
            'INSERT INTO ipca_compliance_checklist_versions (
                template_id, version_no, status, description, items_count,
                created_by, updated_by
            ) VALUES (?, ?, \'DRAFT\', ?, 0, ?, ?)'
        );
        $ins->execute(array(
            $templateId,
            $vn,
            $description !== null && trim($description) !== '' ? trim($description) : null,
            $userId > 0 ? $userId : null,
            $userId > 0 ? $userId : null,
        ));

        return (int)$pdo->lastInsertId();
    }

    public static function assertVersionEditable(array $versionRow): void
    {
        if (!empty($versionRow['locked_at'])) {
            throw new RuntimeException('This checklist version is locked.');
        }
        $st = strtoupper((string)($versionRow['status'] ?? ''));
        if ($st === 'APPROVED' || $st === 'ARCHIVED') {
            throw new RuntimeException('This checklist version cannot be edited.');
        }
    }

    public static function refreshItemsCount(PDO $pdo, int $versionId): void
    {
        $st = $pdo->prepare('SELECT COUNT(*) FROM ipca_compliance_checklist_items WHERE version_id = ?');
        $st->execute(array($versionId));
        $n = (int)$st->fetchColumn();
        $up = $pdo->prepare('UPDATE ipca_compliance_checklist_versions SET items_count = ? WHERE id = ?');
        $up->execute(array($n, $versionId));
    }

    /**
     * @return list<array<string,mixed>>
     */
    public static function listItems(PDO $pdo, int $versionId): array
    {
        $st = $pdo->prepare(
            'SELECT * FROM ipca_compliance_checklist_items WHERE version_id = ? ORDER BY sort_order ASC, id ASC'
        );
        $st->execute(array($versionId));
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : array();
    }

    /**
     * @param array{
     *   item_code?:string,parent_item_id?:int|null,sort_order?:int,item_type?:string,
     *   prompt:string,guidance?:string,is_required?:int,weight?:int|null,
     *   options_json?:string|null,reg_refs_json?:string|null,manual_refs_json?:string|null
     * } $data
     */
    public static function addItem(PDO $pdo, int $versionId, array $data, int $userId): int
    {
        $v = self::getVersion($pdo, $versionId);
        if ($v === null) {
            throw new RuntimeException('Version not found.');
        }
        self::assertVersionEditable($v);
        $prompt = trim((string)($data['prompt'] ?? ''));
        if ($prompt === '') {
            throw new InvalidArgumentException('Item prompt is required.');
        }
        $code = isset($data['item_code']) ? trim((string)$data['item_code']) : '';
        if ($code === '') {
            $code = 'ITEM-' . str_pad((string)(count(self::listItems($pdo, $versionId)) + 1), 3, '0', STR_PAD_LEFT);
        }
        $sort = isset($data['sort_order']) ? max(0, (int)$data['sort_order']) : 0;
        $itype = self::normalizeItemType((string)($data['item_type'] ?? 'QUESTION'));
        $guid = isset($data['guidance']) ? trim((string)$data['guidance']) : '';
        $guid = $guid !== '' ? $guid : null;
        $pid = isset($data['parent_item_id']) && (int)$data['parent_item_id'] > 0 ? (int)$data['parent_item_id'] : null;
        $req = isset($data['is_required']) ? (int)(bool)$data['is_required'] : 1;
        $weight = isset($data['weight']) && $data['weight'] !== '' ? max(0, (int)$data['weight']) : null;

        $optJson = self::coerceJsonField($data['options_json'] ?? null);
        $regJson = self::coerceJsonField($data['reg_refs_json'] ?? null);
        $manJson = self::coerceJsonField($data['manual_refs_json'] ?? null);

        $ins = $pdo->prepare(
            'INSERT INTO ipca_compliance_checklist_items (
                version_id, item_code, parent_item_id, sort_order, item_type, prompt, guidance,
                is_required, weight, options_json, reg_refs_json, manual_refs_json
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, CAST(? AS JSON), CAST(? AS JSON), CAST(? AS JSON))'
        );
        $ins->execute(array(
            $versionId,
            substr($code, 0, 64),
            $pid,
            $sort,
            $itype,
            $prompt,
            $guid,
            $req,
            $weight,
            $optJson,
            $regJson,
            $manJson,
        ));
        $id = (int)$pdo->lastInsertId();
        self::refreshItemsCount($pdo, $versionId);

        return $id;
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function updateItem(PDO $pdo, int $itemId, array $data, int $userId): void
    {
        $st = $pdo->prepare('SELECT * FROM ipca_compliance_checklist_items WHERE id = ? LIMIT 1');
        $st->execute(array($itemId));
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('Item not found.');
        }
        $v = self::getVersion($pdo, (int)$row['version_id']);
        if ($v === null) {
            throw new RuntimeException('Version not found.');
        }
        self::assertVersionEditable($v);

        $prompt = array_key_exists('prompt', $data) ? trim((string)$data['prompt']) : (string)$row['prompt'];
        if ($prompt === '') {
            throw new InvalidArgumentException('Item prompt is required.');
        }
        $code = array_key_exists('item_code', $data) ? trim((string)$data['item_code']) : (string)$row['item_code'];
        $sort = array_key_exists('sort_order', $data) ? max(0, (int)$data['sort_order']) : (int)$row['sort_order'];
        $itype = array_key_exists('item_type', $data)
            ? self::normalizeItemType((string)$data['item_type'])
            : (string)$row['item_type'];
        $guid = array_key_exists('guidance', $data) ? trim((string)$data['guidance']) : (string)($row['guidance'] ?? '');
        $guid = $guid !== '' ? $guid : null;
        $req = array_key_exists('is_required', $data) ? (int)(bool)$data['is_required'] : (int)$row['is_required'];
        $weight = array_key_exists('weight', $data) && $data['weight'] !== '' ? max(0, (int)$data['weight']) : $row['weight'];

        $optJson = array_key_exists('options_json', $data) ? self::coerceJsonField($data['options_json']) : self::jsonColumnToString($row['options_json'] ?? null);
        $regJson = array_key_exists('reg_refs_json', $data) ? self::coerceJsonField($data['reg_refs_json']) : self::jsonColumnToString($row['reg_refs_json'] ?? null);
        $manJson = array_key_exists('manual_refs_json', $data) ? self::coerceJsonField($data['manual_refs_json']) : self::jsonColumnToString($row['manual_refs_json'] ?? null);

        $up = $pdo->prepare(
            'UPDATE ipca_compliance_checklist_items SET
                item_code = ?, sort_order = ?, item_type = ?, prompt = ?, guidance = ?,
                is_required = ?, weight = ?,
                options_json = CAST(? AS JSON), reg_refs_json = CAST(? AS JSON), manual_refs_json = CAST(? AS JSON)
             WHERE id = ?'
        );
        $up->execute(array(
            substr($code, 0, 64),
            $sort,
            $itype,
            $prompt,
            $guid,
            $req,
            $weight,
            $optJson,
            $regJson,
            $manJson,
            $itemId,
        ));
    }

    public static function deleteItem(PDO $pdo, int $itemId, int $userId): void
    {
        $st = $pdo->prepare('SELECT * FROM ipca_compliance_checklist_items WHERE id = ? LIMIT 1');
        $st->execute(array($itemId));
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('Item not found.');
        }
        $vid = (int)$row['version_id'];
        $v = self::getVersion($pdo, $vid);
        if ($v === null) {
            throw new RuntimeException('Version not found.');
        }
        self::assertVersionEditable($v);
        $del = $pdo->prepare('DELETE FROM ipca_compliance_checklist_items WHERE id = ?');
        $del->execute(array($itemId));
        self::refreshItemsCount($pdo, $vid);
    }

    public static function setVersionStatus(PDO $pdo, int $versionId, string $status, int $userId): void
    {
        $v = self::getVersion($pdo, $versionId);
        if ($v === null) {
            throw new RuntimeException('Version not found.');
        }
        self::assertVersionEditable($v);
        $st = self::normalizeVersionStatus($status);
        if ($st !== 'DRAFT' && $st !== 'PENDING_APPROVAL') {
            throw new InvalidArgumentException('Status can only move to DRAFT or PENDING_APPROVAL here.');
        }
        $up = $pdo->prepare('UPDATE ipca_compliance_checklist_versions SET status = ?, updated_by = ? WHERE id = ?');
        $up->execute(array($st, $userId > 0 ? $userId : null, $versionId));
    }

    public static function approveVersion(PDO $pdo, int $versionId, int $userId, string $approverName): void
    {
        $name = trim($approverName);
        if ($name === '') {
            throw new InvalidArgumentException('Approver name is required.');
        }
        $v = self::getVersion($pdo, $versionId);
        if ($v === null) {
            throw new RuntimeException('Version not found.');
        }
        if (!empty($v['locked_at'])) {
            throw new RuntimeException('Version already locked.');
        }
        $cur = strtoupper((string)($v['status'] ?? ''));
        if ($cur !== 'PENDING_APPROVAL' && $cur !== 'DRAFT') {
            throw new RuntimeException('Version must be DRAFT or PENDING_APPROVAL to approve.');
        }
        self::refreshItemsCount($pdo, $versionId);
        $now = date('Y-m-d H:i:s');
        $pdo->prepare(
            'UPDATE ipca_compliance_checklist_versions SET
                status = \'APPROVED\',
                approved_by = ?, approved_by_name = ?, approved_at = ?,
                locked_at = ?, locked_by = ?,
                updated_by = ?
             WHERE id = ?'
        )->execute(array(
            $userId > 0 ? $userId : null,
            substr($name, 0, 255),
            $now,
            $now,
            $userId > 0 ? $userId : null,
            $userId > 0 ? $userId : null,
            $versionId,
        ));
        $tid = (int)$v['template_id'];
        $pdo->prepare(
            'UPDATE ipca_compliance_checklist_templates SET current_version_id = ?, updated_by = ? WHERE id = ?'
        )->execute(array($versionId, $userId > 0 ? $userId : null, $tid));

        $tplRow = self::getTemplate($pdo, $tid);
        ComplianceAutomationDispatch::fire($pdo, 'compliance.checklist.approved', array(
            'template_id' => $tid,
            'template_code' => (string)($tplRow['template_code'] ?? ''),
            'template_title' => (string)($tplRow['title'] ?? ''),
            'version_id' => $versionId,
            'version_no' => (int)($v['version_no'] ?? 0),
            'items_count' => (int)($v['items_count'] ?? 0),
            'approved_by_user_id' => $userId,
            'approved_by_name' => substr($name, 0, 255),
        ));
    }

    private static function coerceJsonField(mixed $raw): string
    {
        if ($raw === null || $raw === '') {
            return '{}';
        }
        if (is_string($raw)) {
            $t = trim($raw);
            if ($t === '') {
                return '{}';
            }
            json_decode($t);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new InvalidArgumentException('Invalid JSON field.');
            }

            return $t;
        }
        if (is_array($raw)) {
            return json_encode($raw, JSON_UNESCAPED_UNICODE) ?: '{}';
        }

        return '{}';
    }

    private static function jsonColumnToString(mixed $dbVal): string
    {
        if ($dbVal === null || $dbVal === '') {
            return '{}';
        }
        if (is_string($dbVal)) {
            return $dbVal;
        }
        if (is_array($dbVal)) {
            return json_encode($dbVal, JSON_UNESCAPED_UNICODE) ?: '{}';
        }

        return '{}';
    }

    // ---------------------------------------------------------------------
    //  Phase 5 — Audit checklist snapshots & answers
    // ---------------------------------------------------------------------

    /**
     * Generate (or return existing) immutable snapshot of an APPROVED checklist
     * version onto an audit. The snapshot freezes the item list as JSON and
     * stores a sha256 integrity hash. Re-running for the same (audit, template)
     * is idempotent.
     *
     * @return array{snapshot_id:int,items_count:int,sha256:string,created:bool}
     */
    public static function createSnapshotForAudit(PDO $pdo, int $auditId, int $versionId, int $userId): array
    {
        if ($auditId <= 0 || $versionId <= 0) {
            throw new InvalidArgumentException('audit_id and version_id are required.');
        }
        $v = self::getVersion($pdo, $versionId);
        if ($v === null) {
            throw new RuntimeException('Checklist version not found.');
        }
        $status = strtoupper((string)($v['status'] ?? ''));
        if ($status !== 'APPROVED') {
            throw new RuntimeException('Only APPROVED checklist versions can be snapshotted.');
        }
        $tid = (int)$v['template_id'];

        $st = $pdo->prepare(
            'SELECT * FROM ipca_compliance_audit_checklist_snapshots
              WHERE audit_id = ? AND template_id = ? LIMIT 1'
        );
        $st->execute(array($auditId, $tid));
        $existing = $st->fetch(PDO::FETCH_ASSOC);
        if (is_array($existing)) {
            return array(
                'snapshot_id' => (int)$existing['id'],
                'items_count' => (int)($v['items_count'] ?? 0),
                'sha256' => (string)$existing['items_snapshot_sha256'],
                'created' => false,
            );
        }

        $items = self::listItems($pdo, $versionId);
        $frozen = array();
        foreach ($items as $row) {
            $frozen[] = array(
                'id' => (int)$row['id'],
                'item_code' => (string)($row['item_code'] ?? ''),
                'parent_item_id' => isset($row['parent_item_id']) ? (int)$row['parent_item_id'] : null,
                'sort_order' => (int)($row['sort_order'] ?? 0),
                'item_type' => (string)($row['item_type'] ?? 'QUESTION'),
                'prompt' => (string)($row['prompt'] ?? ''),
                'guidance' => (string)($row['guidance'] ?? ''),
                'is_required' => (int)($row['is_required'] ?? 0),
                'weight' => isset($row['weight']) ? (int)$row['weight'] : null,
                'options_json' => self::jsonColumnToString($row['options_json'] ?? null),
                'reg_refs_json' => self::jsonColumnToString($row['reg_refs_json'] ?? null),
                'manual_refs_json' => self::jsonColumnToString($row['manual_refs_json'] ?? null),
            );
        }
        $json = json_encode($frozen, JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            throw new RuntimeException('Failed to encode checklist snapshot');
        }
        $sha = hash('sha256', $json);

        $ins = $pdo->prepare(
            'INSERT INTO ipca_compliance_audit_checklist_snapshots (
                audit_id, template_id, version_id, items_snapshot_json,
                items_snapshot_sha256, status, generated_by
            ) VALUES (?, ?, ?, CAST(? AS JSON), ?, ?, ?)'
        );
        $ins->execute(array(
            $auditId,
            $tid,
            $versionId,
            $json,
            $sha,
            'OPEN',
            $userId > 0 ? $userId : null,
        ));
        $sid = (int)$pdo->lastInsertId();

        return array(
            'snapshot_id' => $sid,
            'items_count' => count($frozen),
            'sha256' => $sha,
            'created' => true,
        );
    }

    /**
     * @return list<array<string,mixed>>
     */
    public static function listSnapshotsForAudit(PDO $pdo, int $auditId): array
    {
        $st = $pdo->prepare(
            'SELECT s.*,
                    t.template_code,
                    t.title AS template_title,
                    v.version_no
               FROM ipca_compliance_audit_checklist_snapshots s
               JOIN ipca_compliance_checklist_templates t ON t.id = s.template_id
               JOIN ipca_compliance_checklist_versions v ON v.id = s.version_id
              WHERE s.audit_id = ?
              ORDER BY s.id ASC'
        );
        $st->execute(array($auditId));
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : array();
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function getSnapshot(PDO $pdo, int $snapshotId): ?array
    {
        $st = $pdo->prepare(
            'SELECT s.*,
                    a.audit_code, a.title AS audit_title, a.status AS audit_status,
                    t.template_code, t.title AS template_title,
                    v.version_no
               FROM ipca_compliance_audit_checklist_snapshots s
               JOIN ipca_compliance_audits a ON a.id = s.audit_id
               JOIN ipca_compliance_checklist_templates t ON t.id = s.template_id
               JOIN ipca_compliance_checklist_versions v ON v.id = s.version_id
              WHERE s.id = ? LIMIT 1'
        );
        $st->execute(array($snapshotId));
        $row = $st->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * Decode the frozen items snapshot. Returns [] if the JSON is missing/invalid.
     *
     * @return list<array<string,mixed>>
     */
    public static function decodeSnapshotItems(mixed $raw): array
    {
        if ($raw === null || $raw === '') {
            return array();
        }
        $decoded = is_string($raw) ? json_decode($raw, true) : $raw;

        return is_array($decoded) ? array_values($decoded) : array();
    }

    /**
     * @return list<array<string,mixed>>
     */
    public static function listAnswers(PDO $pdo, int $snapshotId): array
    {
        $st = $pdo->prepare(
            'SELECT * FROM ipca_compliance_audit_checklist_answers
              WHERE snapshot_id = ?
              ORDER BY id ASC'
        );
        $st->execute(array($snapshotId));
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : array();
    }

    /**
     * Upsert (one per item) a snapshot answer. Used by audit fill UI.
     *
     * @param array{
     *   answer_value?:string|null,answer_text?:string|null,
     *   compliance_state?:string|null,
     *   answer_payload_json?:string|null
     * } $data
     */
    public static function upsertAnswer(PDO $pdo, int $snapshotId, int $itemId, array $data, int $userId): void
    {
        $snap = self::getSnapshot($pdo, $snapshotId);
        if ($snap === null) {
            throw new RuntimeException('Snapshot not found.');
        }
        if (!empty($snap['locked_at'])) {
            throw new RuntimeException('Snapshot is locked.');
        }
        $state = isset($data['compliance_state']) ? strtoupper(trim((string)$data['compliance_state'])) : null;
        if ($state !== null && $state !== '') {
            $allowed = array('COMPLIANT', 'NON_COMPLIANT', 'OBSERVATION', 'N_A', 'PARTIAL', 'PENDING');
            if (!in_array($state, $allowed, true)) {
                throw new InvalidArgumentException('Invalid compliance_state.');
            }
        } else {
            $state = null;
        }
        $val = isset($data['answer_value']) ? trim((string)$data['answer_value']) : '';
        $val = $val !== '' ? substr($val, 0, 64) : null;
        $txt = isset($data['answer_text']) ? trim((string)$data['answer_text']) : '';
        $txt = $txt !== '' ? $txt : null;

        $pj = isset($data['answer_payload_json']) ? trim((string)$data['answer_payload_json']) : '';
        if ($pj !== '') {
            json_decode($pj);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new InvalidArgumentException('answer_payload_json must be valid JSON.');
            }
        } else {
            $pj = null;
        }

        $st = $pdo->prepare(
            'SELECT id FROM ipca_compliance_audit_checklist_answers
              WHERE snapshot_id = ? AND item_id = ? LIMIT 1'
        );
        $st->execute(array($snapshotId, $itemId));
        $row = $st->fetch(PDO::FETCH_ASSOC);

        if (is_array($row) && (int)$row['id'] > 0) {
            $up = $pdo->prepare(
                'UPDATE ipca_compliance_audit_checklist_answers SET
                    answer_value = ?, answer_text = ?,
                    compliance_state = ?,
                    answer_payload_json = CASE WHEN ? IS NULL THEN answer_payload_json ELSE CAST(? AS JSON) END,
                    answered_by = ?,
                    answered_at = CURRENT_TIMESTAMP
                 WHERE id = ?'
            );
            $up->execute(array(
                $val,
                $txt,
                $state,
                $pj,
                $pj,
                $userId > 0 ? $userId : null,
                (int)$row['id'],
            ));
        } else {
            $ins = $pdo->prepare(
                'INSERT INTO ipca_compliance_audit_checklist_answers (
                    snapshot_id, item_id, answer_value, answer_text,
                    compliance_state, answer_payload_json, answered_by
                ) VALUES (?, ?, ?, ?, ?, CAST(? AS JSON), ?)'
            );
            $ins->execute(array(
                $snapshotId,
                $itemId,
                $val,
                $txt,
                $state,
                $pj ?? '{}',
                $userId > 0 ? $userId : null,
            ));
        }

        if (strtoupper((string)($snap['status'] ?? '')) === 'OPEN') {
            $pdo->prepare(
                'UPDATE ipca_compliance_audit_checklist_snapshots SET status = \'IN_PROGRESS\' WHERE id = ?'
            )->execute(array($snapshotId));
        }
    }

    public static function setSnapshotStatus(PDO $pdo, int $snapshotId, string $status, int $userId): void
    {
        $snap = self::getSnapshot($pdo, $snapshotId);
        if ($snap === null) {
            throw new RuntimeException('Snapshot not found.');
        }
        if (!empty($snap['locked_at'])) {
            throw new RuntimeException('Snapshot is locked.');
        }
        $s = strtoupper(trim($status));
        if (!in_array($s, array('OPEN', 'IN_PROGRESS', 'COMPLETED', 'CANCELLED'), true)) {
            throw new InvalidArgumentException('Invalid snapshot status.');
        }
        $now = date('Y-m-d H:i:s');
        $lockedAt = $s === 'COMPLETED' ? $now : null;
        $lockedBy = $lockedAt !== null && $userId > 0 ? $userId : null;
        $pdo->prepare(
            'UPDATE ipca_compliance_audit_checklist_snapshots SET
                status = ?,
                locked_at = COALESCE(?, locked_at),
                locked_by = COALESCE(?, locked_by)
             WHERE id = ?'
        )->execute(array($s, $lockedAt, $lockedBy, $snapshotId));
    }
}
