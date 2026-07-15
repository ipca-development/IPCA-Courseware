<?php
declare(strict_types=1);

require_once __DIR__ . '/../openai.php';
require_once __DIR__ . '/ComplianceAiRunLogger.php';
require_once __DIR__ . '/ComplianceCaseEvents.php';
require_once __DIR__ . '/ComplianceFindingEngine.php';
require_once __DIR__ . '/ComplianceRcaCapEngine.php';
require_once __DIR__ . '/ComplianceRcaCapSubmissionEngine.php';
require_once __DIR__ . '/ComplianceDeadlineExtensionEngine.php';

/**
 * Corrective action plan items — CRUD, optional AI option suggestions (logged).
 */
final class ComplianceCapEngine
{
    /** @var list<string> */
    private const ACTION_TYPES = array(
        'CORRECTIVE', 'PREVENTIVE', 'CONTAINMENT', 'IMMEDIATE',
    );

    /** @var list<string> */
    private const STATUSES = array(
        'PROPOSED', 'APPROVED', 'IN_PROGRESS', 'COMPLETED', 'VERIFIED', 'INEFFECTIVE', 'CANCELLED',
        'DRAFT', 'AWAITING_APPROVAL', 'AWAITING_EVIDENCE', 'EXECUTED', 'EFFECTIVENESS_FAILED',
        'CLOSED', 'OVERDUE', 'EXTENDED',
    );

    /** @var list<string> */
    private const EFFORTS = array('XS', 'S', 'M', 'L', 'XL');

    public static function normalizeActionType(string $v): string
    {
        $v = strtoupper(trim($v));
        return in_array($v, self::ACTION_TYPES, true) ? $v : 'CORRECTIVE';
    }

    public static function normalizeStatus(string $v): string
    {
        $v = strtoupper(str_replace('-', '_', trim($v)));
        return in_array($v, self::STATUSES, true) ? $v : 'PROPOSED';
    }

    public static function normalizeEffort(?string $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $v = strtoupper(trim($v));
        return in_array($v, self::EFFORTS, true) ? $v : null;
    }

    public static function assertNotLocked(array $capRow): void
    {
        if (!empty($capRow['locked_at'])) {
            throw new RuntimeException('This corrective action is locked and cannot be modified.');
        }
    }

    public static function generateActionCode(PDO $pdo): string
    {
        $year = (int)date('Y');
        $prefix = 'CAP-' . $year . '-';

        $stmt = $pdo->prepare(
            'SELECT action_code FROM ipca_compliance_corrective_actions
             WHERE action_code LIKE ?
             ORDER BY action_code DESC
             LIMIT 100'
        );
        $stmt->execute(array($prefix . '%'));
        $max = 0;
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $code = (string)($row['action_code'] ?? '');
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

    /**
     * @return array<string,mixed>|null
     */
    public static function getById(PDO $pdo, int $id): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT c.*, f.finding_code, f.reference AS finding_reference, f.title AS finding_title, f.status AS finding_status
             FROM ipca_compliance_corrective_actions c
             INNER JOIN ipca_compliance_findings f ON f.id = c.finding_id
             WHERE c.id = ? LIMIT 1'
        );
        $stmt->execute(array($id));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public static function listForFinding(PDO $pdo, int $findingId): array
    {
        $stmt = $pdo->prepare(
            'SELECT * FROM ipca_compliance_corrective_actions
             WHERE finding_id = ?
             ORDER BY id ASC'
        );
        $stmt->execute(array($findingId));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : array();
    }

