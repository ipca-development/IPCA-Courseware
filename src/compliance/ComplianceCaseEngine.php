<?php
declare(strict_types=1);

require_once __DIR__ . '/ComplianceAutomationDispatch.php';

/**
 * CRUD for ipca_compliance_cases (envelopes for audits, MoC cases, NCRs, etc.).
 *
 * MoC cases get their own UI path: see public/admin/compliance/moc.php.
 */
final class ComplianceCaseEngine
{
    /** @var list<string> */
    private const CASE_TYPES = array(
        'AUDIT', 'NCR', 'INVESTIGATION', 'MANAGEMENT_OF_CHANGE',
        'INCIDENT', 'INSPECTION', 'OTHER',
    );

    /** @var list<string> */
    private const STATUSES = array(
        'OPEN', 'IN_PROGRESS', 'WAITING_AUTHORITY', 'CLOSED', 'CANCELLED',
    );

    public static function normalizeType(string $v): string
    {
        $u = strtoupper(trim($v));

        return in_array($u, self::CASE_TYPES, true) ? $u : 'OTHER';
    }

    public static function normalizeStatus(string $v): string
    {
        $u = strtoupper(trim($v));

        return in_array($u, self::STATUSES, true) ? $u : 'OPEN';
    }

    public static function generateCaseCode(PDO $pdo, string $typePrefix = 'CMS'): string
    {
        $year = (int)date('Y');
        $prefix = $typePrefix . '-' . $year . '-';
        $st = $pdo->prepare('SELECT case_code FROM ipca_compliance_cases WHERE case_code LIKE ? ORDER BY id DESC LIMIT 80');
        $st->execute(array($prefix . '%'));
        $max = 0;
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $code = (string)($row['case_code'] ?? '');
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
        $st = $pdo->prepare('SELECT * FROM ipca_compliance_cases WHERE id = ? LIMIT 1');
        $st->execute(array($id));
        $row = $st->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public static function listByType(PDO $pdo, string $type, int $limit = 100): array
    {
        $t = self::normalizeType($type);
        $limit = max(1, min(500, $limit));
        $st = $pdo->prepare(
            'SELECT * FROM ipca_compliance_cases
              WHERE case_type = ?
              ORDER BY updated_at DESC, id DESC
              LIMIT ' . (int)$limit
        );
        $st->execute(array($t));
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : array();
    }

    /**
     * @param array{
     *   case_code?:string,title:string,case_type?:string,status?:string,
     *   severity?:string,authority?:string,summary?:string,
     *   opened_at?:string,due_at?:string,owner_user_id?:int|null
     * } $data
     */
    public static function create(PDO $pdo, array $data, int $userId): int
    {
        $title = trim((string)($data['title'] ?? ''));
        if ($title === '') {
            throw new InvalidArgumentException('Case title is required.');
        }
        $type = self::normalizeType((string)($data['case_type'] ?? 'OTHER'));

        $prefixMap = array(
            'AUDIT' => 'AUD',
            'NCR' => 'NCR',
            'INVESTIGATION' => 'INV',
            'MANAGEMENT_OF_CHANGE' => 'MOC',
            'INCIDENT' => 'INC',
            'INSPECTION' => 'INS',
            'OTHER' => 'CMS',
        );
        $code = trim((string)($data['case_code'] ?? ''));
        if ($code === '') {
            $code = self::generateCaseCode($pdo, $prefixMap[$type] ?? 'CMS');
        }
        $status = self::normalizeStatus((string)($data['status'] ?? 'OPEN'));
        $sev = isset($data['severity']) ? strtoupper(trim((string)$data['severity'])) : '';
        if (!in_array($sev, array('LOW', 'MEDIUM', 'HIGH', 'CRITICAL'), true)) {
            $sev = null;
        }
        $auth = isset($data['authority']) ? trim((string)$data['authority']) : '';
        $sum = isset($data['summary']) ? trim((string)$data['summary']) : '';
        $opened = isset($data['opened_at']) ? trim((string)$data['opened_at']) : '';
        $opened = $opened !== '' ? $opened : date('Y-m-d H:i:s');
        $due = isset($data['due_at']) ? trim((string)$data['due_at']) : '';
        $owner = isset($data['owner_user_id']) && (int)$data['owner_user_id'] > 0 ? (int)$data['owner_user_id'] : null;

        $ins = $pdo->prepare(
            'INSERT INTO ipca_compliance_cases (
                case_code, title, case_type, status, severity, authority, summary,
                opened_at, due_at, owner_user_id, created_by, updated_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $ins->execute(array(
            substr($code, 0, 64),
            substr($title, 0, 255),
            $type,
            $status,
            $sev,
            $auth !== '' ? substr($auth, 0, 32) : null,
            $sum !== '' ? $sum : null,
            $opened,
            $due !== '' ? $due : null,
            $owner,
            $userId > 0 ? $userId : null,
            $userId > 0 ? $userId : null,
        ));
        $newId = (int)$pdo->lastInsertId();

        ComplianceAutomationDispatch::fire($pdo, 'compliance.case.opened', array(
            'case_id' => $newId,
            'case_code' => substr($code, 0, 64),
            'title' => substr($title, 0, 255),
            'case_type' => $type,
            'status' => $status,
            'severity' => $sev,
            'authority' => $auth !== '' ? substr($auth, 0, 32) : null,
            'created_by_user_id' => $userId,
        ));

        return $newId;
    }

    public static function updateStatus(PDO $pdo, int $id, string $status, int $userId): void
    {
        $row = self::getById($pdo, $id);
        if ($row === null) {
            throw new RuntimeException('Case not found.');
        }
        if (!empty($row['locked_at'])) {
            throw new RuntimeException('Case is locked.');
        }
        $s = self::normalizeStatus($status);
        $closedAt = null;
        $lockedAt = null;
        $lockedBy = null;
        if ($s === 'CLOSED') {
            $closedAt = date('Y-m-d H:i:s');
            $lockedAt = $closedAt;
            $lockedBy = $userId > 0 ? $userId : null;
        }
        $pdo->prepare(
            'UPDATE ipca_compliance_cases SET
                status = ?,
                closed_at = COALESCE(?, closed_at),
                locked_at = COALESCE(?, locked_at),
                locked_by = COALESCE(?, locked_by),
                updated_by = ?
             WHERE id = ?'
        )->execute(array(
            $s,
            $closedAt,
            $lockedAt,
            $lockedBy,
            $userId > 0 ? $userId : null,
            $id,
        ));
    }
}
