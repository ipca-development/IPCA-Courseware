<?php
declare(strict_types=1);

require_once __DIR__ . '/ComplianceAutomationDispatch.php';

/**
 * Phase 4+ — Manual change requests, drafts, release packages (governance metadata only).
 */
final class ComplianceManualControlEngine
{
    /** @var list<string> */
    private const MANUAL_KINDS = array(
        'rl_edition', 'rl_block', 'easa_node', 'om_canonical', 'omm_canonical', 'external_doc',
    );

    public static function normalizeManualKind(string $v): string
    {
        $v = strtolower(trim($v));

        return in_array($v, self::MANUAL_KINDS, true) ? $v : 'om_canonical';
    }

    private static function nextCode(PDO $pdo, string $table, string $column, string $prefix): string
    {
        $year = (int)date('Y');
        $p = $prefix . '-' . $year . '-';
        $st = $pdo->prepare("SELECT `{$column}` FROM `{$table}` WHERE `{$column}` LIKE ? ORDER BY id DESC LIMIT 80");
        $st->execute(array($p . '%'));
        $max = 0;
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $code = (string)($row[$column] ?? '');
            if (!str_starts_with($code, $p)) {
                continue;
            }
            $n = (int)substr($code, strlen($p));
            if ($n > $max) {
                $max = $n;
            }
        }

