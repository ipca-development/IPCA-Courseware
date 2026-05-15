<?php
declare(strict_types=1);

require_once __DIR__ . '/ComplianceAutomationDispatch.php';
require_once __DIR__ . '/ComplianceApprovalEngine.php';

/**
 * Phase 5 — Audit lifecycle CRUD.
 *
 * Engine only handles the audit record itself (and a couple of small selectors).
 * Checklist snapshots live in ComplianceChecklistEngine, findings live in
 * ComplianceFindingEngine.
 */
final class ComplianceAuditEngine
{
    /** @var list<string> */
    private const STATUSES = array(
        'PLANNED', 'SCHEDULED', 'IN_PROGRESS', 'FIELDWORK_COMPLETE',
        'REPORT_DRAFT', 'REPORT_ISSUED', 'WAITING_AUTHORITY', 'CLOSED', 'CANCELLED',
    );

    /** @var list<string> */
    private const AUTHORITIES = array('BCAA', 'FAA', 'EASA', 'INTERNAL', 'OTHER');

    /** @var list<string> */
    private const CATEGORIES = array('SAFETY', 'COMPLIANCE', 'QUALITY', 'SECURITY', 'OPERATIONAL', 'OTHER');

    public static function normalizeStatus(string $v): string
    {
        $u = strtoupper(trim($v));

        return in_array($u, self::STATUSES, true) ? $u : 'PLANNED';
    }

    public static function normalizeAuthority(string $v): string
    {
        $u = strtoupper(trim($v));

        return in_array($u, self::AUTHORITIES, true) ? $u : 'INTERNAL';
    }

    public static function normalizeCategory(?string $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $u = strtoupper(trim($v));
        if ($u === '') {
            return null;
        }

        return in_array($u, self::CATEGORIES, true) ? $u : null;
    }

    public static function generateAuditCode(PDO $pdo): string
    {
        $year = (int)date('Y');
        $prefix = 'AUD-' . $year . '-';
        $st = $pdo->prepare('SELECT audit_code FROM ipca_compliance_audits WHERE audit_code LIKE ? ORDER BY id DESC LIMIT 80');
        $st->execute(array($prefix . '%'));
        $max = 0;
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $code = (string)($row['audit_code'] ?? '');
            if (!str_starts_with($code, $prefix)) {
                continue;
            }
            $n = (int)substr($code, strlen($prefix));
            if ($n > $max) {
                $max = $n;
            }
        }

