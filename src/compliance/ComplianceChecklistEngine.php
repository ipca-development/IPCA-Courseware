<?php
declare(strict_types=1);

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
}