        return $p . str_pad((string)($max + 1), 5, '0', STR_PAD_LEFT);
    }

    // --- Change requests ---

    /**
     * @return list<array<string,mixed>>
     */
    public static function listChangeRequests(PDO $pdo, int $limit = 150): array
    {
        $limit = max(1, min(500, $limit));
        $sql = 'SELECT * FROM ipca_compliance_manual_change_requests ORDER BY updated_at DESC, id DESC LIMIT ' . (int)$limit;
        $st = $pdo->query($sql);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : array();
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function getChangeRequest(PDO $pdo, int $id): ?array
    {
        $st = $pdo->prepare('SELECT * FROM ipca_compliance_manual_change_requests WHERE id = ? LIMIT 1');
        $st->execute(array($id));
        $row = $st->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @param array{
     *   request_code?:string,title:string,description:string,manual_kind?:string,
     *   manual_ref_id?:string,manual_label?:string,proposed_text?:string,rationale?:string,
     *   priority?:string,status?:string,case_id?:int|null
     * } $data
     */
    public static function createChangeRequest(PDO $pdo, array $data, int $userId): int
    {
        $code = trim((string)($data['request_code'] ?? ''));
        if ($code === '') {
            $code = self::nextCode($pdo, 'ipca_compliance_manual_change_requests', 'request_code', 'MCR');
        }
        $title = trim((string)($data['title'] ?? ''));
        $desc = trim((string)($data['description'] ?? ''));
        if ($title === '' || $desc === '') {
            throw new InvalidArgumentException('Title and description are required.');
        }
        $kind = self::normalizeManualKind((string)($data['manual_kind'] ?? 'om_canonical'));
        $prio = strtoupper(trim((string)($data['priority'] ?? 'NORMAL')));
        if (!in_array($prio, array('LOW', 'NORMAL', 'HIGH', 'URGENT'), true)) {
            $prio = 'NORMAL';
        }
        $status = strtoupper(trim((string)($data['status'] ?? 'DRAFT')));
        if (!in_array($status, array('DRAFT', 'SUBMITTED', 'UNDER_REVIEW', 'APPROVED', 'REJECTED', 'RELEASED', 'CANCELLED'), true)) {
            $status = 'DRAFT';
        }
        $caseId = isset($data['case_id']) && (int)$data['case_id'] > 0 ? (int)$data['case_id'] : null;

        $ins = $pdo->prepare(
            'INSERT INTO ipca_compliance_manual_change_requests (
                case_id, request_code, title, description, manual_kind, manual_ref_id, manual_label,
                proposed_text, rationale, status, priority, raised_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $ins->execute(array(
            $caseId,
            substr($code, 0, 64),
            substr($title, 0, 255),
            $desc,
            $kind,
            isset($data['manual_ref_id']) && trim((string)$data['manual_ref_id']) !== '' ? substr(trim((string)$data['manual_ref_id']), 0, 191) : null,
            isset($data['manual_label']) && trim((string)$data['manual_label']) !== '' ? substr(trim((string)$data['manual_label']), 0, 255) : null,
            isset($data['proposed_text']) ? trim((string)$data['proposed_text']) : null,
            isset($data['rationale']) && trim((string)$data['rationale']) !== '' ? trim((string)$data['rationale']) : null,
            $status,
            $prio,
            $userId > 0 ? $userId : null,
        ));

        $newId = (int)$pdo->lastInsertId();

        ComplianceAutomationDispatch::fire($pdo, 'compliance.manual.change_requested', array(
            'change_request_id' => $newId,
            'request_code' => substr($code, 0, 64),
            'title' => substr($title, 0, 255),
            'manual_kind' => $kind,
            'priority' => $prio,
            'status' => $status,
            'case_id' => $caseId,
            'raised_by_user_id' => $userId,
        ));

        return $newId;
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function updateChangeRequest(PDO $pdo, int $id, array $data, int $userId): void
    {
        $row = self::getChangeRequest($pdo, $id);
        if ($row === null) {
            throw new RuntimeException('Change request not found.');
        }
        if (!empty($row['locked_at'])) {
            throw new RuntimeException('Change request is locked.');
        }
        $title = array_key_exists('title', $data) ? trim((string)$data['title']) : (string)$row['title'];
        $desc = array_key_exists('description', $data) ? trim((string)$data['description']) : (string)$row['description'];
        if ($title === '' || $desc === '') {
            throw new InvalidArgumentException('Title and description are required.');
        }
        $kind = array_key_exists('manual_kind', $data)
            ? self::normalizeManualKind((string)$data['manual_kind'])
            : (string)$row['manual_kind'];
        $ref = array_key_exists('manual_ref_id', $data) ? trim((string)$data['manual_ref_id']) : (string)($row['manual_ref_id'] ?? '');
        $lab = array_key_exists('manual_label', $data) ? trim((string)$data['manual_label']) : (string)($row['manual_label'] ?? '');
        $prop = array_key_exists('proposed_text', $data) ? (string)$data['proposed_text'] : (string)($row['proposed_text'] ?? '');
        $rat = array_key_exists('rationale', $data) ? trim((string)$data['rationale']) : (string)($row['rationale'] ?? '');
        $prio = array_key_exists('priority', $data) ? strtoupper(trim((string)$data['priority'])) : (string)$row['priority'];
        if (!in_array($prio, array('LOW', 'NORMAL', 'HIGH', 'URGENT'), true)) {
            $prio = 'NORMAL';
        }

        $st = $pdo->prepare(
            'UPDATE ipca_compliance_manual_change_requests SET
                title = ?, description = ?, manual_kind = ?, manual_ref_id = ?, manual_label = ?,
                proposed_text = ?, rationale = ?, priority = ?
             WHERE id = ?'
        );
        $st->execute(array(
            substr($title, 0, 255),
            $desc,
            $kind,
            $ref !== '' ? substr($ref, 0, 191) : null,
            $lab !== '' ? substr($lab, 0, 255) : null,
            $prop !== '' ? $prop : null,
            $rat !== '' ? $rat : null,
            $prio,
            $id,
        ));
    }

    public static function setChangeRequestStatus(PDO $pdo, int $id, string $status, int $userId, ?string $approverName): void
    {
        $row = self::getChangeRequest($pdo, $id);
        if ($row === null) {
            throw new RuntimeException('Change request not found.');
        }
        if (!empty($row['locked_at'])) {
            throw new RuntimeException('Change request is locked.');
        }
        $s = strtoupper(trim($status));
        if (!in_array($s, array('DRAFT', 'SUBMITTED', 'UNDER_REVIEW', 'APPROVED', 'REJECTED', 'RELEASED', 'CANCELLED'), true)) {
            throw new InvalidArgumentException('Invalid status.');
        }
        $now = date('Y-m-d H:i:s');
        $apprBy = null;
        $apprName = null;
        $apprAt = null;
        $relAt = null;
        if ($s === 'APPROVED') {
            $n = trim((string)($approverName ?? ''));
            if ($n === '') {
                throw new InvalidArgumentException('Approver name is required for APPROVED.');
            }
            $apprBy = $userId > 0 ? $userId : null;
            $apprName = substr($n, 0, 255);
            $apprAt = $now;
        }
        if ($s === 'RELEASED') {
            $relAt = $now;
        }
        $pdo->prepare(
            'UPDATE ipca_compliance_manual_change_requests SET
                status = ?,
                approved_by = ?, approved_by_name = ?, approved_at = ?,
                released_at = COALESCE(?, released_at)
             WHERE id = ?'
        )->execute(array(
            $s,
            $apprBy,
            $apprName,
            $apprAt,
            $relAt,
            $id,
        ));

        if ($s === 'APPROVED') {
            ComplianceAutomationDispatch::fire($pdo, 'compliance.manual.cr_approved', array(
                'change_request_id' => $id,
                'request_code' => (string)($row['request_code'] ?? ''),
                'title' => (string)($row['title'] ?? ''),
                'manual_kind' => (string)($row['manual_kind'] ?? ''),
                'approved_by_user_id' => $userId,
                'approved_by_name' => $apprName,
            ));
        }
    }

    /**
     * @return list<array<string,mixed>>
     */
    public static function listCrLinks(PDO $pdo, int $requestId): array
    {
        $st = $pdo->prepare('SELECT * FROM ipca_compliance_manual_change_request_links WHERE request_id = ? ORDER BY id ASC');
        $st->execute(array($requestId));
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : array();
    }

    public static function addCrLink(
        PDO $pdo,
        int $requestId,
        string $entityType,
        ?int $entityId,
        ?string $externalRef,
        ?string $relation,
        int $userId
    ): void {
        if (self::getChangeRequest($pdo, $requestId) === null) {
            throw new RuntimeException('Change request not found.');
        }
        $et = strtolower(trim($entityType));
        if ($et === '') {
            throw new InvalidArgumentException('entity_type required.');
        }
        if (($entityId === null || $entityId <= 0) && ($externalRef === null || trim((string)$externalRef) === '')) {
            throw new InvalidArgumentException('entity_id or external_ref required.');
        }
        $ins = $pdo->prepare(
            'INSERT INTO ipca_compliance_manual_change_request_links (
                request_id, entity_type, entity_id, external_ref, relation, created_by
            ) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $ins->execute(array(
            $requestId,
            substr($et, 0, 32),
            $entityId !== null && $entityId > 0 ? $entityId : null,
            $externalRef !== null && trim($externalRef) !== '' ? substr(trim($externalRef), 0, 255) : null,
            $relation !== null && trim($relation) !== '' ? substr(trim($relation), 0, 32) : null,
            $userId > 0 ? $userId : null,
        ));
    }

    // --- Drafts ---

    /**
     * @return list<array<string,mixed>>
     */
    public static function listDrafts(PDO $pdo, int $limit = 150): array
    {
        $limit = max(1, min(500, $limit));
        $sql = 'SELECT * FROM ipca_compliance_manual_drafts ORDER BY updated_at DESC, id DESC LIMIT ' . (int)$limit;
        $st = $pdo->query($sql);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : array();
    }

    /**
     * Drafts eligible to be bundled into a release package — APPROVED or PUBLISHED.
     *
     * @return list<array<string,mixed>>
     */
    public static function listReleasableDrafts(PDO $pdo, int $limit = 200): array
    {
        $limit = max(1, min(500, $limit));
        $sql = "SELECT id, draft_code, draft_title, status, manual_kind, manual_label, manual_ref_id, version_no
                  FROM ipca_compliance_manual_drafts
                 WHERE status IN ('APPROVED','PUBLISHED')
                 ORDER BY draft_code ASC
                 LIMIT " . (int)$limit;
        $st = $pdo->query($sql);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : array();
    }

    /**
     * Decode the drafts_json column to a plain list of draft IDs.
     * Accepts: `[1,2]`, `[{"id":1},...]`, `[{"draft_id":1},...]`.
     *
     * @return list<int>
     */
    public static function extractDraftIds(mixed $rawJson): array
    {
        if ($rawJson === null || $rawJson === '') {
            return array();
        }
        $decoded = is_string($rawJson) ? json_decode($rawJson, true) : $rawJson;
        if (!is_array($decoded)) {
            return array();
        }
        $ids = array();
        foreach ($decoded as $entry) {
            if (is_int($entry) && $entry > 0) {
                $ids[] = $entry;
                continue;
            }
            if (is_string($entry) && ctype_digit($entry) && (int)$entry > 0) {
                $ids[] = (int)$entry;
                continue;
            }
            if (is_array($entry)) {
                if (isset($entry['id']) && (int)$entry['id'] > 0) {
                    $ids[] = (int)$entry['id'];
                    continue;
                }
                if (isset($entry['draft_id']) && (int)$entry['draft_id'] > 0) {
                    $ids[] = (int)$entry['draft_id'];
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Build a friendly drafts_json payload from selected IDs.
     * Stores `{id, draft_code, draft_title}` per entry so the PDF and audit
     * trail show meaningful labels even if a draft is later renamed.
     */
    public static function buildDraftsJsonFromIds(PDO $pdo, array $ids): string
    {
        $clean = array();
        foreach ($ids as $v) {
            $n = (int)$v;
            if ($n > 0) {
                $clean[$n] = $n;
            }
        }
        if ($clean === array()) {
            return '[]';
        }
        $placeholders = implode(',', array_fill(0, count($clean), '?'));
        $st = $pdo->prepare(
            'SELECT id, draft_code, draft_title
               FROM ipca_compliance_manual_drafts
              WHERE id IN (' . $placeholders . ')
              ORDER BY draft_code ASC'
        );
        $st->execute(array_values($clean));
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: array();
        $out = array();
        foreach ($rows as $r) {
            $out[] = array(
                'id' => (int)$r['id'],
                'draft_code' => (string)($r['draft_code'] ?? ''),
                'draft_title' => (string)($r['draft_title'] ?? ''),
            );
        }

        return json_encode($out, JSON_UNESCAPED_UNICODE) ?: '[]';
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function getDraft(PDO $pdo, int $id): ?array
    {
        $st = $pdo->prepare('SELECT * FROM ipca_compliance_manual_drafts WHERE id = ? LIMIT 1');
        $st->execute(array($id));
        $row = $st->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @param array{
     *   draft_code?:string,request_id?:int|null,manual_kind?:string,manual_ref_id?:string,
     *   manual_label?:string,draft_title:string,draft_body:string,status?:string,version_no?:int
     * } $data
     */
    public static function createDraft(PDO $pdo, array $data, int $userId): int
    {
        $code = trim((string)($data['draft_code'] ?? ''));
        if ($code === '') {
            $code = self::nextCode($pdo, 'ipca_compliance_manual_drafts', 'draft_code', 'MDR');
        }
        $title = trim((string)($data['draft_title'] ?? ''));
        $body = trim((string)($data['draft_body'] ?? ''));
        if ($title === '' || $body === '') {
            throw new InvalidArgumentException('Draft title and body are required.');
        }
        $kind = self::normalizeManualKind((string)($data['manual_kind'] ?? 'om_canonical'));
        $rid = isset($data['request_id']) && (int)$data['request_id'] > 0 ? (int)$data['request_id'] : null;
        if ($rid !== null && self::getChangeRequest($pdo, $rid) === null) {
            throw new RuntimeException('Linked change request not found.');
        }
        $stat = strtoupper(trim((string)($data['status'] ?? 'DRAFT')));
        if (!in_array($stat, array('DRAFT', 'UNDER_REVIEW', 'APPROVED', 'REJECTED', 'PUBLISHED', 'ARCHIVED'), true)) {
            $stat = 'DRAFT';
        }
        $vn = isset($data['version_no']) ? max(1, (int)$data['version_no']) : 1;

        $ins = $pdo->prepare(
            'INSERT INTO ipca_compliance_manual_drafts (
                request_id, draft_code, manual_kind, manual_ref_id, manual_label,
                draft_title, draft_body, status, version_no, created_by, updated_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $ins->execute(array(
            $rid,
            substr($code, 0, 64),
            $kind,
            isset($data['manual_ref_id']) && trim((string)$data['manual_ref_id']) !== '' ? substr(trim((string)$data['manual_ref_id']), 0, 191) : null,
            isset($data['manual_label']) && trim((string)$data['manual_label']) !== '' ? substr(trim((string)$data['manual_label']), 0, 255) : null,
            substr($title, 0, 255),
            $body,
            $stat,
            $vn,
            $userId > 0 ? $userId : null,
            $userId > 0 ? $userId : null,
        ));

        return (int)$pdo->lastInsertId();
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function updateDraft(PDO $pdo, int $id, array $data, int $userId): void
    {
        $row = self::getDraft($pdo, $id);
        if ($row === null) {
            throw new RuntimeException('Draft not found.');
        }
        if (!empty($row['locked_at'])) {
            throw new RuntimeException('Draft is locked.');
        }
        $title = array_key_exists('draft_title', $data) ? trim((string)$data['draft_title']) : (string)$row['draft_title'];
        $body = array_key_exists('draft_body', $data) ? trim((string)$data['draft_body']) : (string)$row['draft_body'];
        if ($title === '' || $body === '') {
            throw new InvalidArgumentException('Draft title and body are required.');
        }
        $kind = array_key_exists('manual_kind', $data)
            ? self::normalizeManualKind((string)$data['manual_kind'])
            : (string)$row['manual_kind'];
        $ref = array_key_exists('manual_ref_id', $data) ? trim((string)$data['manual_ref_id']) : (string)($row['manual_ref_id'] ?? '');
        $lab = array_key_exists('manual_label', $data) ? trim((string)$data['manual_label']) : (string)($row['manual_label'] ?? '');

        $st = $pdo->prepare(
            'UPDATE ipca_compliance_manual_drafts SET
                manual_kind = ?, manual_ref_id = ?, manual_label = ?,
                draft_title = ?, draft_body = ?, updated_by = ?
             WHERE id = ?'
        );
        $st->execute(array(
            $kind,
            $ref !== '' ? substr($ref, 0, 191) : null,
            $lab !== '' ? substr($lab, 0, 255) : null,
            substr($title, 0, 255),
            $body,
            $userId > 0 ? $userId : null,
            $id,
        ));
    }

    public static function setDraftStatus(PDO $pdo, int $id, string $status, int $userId, ?string $approverName): void
    {
        $row = self::getDraft($pdo, $id);
        if ($row === null) {
            throw new RuntimeException('Draft not found.');
        }
        if (!empty($row['locked_at'])) {
            throw new RuntimeException('Draft is locked.');
        }
        $s = strtoupper(trim($status));
        if (!in_array($s, array('DRAFT', 'UNDER_REVIEW', 'APPROVED', 'REJECTED', 'PUBLISHED', 'ARCHIVED'), true)) {
            throw new InvalidArgumentException('Invalid status.');
        }
        $now = date('Y-m-d H:i:s');
        $apprBy = null;
        $apprName = null;
        $apprAt = null;
        $lockedAt = null;
        $lockedBy = null;
        if ($s === 'APPROVED' || $s === 'PUBLISHED') {
            $n = trim((string)($approverName ?? ''));
            if ($n === '') {
                throw new InvalidArgumentException('Approver name is required for ' . $s . '.');
            }
            $apprBy = $userId > 0 ? $userId : null;
            $apprName = substr($n, 0, 255);
            $apprAt = $now;
        }
        if ($s === 'PUBLISHED') {
            $lockedAt = $now;
            $lockedBy = $userId > 0 ? $userId : null;
        }
        $pdo->prepare(
            'UPDATE ipca_compliance_manual_drafts SET
                status = ?,
                approved_by = ?, approved_by_name = ?, approved_at = ?,
                locked_at = COALESCE(?, locked_at), locked_by = COALESCE(?, locked_by),
                updated_by = ?
             WHERE id = ?'
        )->execute(array(
            $s,
            $apprBy,
            $apprName,
            $apprAt,
            $lockedAt,
            $lockedBy,
            $userId > 0 ? $userId : null,
            $id,
        ));

        if ($s === 'PUBLISHED') {
            ComplianceAutomationDispatch::fire($pdo, 'compliance.manual.draft_published', array(
                'draft_id' => $id,
                'draft_code' => (string)($row['draft_code'] ?? ''),
                'draft_title' => (string)($row['draft_title'] ?? ''),
                'manual_kind' => (string)($row['manual_kind'] ?? ''),
                'request_id' => isset($row['request_id']) ? (int)$row['request_id'] : null,
                'published_by_user_id' => $userId,
                'published_by_name' => $apprName,
            ));
        }
    }

    // --- Release packages ---

    /**
     * @return list<array<string,mixed>>
     */
    public static function listPackages(PDO $pdo, int $limit = 100): array
    {
        $limit = max(1, min(300, $limit));
        $sql = 'SELECT * FROM ipca_compliance_manual_release_packages ORDER BY updated_at DESC, id DESC LIMIT ' . (int)$limit;
        $st = $pdo->query($sql);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : array();
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function getPackage(PDO $pdo, int $id): ?array
    {
        $st = $pdo->prepare('SELECT * FROM ipca_compliance_manual_release_packages WHERE id = ? LIMIT 1');
        $st->execute(array($id));
        $row = $st->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @param array{
     *   package_code?:string,title:string,manual_code?:string,target_revision?:string,
     *   effective_date?:string,status?:string,drafts_json?:string|null
     * } $data
     */
    public static function createPackage(PDO $pdo, array $data, int $userId): int
    {
        $code = trim((string)($data['package_code'] ?? ''));
        if ($code === '') {
            $code = self::nextCode($pdo, 'ipca_compliance_manual_release_packages', 'package_code', 'MRP');
        }
        $title = trim((string)($data['title'] ?? ''));
        if ($title === '') {
            throw new InvalidArgumentException('Package title is required.');
        }
        $stat = strtoupper(trim((string)($data['status'] ?? 'PLANNED')));
        if (!in_array($stat, array('PLANNED', 'DRAFTING', 'REVIEW', 'APPROVED', 'RELEASED', 'SUPERSEDED', 'CANCELLED'), true)) {
            $stat = 'PLANNED';
        }
        $dj = isset($data['drafts_json']) ? trim((string)$data['drafts_json']) : '';
        if ($dj !== '') {
            json_decode($dj);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new InvalidArgumentException('drafts_json must be valid JSON.');
            }
        } else {
            $dj = '[]';
        }
        $ins = $pdo->prepare(
            'INSERT INTO ipca_compliance_manual_release_packages (
                package_code, title, manual_code, target_revision, effective_date, status,
                drafts_json, created_by, updated_by
            ) VALUES (?, ?, ?, ?, ?, ?, CAST(? AS JSON), ?, ?)'
        );
        $ins->execute(array(
            substr($code, 0, 64),
            substr($title, 0, 255),
            isset($data['manual_code']) ? (trim((string)$data['manual_code']) !== '' ? substr(trim((string)$data['manual_code']), 0, 32) : null) : null,
            isset($data['target_revision']) ? (trim((string)$data['target_revision']) !== '' ? substr(trim((string)$data['target_revision']), 0, 32) : null) : null,
            isset($data['effective_date']) && trim((string)$data['effective_date']) !== '' ? substr(trim((string)$data['effective_date']), 0, 10) : null,
            $stat,
            $dj,
            $userId > 0 ? $userId : null,
            $userId > 0 ? $userId : null,
        ));

        return (int)$pdo->lastInsertId();
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function updatePackage(PDO $pdo, int $id, array $data, int $userId): void
    {
        $row = self::getPackage($pdo, $id);
        if ($row === null) {
            throw new RuntimeException('Package not found.');
        }
        if (!empty($row['locked_at'])) {
            throw new RuntimeException('Package is locked.');
        }
        $title = array_key_exists('title', $data) ? trim((string)$data['title']) : (string)$row['title'];
        if ($title === '') {
            throw new InvalidArgumentException('Title is required.');
        }
        $dj = array_key_exists('drafts_json', $data) ? trim((string)$data['drafts_json']) : null;
        if ($dj !== null) {
            json_decode($dj);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new InvalidArgumentException('drafts_json must be valid JSON.');
            }
        }
        $mc = array_key_exists('manual_code', $data) ? trim((string)$data['manual_code']) : (string)($row['manual_code'] ?? '');
        $tr = array_key_exists('target_revision', $data) ? trim((string)$data['target_revision']) : (string)($row['target_revision'] ?? '');
        $ed = array_key_exists('effective_date', $data) ? trim((string)$data['effective_date']) : (string)($row['effective_date'] ?? '');

        if ($dj !== null) {
            $pdo->prepare(
                'UPDATE ipca_compliance_manual_release_packages SET
                    title = ?, manual_code = ?, target_revision = ?, effective_date = ?,
                    drafts_json = CAST(? AS JSON), updated_by = ?
                 WHERE id = ?'
            )->execute(array(
                substr($title, 0, 255),
                $mc !== '' ? substr($mc, 0, 32) : null,
                $tr !== '' ? substr($tr, 0, 32) : null,
                $ed !== '' ? substr($ed, 0, 10) : null,
                $dj,
                $userId > 0 ? $userId : null,
                $id,
            ));
        } else {
            $pdo->prepare(
                'UPDATE ipca_compliance_manual_release_packages SET
                    title = ?, manual_code = ?, target_revision = ?, effective_date = ?, updated_by = ?
                 WHERE id = ?'
            )->execute(array(
                substr($title, 0, 255),
                $mc !== '' ? substr($mc, 0, 32) : null,
                $tr !== '' ? substr($tr, 0, 32) : null,
                $ed !== '' ? substr($ed, 0, 10) : null,
                $userId > 0 ? $userId : null,
                $id,
            ));
        }
    }

    public static function addPackageApproval(
        PDO $pdo,
        int $packageId,
        string $approverName,
        ?string $approverRole,
        string $decision,
        ?string $comments,
        int $userId
    ): int {
        if (self::getPackage($pdo, $packageId) === null) {
            throw new RuntimeException('Package not found.');
        }
        $name = trim($approverName);
        if ($name === '') {
            throw new InvalidArgumentException('Approver name is required.');
        }
        $d = strtoupper(trim($decision));
        if (!in_array($d, array('PENDING', 'APPROVED', 'REJECTED', 'RECUSED'), true)) {
            $d = 'PENDING';
        }
        $ins = $pdo->prepare(
            'INSERT INTO ipca_compliance_manual_release_approvals (
                package_id, approver_user_id, approver_name, approver_role, decision, comments, decided_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $now = $d === 'PENDING' ? null : date('Y-m-d H:i:s');
        $ins->execute(array(
            $packageId,
            $userId > 0 ? $userId : null,
            substr($name, 0, 255),
            $approverRole !== null && trim($approverRole) !== '' ? substr(trim($approverRole), 0, 64) : null,
            $d,
            $comments !== null && trim($comments) !== '' ? trim($comments) : null,
            $now,
        ));

        return (int)$pdo->lastInsertId();
    }

    /**
     * @return list<array<string,mixed>>
     */
    public static function listPackageApprovals(PDO $pdo, int $packageId): array
    {
        $st = $pdo->prepare(
            'SELECT * FROM ipca_compliance_manual_release_approvals WHERE package_id = ? ORDER BY id ASC'
        );
        $st->execute(array($packageId));
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : array();
    }

    public static function setPackageWorkflowStatus(PDO $pdo, int $id, string $status, int $userId, ?string $actorName): void
    {
        $row = self::getPackage($pdo, $id);
        if ($row === null) {
            throw new RuntimeException('Package not found.');
        }
        if (!empty($row['locked_at'])) {
            throw new RuntimeException('Package is locked.');
        }
        $s = strtoupper(trim($status));
        if (!in_array($s, array('PLANNED', 'DRAFTING', 'REVIEW', 'APPROVED', 'RELEASED', 'SUPERSEDED', 'CANCELLED'), true)) {
            throw new InvalidArgumentException('Invalid package status.');
        }
        $now = date('Y-m-d H:i:s');
        $releasedAt = null;
        $releasedBy = null;
        $releasedName = null;
        $lockedAt = null;
        $lockedBy = null;
        if ($s === 'RELEASED') {
            $nm = trim((string)($actorName ?? ''));
            if ($nm === '') {
                throw new InvalidArgumentException('Releaser name is required to mark RELEASED.');
            }
            $releasedAt = $now;
            $releasedBy = $userId > 0 ? $userId : null;
            $releasedName = substr($nm, 0, 255);
            $lockedAt = $now;
            $lockedBy = $userId > 0 ? $userId : null;
        }
        $pdo->prepare(
            'UPDATE ipca_compliance_manual_release_packages SET
                status = ?,
                released_at = COALESCE(?, released_at),
                released_by = COALESCE(?, released_by),
                released_by_name = COALESCE(?, released_by_name),
                locked_at = COALESCE(?, locked_at),
                locked_by = COALESCE(?, locked_by),
                updated_by = ?
             WHERE id = ?'
        )->execute(array(
            $s,
            $releasedAt,
            $releasedBy,
            $releasedName,
            $lockedAt,
            $lockedBy,
            $userId > 0 ? $userId : null,
            $id,
        ));

        if ($s === 'RELEASED') {
            ComplianceAutomationDispatch::fire($pdo, 'compliance.manual.package_released', array(
                'package_id' => $id,
                'package_code' => (string)($row['package_code'] ?? ''),
                'title' => (string)($row['title'] ?? ''),
                'manual_code' => (string)($row['manual_code'] ?? ''),
                'target_revision' => (string)($row['target_revision'] ?? ''),
                'effective_date' => (string)($row['effective_date'] ?? ''),
                'released_by_user_id' => $userId,
                'released_by_name' => $releasedName,
            ));
        }
    }
}
