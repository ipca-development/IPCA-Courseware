<?php
declare(strict_types=1);

require_once __DIR__ . '/ComplianceCaseEvents.php';

final class ComplianceFindingEngine
{
    /** @var list<string> */
    private const CLASSIFICATIONS = array(
        'LEVEL_1', 'LEVEL_2', 'LEVEL_3', 'OBSERVATION', 'INFORMATION',
    );

    /** @var list<string> */
    private const SEVERITIES = array('LOW', 'MEDIUM', 'HIGH', 'CRITICAL');

    /** @var list<string> */
    private const STATUSES = array(
        'OPEN', 'IN_PROGRESS', 'WAITING_AUTHORITY', 'CLOSED', 'CANCELLED',
    );

    public static function normalizeClassification(string $v): string
    {
        $v = strtoupper(trim($v));
        return in_array($v, self::CLASSIFICATIONS, true) ? $v : 'LEVEL_3';
    }

    public static function normalizeSeverity(string $v): string
    {
        $v = strtoupper(trim($v));
        return in_array($v, self::SEVERITIES, true) ? $v : 'MEDIUM';
    }

    public static function normalizeStatus(string $v): string
    {
        $v = strtoupper(trim($v));
        return in_array($v, self::STATUSES, true) ? $v : 'OPEN';
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function getById(PDO $pdo, int $id): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT * FROM ipca_compliance_findings WHERE id = ? LIMIT 1'
        );
        $stmt->execute(array($id));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public static function listRecent(PDO $pdo, int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        $sql = 'SELECT * FROM ipca_compliance_findings
                ORDER BY updated_at DESC, id DESC
                LIMIT ' . (int)$limit;
        $stmt = $pdo->query($sql);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : array();
    }

    /**
     * @return list<array<string,mixed>>
     */
    public static function listAuditsForSelect(PDO $pdo, int $limit = 200): array
    {
        $limit = max(1, min(500, $limit));
        $sql = 'SELECT id, audit_code, title, status
                FROM ipca_compliance_audits
                ORDER BY updated_at DESC, id DESC
                LIMIT ' . (int)$limit;
        $stmt = $pdo->query($sql);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : array();
    }

    public static function assertNotLocked(array $findingRow): void
    {
        if (!empty($findingRow['locked_at'])) {
            throw new RuntimeException('This finding is locked and cannot be modified.');
        }
    }

    public static function generateFindingCode(PDO $pdo): string
    {
        $year = (int)date('Y');
        $prefix = 'NCR-' . $year . '-';

        $stmt = $pdo->prepare(
            'SELECT finding_code FROM ipca_compliance_findings
             WHERE finding_code LIKE ?
             ORDER BY finding_code DESC
             LIMIT 100'
        );
        $stmt->execute(array($prefix . '%'));
        $max = 0;
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $code = (string)($row['finding_code'] ?? '');
            if (!str_starts_with($code, $prefix)) {
                continue;
            }
            $suffix = substr($code, strlen($prefix));
            $n = (int)$suffix;
            if ($n > $max) {
                $max = $n;
            }
        }