        return $prefix . str_pad((string)($max + 1), 4, '0', STR_PAD_LEFT);
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function getById(PDO $pdo, int $id): ?array
    {
        $st = $pdo->prepare('SELECT * FROM ipca_compliance_audits WHERE id = ? LIMIT 1');
        $st->execute(array($id));
        $row = $st->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public static function listRecent(PDO $pdo, int $limit = 200): array
    {
        $limit = max(1, min(500, $limit));
        $sql = 'SELECT * FROM ipca_compliance_audits ORDER BY updated_at DESC, id DESC LIMIT ' . (int)$limit;
        $st = $pdo->query($sql);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : array();
    }

    /**
     * @param array{
     *   audit_code?:string,title:string,authority?:string,audit_category?:string,
     *   audit_type?:string,audit_entity?:string,external_ref?:string,status?:string,
     *   subject?:string,start_date?:string,end_date?:string,case_id?:int|null
     * } $data
     */
    public static function create(PDO $pdo, array $data, int $userId): int
    {
        $title = trim((string)($data['title'] ?? ''));
        if ($title === '') {
            throw new InvalidArgumentException('Audit title is required.');
        }
        $type = trim((string)($data['audit_type'] ?? 'INTERNAL'));
        if ($type === '') {
            $type = 'INTERNAL';
        }
        $code = trim((string)($data['audit_code'] ?? ''));
        if ($code === '') {
            $code = self::generateAuditCode($pdo);
        }
        $status = self::normalizeStatus((string)($data['status'] ?? 'PLANNED'));
        $authority = self::normalizeAuthority((string)($data['authority'] ?? 'INTERNAL'));
        $category = self::normalizeCategory($data['audit_category'] ?? null);
        $entity = isset($data['audit_entity']) ? trim((string)$data['audit_entity']) : '';
        $external = isset($data['external_ref']) ? trim((string)$data['external_ref']) : '';
        $subject = isset($data['subject']) ? trim((string)$data['subject']) : '';
        $start = isset($data['start_date']) ? trim((string)$data['start_date']) : '';
        $end = isset($data['end_date']) ? trim((string)$data['end_date']) : '';
        $caseId = isset($data['case_id']) && (int)$data['case_id'] > 0 ? (int)$data['case_id'] : null;

        $ins = $pdo->prepare(
            'INSERT INTO ipca_compliance_audits (
                case_id, audit_code, title, authority, audit_category, audit_type, audit_entity,
                external_ref, status, subject, start_date, end_date, created_by, updated_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $ins->execute(array(
            $caseId,
            substr($code, 0, 64),
            substr($title, 0, 255),
            $authority,
            $category,
            substr($type, 0, 64),
            $entity !== '' ? substr($entity, 0, 128) : null,
            $external !== '' ? substr($external, 0, 128) : null,
            $status,
            $subject !== '' ? $subject : null,
            $start !== '' ? substr($start, 0, 10) : null,
            $end !== '' ? substr($end, 0, 10) : null,
            $userId > 0 ? $userId : null,
            $userId > 0 ? $userId : null,
        ));
        $newId = (int)$pdo->lastInsertId();

        ComplianceAutomationDispatch::fire($pdo, 'compliance.audit.created', array(
            'audit_id' => $newId,
            'audit_code' => substr($code, 0, 64),
            'title' => substr($title, 0, 255),
            'authority' => $authority,
            'audit_type' => $type,
            'status' => $status,
            'created_by_user_id' => $userId,
        ));

        return $newId;
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function update(PDO $pdo, int $id, array $data, int $userId): void
    {
        $row = self::getById($pdo, $id);
        if ($row === null) {
            throw new RuntimeException('Audit not found.');
        }
        if (!empty($row['locked_at'])) {
            throw new RuntimeException('Audit is locked.');
        }
        $title = array_key_exists('title', $data) ? trim((string)$data['title']) : (string)$row['title'];
        if ($title === '') {
            throw new InvalidArgumentException('Audit title is required.');
        }
        $authority = array_key_exists('authority', $data)
            ? self::normalizeAuthority((string)$data['authority']) : (string)$row['authority'];
        $category = array_key_exists('audit_category', $data)
            ? self::normalizeCategory((string)$data['audit_category'])
            : (isset($row['audit_category']) && $row['audit_category'] !== '' ? (string)$row['audit_category'] : null);
        $type = array_key_exists('audit_type', $data) ? trim((string)$data['audit_type']) : (string)$row['audit_type'];
        if ($type === '') {
            $type = 'INTERNAL';
        }
        $entity = array_key_exists('audit_entity', $data) ? trim((string)$data['audit_entity']) : (string)($row['audit_entity'] ?? '');
        $external = array_key_exists('external_ref', $data) ? trim((string)$data['external_ref']) : (string)($row['external_ref'] ?? '');
        $status = array_key_exists('status', $data) ? self::normalizeStatus((string)$data['status']) : (string)$row['status'];
        $subject = array_key_exists('subject', $data) ? trim((string)$data['subject']) : (string)($row['subject'] ?? '');
        $start = array_key_exists('start_date', $data) ? trim((string)$data['start_date']) : (string)($row['start_date'] ?? '');
        $end = array_key_exists('end_date', $data) ? trim((string)$data['end_date']) : (string)($row['end_date'] ?? '');

        $st = $pdo->prepare(
            'UPDATE ipca_compliance_audits SET
                title = ?, authority = ?, audit_category = ?, audit_type = ?, audit_entity = ?,
                external_ref = ?, status = ?, subject = ?, start_date = ?, end_date = ?,
                updated_by = ?
             WHERE id = ?'
        );
        $st->execute(array(
            substr($title, 0, 255),
            $authority,
            $category,
            substr($type, 0, 64),
            $entity !== '' ? substr($entity, 0, 128) : null,
            $external !== '' ? substr($external, 0, 128) : null,
            $status,
            $subject !== '' ? $subject : null,
            $start !== '' ? substr($start, 0, 10) : null,
            $end !== '' ? substr($end, 0, 10) : null,
            $userId > 0 ? $userId : null,
            $id,
        ));
    }

    public static function close(PDO $pdo, int $id, int $userId, ?string $closedDate): void
    {
        $row = self::getById($pdo, $id);
        if ($row === null) {
            throw new RuntimeException('Audit not found.');
        }
        if (!empty($row['locked_at'])) {
            return;
        }
        $openFindings = self::openFindingCount($pdo, $id);
        if ($openFindings > 0) {
            throw new RuntimeException('Audit cannot be closed while ' . $openFindings . ' finding(s) remain open.');
        }
        $cd = $closedDate !== null && trim($closedDate) !== '' ? substr(trim($closedDate), 0, 10) : date('Y-m-d');
        $now = date('Y-m-d H:i:s');
        $pdo->prepare(
            'UPDATE ipca_compliance_audits SET
                status = \'CLOSED\',
                closed_date = ?,
                locked_at = ?, locked_by = ?,
                updated_by = ?
             WHERE id = ?'
        )->execute(array($cd, $now, $userId > 0 ? $userId : null, $userId > 0 ? $userId : null, $id));
        ComplianceApprovalEngine::record($pdo, array(
            'object_type' => 'audit',
            'object_id' => $id,
            'approval_type' => 'closure',
            'decision' => 'approved',
            'reviewed_by' => $userId,
            'notes' => 'Audit closure passed deterministic all-findings-closed check.',
        ));
    }

    private static function openFindingCount(PDO $pdo, int $auditId): int
    {
        $st = $pdo->prepare(
            "SELECT COUNT(*)
               FROM ipca_compliance_findings
              WHERE audit_id = ?
                AND UPPER(COALESCE(status,'')) NOT IN ('CLOSED','CANCELLED')"
        );
        $st->execute(array($auditId));
        return (int)$st->fetchColumn();
    }

    public static function authorities(): array
    {
        return self::AUTHORITIES;
    }

    public static function categories(): array
    {
        return self::CATEGORIES;
    }

    public static function statuses(): array
    {
        return self::STATUSES;
    }
}
