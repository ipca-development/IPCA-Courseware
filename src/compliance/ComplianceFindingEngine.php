<?php
declare(strict_types=1);

require_once __DIR__ . '/ComplianceCaseEvents.php';
require_once __DIR__ . '/ComplianceApprovalEngine.php';
require_once __DIR__ . '/ComplianceDeadlineExtensionEngine.php';

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
        'AWAITING_RCA_CAP', 'AWAITING_REVIEW', 'REVISION_REQUIRED',
        'APPROVED_ACTIONS_PENDING', 'ACTIONS_IN_PROGRESS',
        'AWAITING_EFFECTIVENESS_REVIEW', 'ESCALATED',
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
        $v = strtoupper(str_replace('-', '_', trim($v)));
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
        $closureException = self::closureExceptionFromData($pdo, $id, $data);
        if ($status === 'CLOSED') {
            $standardClosure = self::closureReadiness($pdo, $id);
            $closure = self::closureReadiness($pdo, $id, array(
                'allow_late_deadline_exception' => $closureException['has_evidence'],
            ));
            if (!$closure['ready']) {
                throw new RuntimeException('Finding cannot be closed yet: ' . implode(' ', $closure['reasons']));
            }
            if (!$standardClosure['ready'] && $closureException['has_late_deadline_exception'] && !$closureException['has_evidence']) {
                throw new RuntimeException('Finding closure after a CAP deadline requires an evidence note or linked finding document.');
            }
            if ($closedDate === '' || $closedDate === '0000-00-00') {
                $closedDate = date('Y-m-d');
            }
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

        if ($status === 'CLOSED') {
            $approvalNotes = 'Finding closure passed deterministic RCA/CAP governance checks.';
            if ($closureException['has_late_deadline_exception']) {
                $approvalNotes .= ' Late CAP deadline exception accepted based on documented evidence.';
                if ($closureException['summary'] !== '') {
                    $approvalNotes .= ' Evidence: ' . $closureException['summary'];
                }
            }
            ComplianceApprovalEngine::record($pdo, array(
                'object_type' => 'finding',
                'object_id' => $id,
                'approval_type' => 'closure',
                'decision' => 'approved',
                'reviewed_by' => $userId,
                'notes' => $approvalNotes,
            ));
        }
    }

    /**
     * @return array{ready:bool,reasons:list<string>}
     */
    public static function closureReadiness(PDO $pdo, int $findingId, array $options = array()): array
    {
        $allowLateDeadlineException = !empty($options['allow_late_deadline_exception']);
        $caps = self::approvedCorrectiveActions($pdo, $findingId);
        if ($caps === array()) {
            return array('ready' => false, 'reasons' => array('No approved corrective actions are recorded.'));
        }
        $reasons = array();
        foreach ($caps as $cap) {
            $code = (string)($cap['action_code'] ?? ('CAP #' . (int)$cap['id']));
            $status = strtoupper((string)($cap['status'] ?? ''));
            if (!in_array($status, array('EXECUTED', 'COMPLETED', 'VERIFIED', 'CLOSED'), true)) {
                $reasons[] = $code . ' has not been executed.';
            } elseif (!self::capHasClosureEvidence($pdo, (int)$cap['id'])) {
                $reasons[] = $code . ' has no corrective-action closure evidence note or document.';
            }

            $deadline = ComplianceDeadlineExtensionEngine::effectiveCorrectiveActionDeadline(
                $pdo,
                (int)$cap['id'],
                isset($cap['due_date']) ? (string)$cap['due_date'] : null
            );
            $executedAt = self::capExecutionDate($cap);
            if ($deadline !== null) {
                if ($executedAt === null) {
                    $reasons[] = $code . ' has no execution/completion date for deadline verification.';
                } elseif ($executedAt > $deadline) {
                    if (!$allowLateDeadlineException) {
                        $reasons[] = $code . ' was executed after its approved deadline.';
                    }
                }
            }

            if (!self::capEffectivenessPositive($pdo, (int)$cap['id'], $status)) {
                $reasons[] = $code . ' does not have a positive effectiveness verification.';
            }
        }

        return array('ready' => $reasons === array(), 'reasons' => $reasons);
    }

    private static function capHasClosureEvidence(PDO $pdo, int $capId): bool
    {
        try {
            if (!self::ensureCapEvidenceTable($pdo)) {
                return false;
            }
            $st = $pdo->prepare(
                'SELECT COUNT(*)
                   FROM ipca_compliance_corrective_action_evidence
                  WHERE corrective_action_id = ?'
            );
            $st->execute(array($capId));
            return (int)$st->fetchColumn() > 0;
        } catch (Throwable) {
            return false;
        }
    }

    private static function ensureCapEvidenceTable(PDO $pdo): bool
    {
        try {
            $pdo->query('SELECT id FROM ipca_compliance_corrective_action_evidence LIMIT 0');
            return true;
        } catch (Throwable) {
            // Create the phase-1 evidence table if the migration was not applied yet.
        }
        $baseSql = "CREATE TABLE IF NOT EXISTS ipca_compliance_corrective_action_evidence (
                  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                  corrective_action_id BIGINT UNSIGNED NOT NULL,
                  evidence_kind VARCHAR(32) NOT NULL DEFAULT 'DOCUMENT',
                  storage_relpath VARCHAR(1024) NULL,
                  external_url VARCHAR(2048) NULL,
                  title VARCHAR(255) NULL,
                  description TEXT NULL,
                  mime_type VARCHAR(128) NULL,
                  file_size BIGINT UNSIGNED NULL,
                  sha256 CHAR(64) NULL,
                  uploaded_by INT UNSIGNED NULL,
                  uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  KEY idx_ipcacapev_action_kind (corrective_action_id, evidence_kind),
                  KEY idx_ipcacapev_sha (sha256)";
        try {
            $pdo->exec(
                $baseSql . ",
                  CONSTRAINT fk_ipcacapev_action_from_finding FOREIGN KEY (corrective_action_id)
                    REFERENCES ipca_compliance_corrective_actions (id) ON DELETE CASCADE ON UPDATE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        } catch (Throwable) {
            try {
                $pdo->exec($baseSql . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
            } catch (Throwable) {
                return false;
            }
        }
        try {
            $pdo->query('SELECT id FROM ipca_compliance_corrective_action_evidence LIMIT 0');
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param array<string,mixed> $data
     * @return array{has_late_deadline_exception:bool,has_evidence:bool,summary:string}
     */
    private static function closureExceptionFromData(PDO $pdo, int $findingId, array $data): array
    {
        $lateReasonExists = false;
        foreach (self::closureReadiness($pdo, $findingId)['reasons'] as $reason) {
            if (stripos($reason, 'approved deadline') !== false) {
                $lateReasonExists = true;
                break;
            }
        }

        $note = trim((string)($data['closure_late_deadline_evidence_note'] ?? ''));
        $docId = (int)($data['closure_late_deadline_document_id'] ?? 0);
        $parts = array();
        if ($note !== '') {
            $parts[] = 'Note: ' . $note;
        }
        if ($docId > 0) {
            $parts[] = 'Document: ' . self::findingDocumentSummary($pdo, $findingId, $docId);
        }

        return array(
            'has_late_deadline_exception' => $lateReasonExists,
            'has_evidence' => $parts !== array(),
            'summary' => implode(' | ', $parts),
        );
    }

    private static function findingDocumentSummary(PDO $pdo, int $findingId, int $documentId): string
    {
        try {
            $st = $pdo->prepare(
                'SELECT id, original_name, doc_kind
                   FROM ipca_compliance_finding_documents
                  WHERE id = ? AND finding_id = ?
                  LIMIT 1'
            );
            $st->execute(array($documentId, $findingId));
            $doc = $st->fetch(PDO::FETCH_ASSOC);
            if (!is_array($doc)) {
                throw new RuntimeException('Selected closure evidence document was not found for this finding.');
            }
            $name = trim((string)($doc['original_name'] ?? ''));
            $kind = trim((string)($doc['doc_kind'] ?? ''));
            return '#' . $documentId . ($name !== '' ? ' ' . $name : '') . ($kind !== '' ? ' (' . $kind . ')' : '');
        } catch (RuntimeException $e) {
            throw $e;
        } catch (Throwable) {
            throw new RuntimeException('Selected closure evidence document could not be verified.');
        }
    }

    /**
     * @return list<array<string,mixed>>
     */
    private static function approvedCorrectiveActions(PDO $pdo, int $findingId): array
    {
        $st = $pdo->prepare(
            "SELECT *
               FROM ipca_compliance_corrective_actions
              WHERE finding_id = ?
                AND COALESCE(status,'') NOT IN ('CANCELLED','cancelled','DRAFT','draft','PROPOSED','proposed')
              ORDER BY id ASC"
        );
        $st->execute(array($findingId));
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : array();
    }

    private static function capExecutionDate(array $cap): ?string
    {
        foreach (array('completed_at', 'verified_at', 'updated_at') as $key) {
            $value = trim((string)($cap[$key] ?? ''));
            if ($value !== '' && $value !== '0000-00-00' && $value !== '0000-00-00 00:00:00') {
                return substr($value, 0, 10);
            }
        }
        return null;
    }

    private static function capEffectivenessPositive(PDO $pdo, int $capId, string $capStatus): bool
    {
        if (in_array($capStatus, array('VERIFIED', 'CLOSED'), true)) {
            return true;
        }
        try {
            $st = $pdo->prepare(
                'SELECT effectiveness
                   FROM ipca_compliance_effectiveness_reviews
                  WHERE corrective_action_id = ?
                  ORDER BY reviewed_at DESC, id DESC
                  LIMIT 1'
            );
            $st->execute(array($capId));
            $eff = strtoupper((string)$st->fetchColumn());
            return $eff === 'EFFECTIVE';
        } catch (Throwable) {
            return false;
        }
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