    /**
     * @return list<array<string,mixed>>
     */
    public static function listRecent(PDO $pdo, ?string $status, ?int $findingId, int $limit = 150): array
    {
        $limit = max(1, min(500, $limit));
        $where = array('1=1');
        $params = array();

        if ($status !== null && $status !== '') {
            $where[] = 'c.status = ?';
            $params[] = self::normalizeStatus($status);
        }
        if ($findingId !== null && $findingId > 0) {
            $where[] = 'c.finding_id = ?';
            $params[] = $findingId;
        }

        $sql = 'SELECT c.*, f.finding_code, f.reference AS finding_reference, f.title AS finding_title, f.audit_id
                FROM ipca_compliance_corrective_actions c
                INNER JOIN ipca_compliance_findings f ON f.id = c.finding_id
                WHERE ' . implode(' AND ', $where) . '
                ORDER BY c.updated_at DESC, c.id DESC
                LIMIT ' . (int)$limit;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : array();
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function create(PDO $pdo, array $data, int $userId): int
    {
        $findingId = (int)($data['finding_id'] ?? 0);
        if ($findingId <= 0) {
            throw new RuntimeException('Finding is required.');
        }

        $finding = ComplianceFindingEngine::getById($pdo, $findingId);
        if ($finding === null) {
            throw new RuntimeException('Finding not found.');
        }
        ComplianceFindingEngine::assertNotLocked($finding);

        $title = trim((string)($data['title'] ?? ''));
        $description = trim((string)($data['description'] ?? ''));
        if ($title === '' || $description === '') {
            throw new RuntimeException('Title and description are required.');
        }

        $actionType = self::normalizeActionType((string)($data['action_type'] ?? 'CORRECTIVE'));
        $status = self::normalizeStatus((string)($data['status'] ?? 'PROPOSED'));
        $effort = isset($data['effort']) ? self::normalizeEffort(trim((string)$data['effort']) ?: null) : null;

        $caseId = isset($finding['case_id']) ? (int)$finding['case_id'] : null;
        if ($caseId !== null && $caseId <= 0) {
            $caseId = null;
        }

        $dueRaw = isset($data['due_date']) ? trim((string)$data['due_date']) : '';
        $dueDate = $dueRaw !== '' ? $dueRaw : null;

        $respName = isset($data['responsible_name']) ? trim((string)$data['responsible_name']) : '';
        $respName = $respName !== '' ? $respName : null;

        $aiAssisted = !empty($data['ai_assisted']);
        $aiRunId = isset($data['ai_run_id']) ? (int)$data['ai_run_id'] : null;
        if ($aiRunId !== null && $aiRunId <= 0) {
            $aiRunId = null;
        }
        $submissionId = isset($data['submission_id']) && (int)$data['submission_id'] > 0
            ? (int)$data['submission_id']
            : ComplianceRcaCapSubmissionEngine::ensureWorkingSubmission($pdo, $findingId, $userId);
        $hasSubmissionColumn = ComplianceRcaCapSubmissionEngine::capSubmissionColumnPresent($pdo);

        $ownsTx = !$pdo->inTransaction();
        if ($ownsTx) {
            $pdo->beginTransaction();
        }
        try {
            $code = self::generateActionCode($pdo);
            $columns = 'finding_id, case_id, action_code, action_type, title, description,
                    status, effort, responsible_name, due_date,
                    ai_assisted, ai_run_id, created_by, updated_by';
            $placeholders = '?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?';
            $params = array(
                $findingId,
                $caseId,
                $code,
                $actionType,
                $title,
                $description,
                $status,
                $effort,
                $respName,
                $dueDate,
                $aiAssisted ? 1 : 0,
                $aiRunId,
                $userId,
                $userId,
            );
            if ($hasSubmissionColumn) {
                $columns = 'submission_id, ' . $columns;
                $placeholders = '?, ' . $placeholders;
                array_unshift($params, $submissionId);
            }
            $stmt = $pdo->prepare(
                'INSERT INTO ipca_compliance_corrective_actions (' . $columns . ')
                 VALUES (' . $placeholders . ')'
            );
            $stmt->execute($params);

            $id = (int)$pdo->lastInsertId();

            compliance_log_case_event(
                $pdo,
                $caseId,
                'corrective_action',
                $id,
                'created',
                $userId,
                'Corrective action created: ' . $code,
                null,
                array('action_code' => $code, 'title' => $title, 'status' => $status),
                null
            );

            if ($ownsTx) {
                $pdo->commit();
                self::dispatchCapCreatedEvent(
                    $pdo,
                    $id,
                    $code,
                    $findingId,
                    (string)$finding['finding_code'],
                    $title,
                    $status,
                    $actionType,
                    $userId
                );
            }

            return $id;
        } catch (Throwable $e) {
            if ($ownsTx) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function update(PDO $pdo, int $id, array $data, int $userId): void
    {
        $row = self::getById($pdo, $id);
        if ($row === null) {
            throw new RuntimeException('Corrective action not found.');
        }
        self::assertNotLocked($row);

        $finding = ComplianceFindingEngine::getById($pdo, (int)$row['finding_id']);
        if ($finding === null) {
            throw new RuntimeException('Finding not found.');
        }
        ComplianceFindingEngine::assertNotLocked($finding);

        $title = array_key_exists('title', $data)
            ? trim((string)$data['title']) : (string)$row['title'];
        $description = array_key_exists('description', $data)
            ? trim((string)$data['description']) : (string)$row['description'];
        if ($title === '' || $description === '') {
            throw new RuntimeException('Title and description are required.');
        }

        $actionType = array_key_exists('action_type', $data)
            ? self::normalizeActionType((string)$data['action_type'])
            : (string)$row['action_type'];

        $status = array_key_exists('status', $data)
            ? self::normalizeStatus((string)$data['status'])
            : (string)$row['status'];
        $oldStatus = self::normalizeStatus((string)($row['status'] ?? ''));
        $closureEvidenceNote = trim((string)($data['closure_evidence_note'] ?? ''));
        $closureDate = array_key_exists('closure_date', $data)
            ? self::normalizeDate((string)$data['closure_date'])
            : self::existingDate((string)($row['completed_at'] ?? ''));
        if (self::isClosureStatus($status)) {
            if ($closureDate === null) {
                throw new RuntimeException('Corrective action closure requires a closure/execution date.');
            }
        }
        if (self::isClosureStatus($status) && (!self::isClosureStatus($oldStatus) || !self::hasClosureEvidence($pdo, $id))) {
            if ($closureEvidenceNote === '' && !self::hasClosureEvidence($pdo, $id)) {
                throw new RuntimeException('Corrective action closure requires an evidence note or existing corrective-action evidence document.');
            }
        }

        $effort = array_key_exists('effort', $data)
            ? self::normalizeEffort(trim((string)$data['effort']) ?: null) : self::normalizeEffort(
                isset($row['effort']) ? trim((string)$row['effort']) ?: null : null
            );

        $dueRaw = array_key_exists('due_date', $data) ? trim((string)$data['due_date']) : (string)($row['due_date'] ?? '');
        $dueDate = $dueRaw !== '' ? $dueRaw : null;

        $respName = array_key_exists('responsible_name', $data)
            ? trim((string)$data['responsible_name']) : (string)($row['responsible_name'] ?? '');
        $respName = $respName !== '' ? $respName : null;

        $caseId = isset($finding['case_id']) ? (int)$finding['case_id'] : null;
        if ($caseId !== null && $caseId <= 0) {
            $caseId = null;
        }

        $before = array(
            'title' => $row['title'],
            'status' => $row['status'],
            'action_type' => $row['action_type'],
        );

        $stmt = $pdo->prepare(
            'UPDATE ipca_compliance_corrective_actions SET
                action_type = ?,
                title = ?,
                description = ?,
                status = ?,
                effort = ?,
                responsible_name = ?,
                due_date = ?,
                completed_at = ?,
                updated_by = ?,
                updated_at = NOW()
             WHERE id = ? LIMIT 1'
        );
        $stmt->execute(array(
            $actionType,
            $title,
            $description,
            $status,
            $effort,
            $respName,
            $dueDate,
            $closureDate,
            $userId,
            $id,
        ));

        if (self::isClosureStatus($status) && $closureEvidenceNote !== '') {
            self::recordClosureEvidenceNote($pdo, $id, $closureEvidenceNote, $userId);
        }

        $oldDue = trim((string)($row['due_date'] ?? ''));
        if ($oldDue !== '' && $dueDate !== null && substr($oldDue, 0, 10) !== substr($dueDate, 0, 10)) {
            ComplianceDeadlineExtensionEngine::recordApprovedCorrectiveActionExtension(
                $pdo,
                $id,
                $oldDue,
                $dueDate,
                $userId,
                'Deadline updated from corrective-action workspace.'
            );
        }

        $after = array(
            'title' => $title,
            'status' => $status,
            'action_type' => $actionType,
        );

        compliance_log_case_event(
            $pdo,
            $caseId,
            'corrective_action',
            $id,
            'updated',
            $userId,
            'Corrective action updated',
            $before,
            $after,
            null
        );
    }

    public static function isClosureStatus(string $status): bool
    {
        return in_array(self::normalizeStatus($status), array('EXECUTED', 'COMPLETED', 'VERIFIED', 'CLOSED'), true);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public static function listEvidence(PDO $pdo, int $capId): array
    {
        if ($capId <= 0 || !self::ensureEvidenceTable($pdo)) {
            return array();
        }
        $st = $pdo->prepare(
            'SELECT *
               FROM ipca_compliance_corrective_action_evidence
              WHERE corrective_action_id = ?
              ORDER BY uploaded_at DESC, id DESC'
        );
        $st->execute(array($capId));
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : array();
    }

    /**
     * @param array{name?:string,tmp_name?:string,type?:string,size?:int,error?:int} $file
     * @param array<string,mixed> $data
     */
    public static function uploadEvidenceDocument(PDO $pdo, int $capId, array $file, array $data, int $userId): int
    {
        if (!self::ensureEvidenceTable($pdo)) {
            throw new RuntimeException('Corrective action evidence table is not installed.');
        }
        $cap = self::getById($pdo, $capId);
        if ($cap === null) {
            throw new RuntimeException('Corrective action not found.');
        }
        $stored = self::storeUploadedEvidencePdf($file, $capId);
        $title = trim((string)($data['title'] ?? ''));
        $description = trim((string)($data['description'] ?? ''));
        $st = $pdo->prepare(
            'INSERT INTO ipca_compliance_corrective_action_evidence
                (corrective_action_id, evidence_kind, storage_relpath, title, description, mime_type, file_size, sha256, uploaded_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $st->execute(array(
            $capId,
            'DOCUMENT',
            $stored['relpath'],
            $title !== '' ? $title : $stored['original_name'],
            $description !== '' ? $description : null,
            $stored['mime_type'],
            $stored['file_size'],
            $stored['sha256'],
            $userId > 0 ? $userId : null,
        ));
        return (int)$pdo->lastInsertId();
    }

    public static function attachFindingDocumentEvidence(PDO $pdo, int $capId, int $findingDocumentId, string $note, int $userId): int
    {
        if (!self::ensureEvidenceTable($pdo)) {
            throw new RuntimeException('Corrective action evidence table is not installed.');
        }
        $cap = self::getById($pdo, $capId);
        if ($cap === null) {
            throw new RuntimeException('Corrective action not found.');
        }
        $st = $pdo->prepare(
            'SELECT id, finding_id, doc_kind, original_name, notes
               FROM ipca_compliance_finding_documents
              WHERE id = ? AND finding_id = ?
              LIMIT 1'
        );
        $st->execute(array($findingDocumentId, (int)$cap['finding_id']));
        $doc = $st->fetch(PDO::FETCH_ASSOC);
        if (!is_array($doc)) {
            throw new RuntimeException('Selected finding document was not found for this corrective action finding.');
        }
        $title = 'Linked finding document: ' . trim((string)($doc['original_name'] ?? ('Document #' . $findingDocumentId)));
        $description = trim($note);
        if ($description === '') {
            $description = trim((string)($doc['notes'] ?? ''));
        }
        $ins = $pdo->prepare(
            'INSERT INTO ipca_compliance_corrective_action_evidence
                (corrective_action_id, evidence_kind, external_url, title, description, uploaded_by)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $ins->execute(array(
            $capId,
            'DOCUMENT',
            '/admin/compliance/document.php?scope=finding&id=' . $findingDocumentId,
            $title,
            $description !== '' ? $description : null,
            $userId > 0 ? $userId : null,
        ));
        return (int)$pdo->lastInsertId();
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function getEvidenceById(PDO $pdo, int $evidenceId): ?array
    {
        if ($evidenceId <= 0 || !self::ensureEvidenceTable($pdo)) {
            return null;
        }
        $st = $pdo->prepare('SELECT * FROM ipca_compliance_corrective_action_evidence WHERE id = ? LIMIT 1');
        $st->execute(array($evidenceId));
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public static function evidenceAbsolutePath(array $evidence): string
    {
        $rel = str_replace('\\', '/', trim((string)($evidence['storage_relpath'] ?? '')));
        if ($rel === '' || str_contains($rel, '..')) {
            throw new RuntimeException('Invalid evidence storage path.');
        }
        return self::projectRoot() . '/' . $rel;
    }

    private static function hasClosureEvidence(PDO $pdo, int $capId): bool
    {
        if (!self::ensureEvidenceTable($pdo)) {
            return false;
        }
        $st = $pdo->prepare(
            'SELECT COUNT(*)
               FROM ipca_compliance_corrective_action_evidence
              WHERE corrective_action_id = ?'
        );
        $st->execute(array($capId));
        return (int)$st->fetchColumn() > 0;
    }

    private static function recordClosureEvidenceNote(PDO $pdo, int $capId, string $note, int $userId): void
    {
        if (!self::ensureEvidenceTable($pdo)) {
            throw new RuntimeException('Corrective action evidence table is not installed.');
        }
        $st = $pdo->prepare(
            'INSERT INTO ipca_compliance_corrective_action_evidence
                (corrective_action_id, evidence_kind, title, description, uploaded_by)
             VALUES (?, ?, ?, ?, ?)'
        );
        $st->execute(array(
            $capId,
            'NOTE',
            'Closure evidence note',
            $note,
            $userId > 0 ? $userId : null,
        ));
    }

    private static function evidenceTablePresent(PDO $pdo): bool
    {
        try {
            $pdo->query('SELECT id FROM ipca_compliance_corrective_action_evidence LIMIT 0');
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private static function ensureEvidenceTable(PDO $pdo): bool
    {
        if (self::evidenceTablePresent($pdo)) {
            return true;
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
                  CONSTRAINT fk_ipcacapev_action FOREIGN KEY (corrective_action_id)
                    REFERENCES ipca_compliance_corrective_actions (id) ON DELETE CASCADE ON UPDATE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        } catch (Throwable) {
            try {
                // Some deployed databases reject runtime FK creation. Evidence still works
                // without the constraint because all writes validate the CAP first.
                $pdo->exec($baseSql . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
            } catch (Throwable) {
                return false;
            }
        }
        return self::evidenceTablePresent($pdo);
    }

    private static function normalizeDate(string $date): ?string
    {
        $date = substr(trim($date), 0, 10);
        if ($date === '') {
            return null;
        }
        $dt = DateTimeImmutable::createFromFormat('Y-m-d', $date);
        return $dt ? $date : null;
    }

    private static function existingDate(string $value): ?string
    {
        $value = trim($value);
        if ($value === '' || $value === '0000-00-00' || $value === '0000-00-00 00:00:00') {
            return null;
        }
        return substr($value, 0, 10);
    }

    /** @return array{relpath:string,original_name:string,mime_type:string,file_size:int,sha256:string} */
    private static function storeUploadedEvidencePdf(array $file, int $capId): array
    {
        $err = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Upload failed with error code ' . $err . '.');
        }
        $tmp = (string)($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new RuntimeException('Invalid uploaded file.');
        }
        $size = (int)($file['size'] ?? 0);
        if ($size <= 0) {
            throw new RuntimeException('Uploaded evidence is empty.');
        }
        if ($size > 50 * 1024 * 1024) {
            throw new RuntimeException('Evidence PDF exceeds maximum size of 50 MiB.');
        }
        $original = self::safeFilename((string)($file['name'] ?? 'cap-evidence.pdf'));
        $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        if ($ext !== 'pdf') {
            throw new RuntimeException('Only PDF evidence documents can be uploaded.');
        }
        $mime = self::detectMime($tmp);
        if ($mime !== 'application/pdf' && $mime !== 'application/octet-stream') {
            throw new RuntimeException('Uploaded evidence must be a PDF.');
        }
        $dirRel = 'storage/compliance/cap_evidence/' . $capId;
        $dirAbs = self::projectRoot() . '/' . $dirRel;
        if (!is_dir($dirAbs) && !mkdir($dirAbs, 0775, true) && !is_dir($dirAbs)) {
            throw new RuntimeException('Unable to create evidence storage directory.');
        }
        $sha = hash_file('sha256', $tmp) ?: bin2hex(random_bytes(16));
        $targetName = date('Ymd_His') . '_' . substr($sha, 0, 12) . '_' . $original;
        $targetAbs = $dirAbs . '/' . $targetName;
        if (!move_uploaded_file($tmp, $targetAbs)) {
            throw new RuntimeException('Unable to store uploaded evidence.');
        }
        return array(
            'relpath' => $dirRel . '/' . $targetName,
            'original_name' => $original,
            'mime_type' => 'application/pdf',
            'file_size' => $size,
            'sha256' => $sha,
        );
    }

    private static function safeFilename(string $name): string
    {
        $name = basename($name);
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name) ?: 'cap-evidence.pdf';
        return substr($name, 0, 180);
    }

    private static function detectMime(string $path): string
    {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($path);
        return is_string($mime) && $mime !== '' ? $mime : 'application/octet-stream';
    }

    private static function projectRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * AI: propose three CAP options (A/B/C). Logged as CAP_SUGGEST. Does not persist CAP rows.
     *
     * @return list<array<string,mixed>>
     */
    public static function suggestCapOptions(PDO $pdo, int $findingId, int $userId): array
    {
        $findingRow = ComplianceFindingEngine::getById($pdo, $findingId);
        if ($findingRow === null) {
            throw new RuntimeException('Finding not found.');
        }
        ComplianceFindingEngine::assertNotLocked($findingRow);

        $rca = ComplianceRcaCapEngine::getRcaForFinding($pdo, $findingId);
        $steps = array();
        if ($rca !== null && !empty($rca['steps_json'])) {
            $raw = $rca['steps_json'];
            if (is_string($raw)) {
                $decoded = json_decode($raw, true);
                $steps = is_array($decoded) ? ComplianceRcaCapEngine::normaliseSteps($decoded) : array();
            }
        }

        $ctx = ComplianceFindingEngine::buildFindingContextArray($findingRow);
        $findingText = self::buildFindingContext($ctx);

        $rcaText = '';
        if ($steps !== array()) {
            foreach ($steps as $s) {
                $n = (int)($s['whyNumber'] ?? 0);
                $q = trim((string)($s['question'] ?? ''));
                $a = trim((string)($s['answer'] ?? ''));
                if ($n >= 1 && $n <= 5) {
                    $rcaText .= "WHY {$n} QUESTION: {$q}\nWHY {$n} ANSWER (final): {$a}\n\n";
                }
            }
        } else {
            $rcaText = "(No 5-Whys RCA recorded yet — base recommendations on the finding only.)\n";
        }

        $prompt = <<<TXT
You are an aviation compliance expert. Create a proposed Corrective Action Plan (CAP) for an audit finding.

You must propose THREE options with increasing robustness:
- Option A (QUICK): quick and easy, low effort, limited scope.
- Option B (RECOMMENDED): balanced effort and strong compliance improvement.
- Option C (BEST): most robust systemic solution, more time and effort.

Rules:
- Actions must be realistic for an ATO/flight training organisation.
- Do not blame individuals; focus on process, training, oversight, documentation, tools.
- Each option must include 2–3 concrete action items ONLY.
- Each action item must include:
  - action_type: CORRECTIVE, PREVENTIVE, or CONTAINMENT
  - description: clear, authority-ready text
  - due_days: integer (e.g. 7 / 30 / 90)

Output MUST be valid JSON ONLY with:
{
  "options": [
    {
      "label":"Option A",
      "effort":"QUICK",
      "actions":[...]
    },
    {
      "label":"Option B",
      "effort":"RECOMMENDED",
      "actions":[...]
    },
    {
      "label":"Option C",
      "effort":"BEST",
      "actions":[...]
    }
  ]
}

FINDING CONTEXT:
{$findingText}

RCA / 5 WHYS (may be incomplete):
{$rcaText}
TXT;

        $t0 = (int)round(microtime(true) * 1000);
        $model = cw_openai_model();

        try {
            $raw = cw_openai_prompt_plaintext($prompt, null, 120);
            $t1 = (int)round(microtime(true) * 1000);
            $latency = max(0, $t1 - $t0);

            $decoded = self::decodeJsonSafely($raw);
            $options = array();
            if (is_array($decoded) && isset($decoded['options']) && is_array($decoded['options'])) {
                $options = $decoded['options'];
            }

            if (count($options) < 1) {
                ComplianceAiRunLogger::insert(
                    $pdo,
                    'finding',
                    $findingId,
                    'CAP_SUGGEST',
                    'ERROR',
                    $model,
                    $prompt,
                    array('finding_id' => $findingId),
                    null,
                    $raw,
                    $latency,
                    'Empty or invalid options in model output',
                    $userId
                );
                throw new RuntimeException(
                    'AI did not return usable CAP options. Check ipca_compliance_ai_runs for details.'
                );
            }

            $runId = ComplianceAiRunLogger::insert(
                $pdo,
                'finding',
                $findingId,
                'CAP_SUGGEST',
                'OK',
                $model,
                $prompt,
                array(
                    'finding_id' => $findingId,
                    'rca_step_count' => count($steps),
                ),
                array('options' => $options),
                $raw,
                $latency,
                null,
                $userId
            );

            $_SESSION['_ipca_compliance_cap_suggest'] = array(
                'finding_id' => $findingId,
                'options' => $options,
                'ai_run_id' => $runId,
                'saved_at' => time(),
            );

            return $options;
        } catch (Throwable $e) {
            $t1 = (int)round(microtime(true) * 1000);
            $latency = max(0, $t1 - $t0);

            ComplianceAiRunLogger::insert(
                $pdo,
                'finding',
                $findingId,
                'CAP_SUGGEST',
                'ERROR',
                $model,
                $prompt,
                array('finding_id' => $findingId),
                null,
                null,
                $latency,
                $e->getMessage(),
                $userId
            );

            throw $e;
        }
    }

    /**
     * Persist human-selected AI option: update finding flags and create PROPOSED corrective actions.
     *
     * @param array<string,mixed> $option Keys: label, effort, actions (list of action items)
     * @return list<int> New corrective action IDs
     */
    public static function adoptAiCapOption(
        PDO $pdo,
        int $findingId,
        array $option,
        int $userId,
        ?int $aiRunId
    ): array {
        $findingRow = ComplianceFindingEngine::getById($pdo, $findingId);
        if ($findingRow === null) {
            throw new RuntimeException('Finding not found.');
        }
        ComplianceFindingEngine::assertNotLocked($findingRow);

        $label = (string)($option['label'] ?? '');
        $effortLabel = (string)($option['effort'] ?? '');
        $selected = null;
        if (stripos($label, 'A') !== false) {
            $selected = 'A';
        }
        if (stripos($label, 'B') !== false) {
            $selected = 'B';
        }
        if (stripos($label, 'C') !== false) {
            $selected = 'C';
        }

        $actions = $option['actions'] ?? array();
        if (!is_array($actions) || count($actions) === 0) {
            throw new RuntimeException('Selected option contains no actions.');
        }

        $effortForRow = self::mapAiEffortToSchemaEffort($effortLabel);

        $pdo->beginTransaction();
        try {
            $stmtF = $pdo->prepare(
                'UPDATE ipca_compliance_findings SET
                    cap_selected_option = ?,
                    cap_selected_effort = ?,
                    updated_by = ?,
                    updated_at = NOW()
                 WHERE id = ? AND locked_at IS NULL LIMIT 1'
            );
            $stmtF->execute(array(
                $selected,
                $effortLabel !== '' ? $effortLabel : null,
                $userId,
                $findingId,
            ));

            if ($stmtF->rowCount() < 1) {
                throw new RuntimeException('Could not update finding (locked?).');
            }

            $created = array();
            $n = 0;
            foreach ($actions as $a) {
                if (!is_array($a)) {
                    continue;
                }
                $desc = trim((string)($a['description'] ?? ''));
                if ($desc === '') {
                    continue;
                }
                $type = strtoupper(trim((string)($a['action_type'] ?? 'CORRECTIVE')));
                if (!in_array($type, array('CORRECTIVE', 'PREVENTIVE', 'CONTAINMENT', 'IMMEDIATE'), true)) {
                    $type = 'CORRECTIVE';
                }

                $dueDays = (int)($a['due_days'] ?? 90);
                if ($dueDays < 1) {
                    $dueDays = 90;
                }
                $dueDate = (new DateTimeImmutable('today'))->modify('+' . $dueDays . ' days')->format('Y-m-d');

                $title = $desc;
                if (function_exists('mb_substr')) {
                    $title = mb_substr($title, 0, 255);
                } else {
                    $title = substr($title, 0, 255);
                }

                ++$n;
                $cid = self::create($pdo, array(
                    'finding_id' => $findingId,
                    'action_type' => $type === 'CONTAINMENT' ? 'CONTAINMENT' : ($type === 'PREVENTIVE' ? 'PREVENTIVE' : 'CORRECTIVE'),
                    'title' => $title !== '' ? $title : ('Action ' . $n),
                    'description' => $desc,
                    'status' => 'PROPOSED',
                    'effort' => $effortForRow,
                    'due_date' => $dueDate,
                    'ai_assisted' => true,
                    'ai_run_id' => $aiRunId,
                ), $userId);
                $created[] = $cid;
            }

            if ($created === array()) {
                throw new RuntimeException('No valid actions could be created from this option.');
            }

            $pdo->commit();

            unset($_SESSION['_ipca_compliance_cap_suggest']);

            foreach ($created as $cid) {
                $cap = self::getById($pdo, $cid);
                if ($cap === null) {
                    continue;
                }
                self::dispatchCapCreatedEvent(
                    $pdo,
                    $cid,
                    (string)$cap['action_code'],
                    $findingId,
                    (string)$cap['finding_code'],
                    (string)$cap['title'],
                    (string)$cap['status'],
                    (string)$cap['action_type'],
                    $userId
                );
            }

            return $created;
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    private static function mapAiEffortToSchemaEffort(string $effortLabel): ?string
    {
        $u = strtoupper(trim($effortLabel));
        if ($u === 'QUICK') {
            return 'S';
        }
        if ($u === 'RECOMMENDED') {
            return 'M';
        }
        if ($u === 'BEST') {
            return 'L';
        }
        return null;
    }

    /**
     * @param array<string,mixed> $f
     */
    private static function buildFindingContext(array $f): string
    {
        $parts = array();
        $parts[] = 'Reference: ' . ($f['reference'] ?? '');
        $parts[] = 'Title: ' . ($f['title'] ?? '');
        $parts[] = 'Classification: ' . ($f['classification'] ?? '');
        $parts[] = 'Severity: ' . ($f['severity'] ?? '');
        $parts[] = 'Regulation ref: ' . ($f['regulation_ref'] ?? '');
        $parts[] = "Description:\n" . ($f['description'] ?? '');
        return implode("\n", $parts);
    }

    private static function decodeJsonSafely(string $raw): mixed
    {
        $text = trim($raw);
        if (str_starts_with($text, '```')) {
            $text = preg_replace('/^```(?:json)?/i', '', $text) ?? $text;
            $text = preg_replace('/```$/', '', $text) ?? $text;
            $text = trim($text);
        }
        $decoded = json_decode($text, true);
        if (is_array($decoded)) {
            return $decoded;
        }
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $maybe = substr($text, $start, $end - $start + 1);
            $decoded2 = json_decode($maybe, true);
            if (is_array($decoded2)) {
                return $decoded2;
            }
        }
        return null;
    }

    private static function dispatchCapCreatedEvent(
        PDO $pdo,
        int $correctiveActionId,
        string $actionCode,
        int $findingId,
        string $findingCode,
        string $title,
        string $status,
        string $actionType,
        int $userId
    ): void {
        $path = __DIR__ . '/../automation_runtime.php';
        if (!is_file($path)) {
            return;
        }
        require_once $path;
        try {
            $rt = new AutomationRuntime();
            $rt->dispatchEvent($pdo, 'compliance.cap.created', array(
                'corrective_action_id' => $correctiveActionId,
                'action_code' => $actionCode,
                'finding_id' => $findingId,
                'finding_code' => $findingCode,
                'title' => $title,
                'status' => $status,
                'action_type' => $actionType,
                'created_by_user_id' => $userId,
            ));
        } catch (Throwable $e) {
            // Non-fatal
        }
    }
}