        $next = $max + 1;
        return $prefix . str_pad((string)$next, 5, '0', STR_PAD_LEFT);
    }

    public static function validateAuditExists(PDO $pdo, ?int $auditId): void
    {
        if ($auditId === null || $auditId <= 0) {
            return;
        }
        $s = $pdo->prepare('SELECT id FROM ipca_compliance_audits WHERE id = ? LIMIT 1');
        $s->execute(array($auditId));
        if (!$s->fetchColumn()) {
            throw new RuntimeException('Selected audit does not exist.');
        }
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function create(PDO $pdo, array $data, int $userId): int
    {
        $title = trim((string)($data['title'] ?? ''));
        $description = trim((string)($data['description'] ?? ''));
        if ($title === '' || $description === '') {
            throw new RuntimeException('Title and description are required.');
        }

        $classification = self::normalizeClassification((string)($data['classification'] ?? 'LEVEL_3'));
        $severity = self::normalizeSeverity((string)($data['severity'] ?? 'MEDIUM'));
        $status = self::normalizeStatus((string)($data['status'] ?? 'OPEN'));

        $auditId = isset($data['audit_id']) ? (int)$data['audit_id'] : null;
        if ($auditId !== null && $auditId <= 0) {
            $auditId = null;
        }
        self::validateAuditExists($pdo, $auditId);

        $caseId = isset($data['case_id']) ? (int)$data['case_id'] : null;
        if ($caseId !== null && $caseId <= 0) {
            $caseId = null;
        }

        $raised = trim((string)($data['raised_date'] ?? ''));
        if ($raised === '') {
            $raised = date('Y-m-d');
        }

        $targetDate = isset($data['target_date']) ? trim((string)$data['target_date']) : '';
        $targetDate = $targetDate !== '' ? $targetDate : null;

        $pdo->beginTransaction();
        try {
            $code = self::generateFindingCode($pdo);
            $reference = isset($data['reference']) ? trim((string)$data['reference']) : '';
            $reference = $reference !== '' ? $reference : null;

            $regulationSummary = isset($data['regulation_summary'])
                ? trim((string)$data['regulation_summary']) : '';
            $regulationSummary = $regulationSummary !== '' ? $regulationSummary : null;

            $notes = isset($data['notes']) ? trim((string)$data['notes']) : '';
            $notes = $notes !== '' ? $notes : null;

            $domainCode = isset($data['domain_code']) ? trim((string)$data['domain_code']) : '';
            $domainCode = $domainCode !== '' ? $domainCode : null;

            $reqKey = isset($data['requirement_key']) ? trim((string)$data['requirement_key']) : '';
            $reqKey = $reqKey !== '' ? $reqKey : null;

            $stmt = $pdo->prepare(
                'INSERT INTO ipca_compliance_findings (
                    audit_id, case_id, finding_code, reference, title, description,
                    classification, severity, status, domain_code, requirement_key,
                    regulation_summary, raised_date, target_date,
                    notes, created_by, updated_by
                ) VALUES (
                    ?, ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, ?,
                    ?, ?, ?,
                    ?, ?, ?
                )'
            );

            $stmt->execute(array(
                $auditId,
                $caseId,
                $code,
                $reference,
                $title,
                $description,
                $classification,
                $severity,
                $status,
                $domainCode,
                $reqKey,
                $regulationSummary,
                $raised,
                $targetDate,
                $notes,
                $userId,
                $userId,
            ));

            $id = (int)$pdo->lastInsertId();

            compliance_log_case_event(
                $pdo,
                $caseId,
                'finding',
                $id,
                'created',
                $userId,
                'Finding created: ' . $code,
                null,
                array('finding_code' => $code, 'title' => $title, 'status' => $status),
                null
            );

            $pdo->commit();

            self::dispatchFindingCreatedEvent(
                $pdo,
                $id,
                $code,
                $title,
                $classification,
                $severity,
                $status,
                $userId
            );

            return $id;
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    private static function dispatchFindingCreatedEvent(
        PDO $pdo,
        int $findingId,
        string $findingCode,
        string $title,
        string $classification,
        string $severity,
        string $status,
        int $userId
    ): void {
        $path = __DIR__ . '/../automation_runtime.php';
        if (!is_file($path)) {
            return;
        }
        require_once $path;
        try {
            $rt = new AutomationRuntime();
            $rt->dispatchEvent($pdo, 'compliance.finding.created', array(
                'finding_id' => $findingId,
                'finding_code' => $findingCode,
                'title' => $title,
                'classification' => $classification,
                'severity' => $severity,
                'status' => $status,
                'created_by_user_id' => $userId,
            ));
        } catch (Throwable $e) {
            // Non-fatal — finding is already committed.
        }
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function update(PDO $pdo, int $id, array $data, int $userId): void
    {
        $row = self::getById($pdo, $id);
        if ($row === null) {
            throw new RuntimeException('Finding not found.');
        }
        self::assertNotLocked($row);

        $before = array(
            'title' => $row['title'],
            'status' => $row['status'],
            'classification' => $row['classification'],
            'severity' => $row['severity'],
        );

        $title = array_key_exists('title', $data)
            ? trim((string)$data['title']) : (string)$row['title'];
        $description = array_key_exists('description', $data)
            ? trim((string)$data['description']) : (string)$row['description'];

        if ($title === '' || $description === '') {
            throw new RuntimeException('Title and description are required.');
        }

        $classification = array_key_exists('classification', $data)
            ? self::normalizeClassification((string)$data['classification'])
            : (string)$row['classification'];

        $severity = array_key_exists('severity', $data)
            ? self::normalizeSeverity((string)$data['severity'])
            : (string)$row['severity'];

        $status = array_key_exists('status', $data)
            ? self::normalizeStatus((string)$data['status'])
            : (string)$row['status'];

        $reference = array_key_exists('reference', $data)
            ? trim((string)$data['reference']) : (string)($row['reference'] ?? '');
        $reference = $reference !== '' ? $reference : null;

        $raised = array_key_exists('raised_date', $data)
            ? trim((string)$data['raised_date']) : substr((string)$row['raised_date'], 0, 10);
        if ($raised === '') {
            $raised = date('Y-m-d');
        }

        $targetDate = array_key_exists('target_date', $data)
            ? trim((string)$data['target_date']) : (string)($row['target_date'] ?? '');
        $targetDate = $targetDate !== '' ? $targetDate : null;

        $regulationSummary = array_key_exists('regulation_summary', $data)
            ? trim((string)$data['regulation_summary']) : (string)($row['regulation_summary'] ?? '');
        $regulationSummary = $regulationSummary !== '' ? $regulationSummary : null;

        $notes = array_key_exists('notes', $data)
            ? trim((string)$data['notes']) : (string)($row['notes'] ?? '');
        $notes = $notes !== '' ? $notes : null;

        $domainCode = array_key_exists('domain_code', $data)
            ? trim((string)$data['domain_code']) : (string)($row['domain_code'] ?? '');
        $domainCode = $domainCode !== '' ? $domainCode : null;

        $reqKey = array_key_exists('requirement_key', $data)
            ? trim((string)$data['requirement_key']) : (string)($row['requirement_key'] ?? '');
        $reqKey = $reqKey !== '' ? $reqKey : null;

        $auditId = array_key_exists('audit_id', $data)
            ? (int)$data['audit_id'] : (int)($row['audit_id'] ?? 0);
        if ($auditId <= 0) {
            $auditId = null;
        }
        self::validateAuditExists($pdo, $auditId);

        $closedDate = (string)($row['closed_date'] ?? '');
        if ($status === 'CLOSED' && ($closedDate === '' || $closedDate === '0000-00-00')) {
            $closedDate = date('Y-m-d');
        } elseif ($status !== 'CLOSED') {
            $closedDate = null;
        }

        $caseId = isset($row['case_id']) ? (int)$row['case_id'] : null;
        if ($caseId !== null && $caseId <= 0) {
            $caseId = null;
        }

        $stmt = $pdo->prepare(
            'UPDATE ipca_compliance_findings SET
                audit_id = ?,
                reference = ?,
                title = ?,
                description = ?,
                classification = ?,
                severity = ?,
                status = ?,
                domain_code = ?,
                requirement_key = ?,
                regulation_summary = ?,
                raised_date = ?,
                target_date = ?,
                closed_date = ?,
                notes = ?,
                updated_by = ?,
                updated_at = NOW()
             WHERE id = ? LIMIT 1'
        );

        $stmt->execute(array(
            $auditId,
            $reference,
            $title,
            $description,
            $classification,
            $severity,
            $status,
            $domainCode,
            $reqKey,
            $regulationSummary,
            $raised,
            $targetDate,
            $closedDate,
            $notes,
            $userId,
            $id,
        ));

        $after = array(
            'title' => $title,
            'status' => $status,
            'classification' => $classification,
            'severity' => $severity,
        );

        compliance_log_case_event(
            $pdo,
            $caseId,
            'finding',
            $id,
            'updated',
            $userId,
            'Finding updated',
            $before,
            $after,
            null
        );
    }

    /**
     * @return array<string,mixed>
     */
    public static function buildFindingContextArray(array $findingRow): array
    {
        return array(
            'reference' => $findingRow['reference'] ?? '',
            'title' => $findingRow['title'] ?? '',
            'classification' => $findingRow['classification'] ?? '',
            'severity' => $findingRow['severity'] ?? '',
            'regulation_ref' => $findingRow['regulation_summary'] ?? '',
            'description' => $findingRow['description'] ?? '',
            'manual_refs' => array(),
        );
    }
}
