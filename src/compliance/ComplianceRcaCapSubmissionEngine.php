<?php
declare(strict_types=1);

require_once __DIR__ . '/ComplianceApprovalEngine.php';
require_once __DIR__ . '/ComplianceCaseEvents.php';

final class ComplianceRcaCapSubmissionEngine
{
    public static function tablePresent(PDO $pdo): bool
    {
        try {
            $pdo->query('SELECT id FROM ipca_compliance_rca_cap_submissions LIMIT 0');
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public static function capSubmissionColumnPresent(PDO $pdo): bool
    {
        try {
            $st = $pdo->query("SHOW COLUMNS FROM ipca_compliance_corrective_actions LIKE 'submission_id'");
            return (bool)$st->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return false;
        }
    }

    public static function ensureBaselineSubmission(PDO $pdo, int $findingId, ?int $userId = null): ?int
    {
        if (!self::tablePresent($pdo) || $findingId <= 0) {
            return null;
        }
        $current = self::currentForFinding($pdo, $findingId);
        if ($current !== null) {
            return (int)$current['id'];
        }

        $rcaText = null;
        try {
            $stRca = $pdo->prepare('SELECT root_cause_text FROM ipca_compliance_finding_rca WHERE finding_id = ? LIMIT 1');
            $stRca->execute(array($findingId));
            $rcaText = $stRca->fetchColumn();
            $rcaText = is_string($rcaText) && trim($rcaText) !== '' ? $rcaText : null;
        } catch (Throwable) {
            $rcaText = null;
        }

        $capSummary = null;
        try {
            $stCap = $pdo->prepare(
                "SELECT GROUP_CONCAT(CONCAT(action_code, ': ', title) ORDER BY id SEPARATOR '\n')
                   FROM ipca_compliance_corrective_actions
                  WHERE finding_id = ?"
            );
            $stCap->execute(array($findingId));
            $capSummary = $stCap->fetchColumn();
            $capSummary = is_string($capSummary) && trim($capSummary) !== '' ? $capSummary : null;
        } catch (Throwable) {
            $capSummary = null;
        }

        $st = $pdo->prepare(
            'INSERT INTO ipca_compliance_rca_cap_submissions
                (finding_id, submission_no, submission_type, status, rca_text, cap_summary, submitted_at, submitted_by, review_notes, locked_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $st->execute(array(
            $findingId,
            self::nextSubmissionNo($pdo, $findingId),
            'internal',
            'draft',
            $rcaText,
            $capSummary,
            null,
            $userId !== null && $userId > 0 ? $userId : null,
            'Baseline submission opened by the current RCA/CAP workflow.',
            null,
        ));

        return (int)$pdo->lastInsertId();
    }

    public static function ensureWorkingSubmission(PDO $pdo, int $findingId, ?int $userId = null): ?int
    {
        if (!self::tablePresent($pdo)) {
            return null;
        }
        $current = self::currentForFinding($pdo, $findingId);
        if ($current === null) {
            return self::ensureBaselineSubmission($pdo, $findingId, $userId);
        }
        $status = (string)($current['status'] ?? '');
        if (empty($current['locked_at']) && in_array($status, array('draft', 'submitted', 'under_review'), true)) {
            return (int)$current['id'];
        }
        return self::createRevision($pdo, $findingId, array('submitted_by' => $userId));
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function getById(PDO $pdo, int $submissionId): ?array
    {
        if (!self::tablePresent($pdo) || $submissionId <= 0) {
            return null;
        }
        $st = $pdo->prepare('SELECT * FROM ipca_compliance_rca_cap_submissions WHERE id = ? LIMIT 1');
        $st->execute(array($submissionId));
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function currentForFinding(PDO $pdo, int $findingId): ?array
    {
        if (!self::tablePresent($pdo)) {
            return null;
        }
        $st = $pdo->prepare(
            "SELECT *
               FROM ipca_compliance_rca_cap_submissions
              WHERE finding_id = ?
                AND status <> 'superseded'
              ORDER BY submission_no DESC, id DESC
              LIMIT 1"
        );
        $st->execute(array($findingId));
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public static function listForFinding(PDO $pdo, int $findingId): array
    {
        if (!self::tablePresent($pdo)) {
            return array();
        }
        $st = $pdo->prepare(
            'SELECT *
               FROM ipca_compliance_rca_cap_submissions
              WHERE finding_id = ?
              ORDER BY submission_no ASC, id ASC'
        );
        $st->execute(array($findingId));
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : array();
    }

    /**
     * @param array<string,mixed> $opts
     */
    public static function createRevision(PDO $pdo, int $findingId, array $opts = array()): ?int
    {
        if (!self::tablePresent($pdo)) {
            return null;
        }
        self::supersedeOpenSubmissions($pdo, $findingId);
        $st = $pdo->prepare(
            'INSERT INTO ipca_compliance_rca_cap_submissions
                (finding_id, submission_no, submission_type, status, rca_text, cap_summary,
                 proposed_rca_deadline, proposed_cap_deadline, email_thread_id, submitted_by, review_notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $st->execute(array(
            $findingId,
            self::nextSubmissionNo($pdo, $findingId),
            in_array((string)($opts['submission_type'] ?? 'internal'), array('authority', 'internal'), true)
                ? (string)$opts['submission_type'] : 'internal',
            'draft',
            isset($opts['rca_text']) ? (string)$opts['rca_text'] : null,
            isset($opts['cap_summary']) ? (string)$opts['cap_summary'] : null,
            isset($opts['proposed_rca_deadline']) && (string)$opts['proposed_rca_deadline'] !== '' ? (string)$opts['proposed_rca_deadline'] : null,
            isset($opts['proposed_cap_deadline']) && (string)$opts['proposed_cap_deadline'] !== '' ? (string)$opts['proposed_cap_deadline'] : null,
            isset($opts['email_thread_id']) && (int)$opts['email_thread_id'] > 0 ? (int)$opts['email_thread_id'] : null,
            isset($opts['submitted_by']) && (int)$opts['submitted_by'] > 0 ? (int)$opts['submitted_by'] : null,
            isset($opts['review_notes']) ? (string)$opts['review_notes'] : null,
        ));
        return (int)$pdo->lastInsertId();
    }

    public static function snapshotCurrentDraft(PDO $pdo, int $findingId, ?int $userId = null): ?int
    {
        $submissionId = self::ensureBaselineSubmission($pdo, $findingId, $userId);
        if ($submissionId === null) {
            return null;
        }
        $rcaText = null;
        try {
            $st = $pdo->prepare('SELECT root_cause_text FROM ipca_compliance_finding_rca WHERE finding_id = ? LIMIT 1');
            $st->execute(array($findingId));
            $rcaText = $st->fetchColumn();
            $rcaText = is_string($rcaText) ? $rcaText : null;
        } catch (Throwable) {
            $rcaText = null;
        }
        $capSummary = null;
        try {
            $st = $pdo->prepare(
                "SELECT GROUP_CONCAT(CONCAT(action_code, ': ', title) ORDER BY id SEPARATOR '\n')
                   FROM ipca_compliance_corrective_actions WHERE finding_id = ?"
            );
            $st->execute(array($findingId));
            $capSummary = $st->fetchColumn();
            $capSummary = is_string($capSummary) ? $capSummary : null;
        } catch (Throwable) {
            $capSummary = null;
        }
        $pdo->prepare(
            'UPDATE ipca_compliance_rca_cap_submissions
                SET rca_text = COALESCE(?, rca_text),
                    cap_summary = COALESCE(?, cap_summary),
                    updated_at = NOW()
              WHERE id = ? AND locked_at IS NULL'
        )->execute(array($rcaText, $capSummary, $submissionId));
        return $submissionId;
    }

    public static function submit(PDO $pdo, int $submissionId, int $userId, ?int $emailThreadId = null): void
    {
        if (!self::tablePresent($pdo)) {
            return;
        }
        $pdo->prepare(
            "UPDATE ipca_compliance_rca_cap_submissions
                SET status = 'submitted',
                    submitted_at = COALESCE(submitted_at, NOW()),
                    submitted_by = COALESCE(submitted_by, ?),
                    email_thread_id = COALESCE(?, email_thread_id),
                    updated_at = NOW()
              WHERE id = ? AND status = 'draft' AND locked_at IS NULL"
        )->execute(array($userId > 0 ? $userId : null, $emailThreadId, $submissionId));
    }

    public static function review(PDO $pdo, int $submissionId, string $decision, int $reviewedBy, ?string $notes = null): void
    {
        if (!self::tablePresent($pdo)) {
            return;
        }
        $decision = strtolower(trim($decision));
        $status = $decision === 'approved' ? 'approved' : ($decision === 'partially_approved' ? 'partially_approved' : 'rejected');
        $st = $pdo->prepare(
            'UPDATE ipca_compliance_rca_cap_submissions
                SET status = ?, reviewed_at = NOW(), reviewed_by = ?, review_notes = ?, locked_at = NOW(), updated_at = NOW()
              WHERE id = ? AND locked_at IS NULL'
        );
        $st->execute(array($status, $reviewedBy > 0 ? $reviewedBy : null, $notes, $submissionId));
        ComplianceApprovalEngine::record($pdo, array(
            'object_type' => 'rca_cap_submission',
            'object_id' => $submissionId,
            'approval_type' => 'cap',
            'decision' => $status === 'partially_approved' ? 'partially_approved' : ($status === 'approved' ? 'approved' : 'rejected'),
            'reviewed_by' => $reviewedBy,
            'notes' => $notes,
        ));
    }

    public static function approvedCapDeadlineForFinding(PDO $pdo, int $findingId): ?string
    {
        if (!self::tablePresent($pdo)) {
            return null;
        }
        $st = $pdo->prepare(
            "SELECT MIN(COALESCE(s.approved_cap_deadline, c.due_date))
               FROM ipca_compliance_rca_cap_submissions s
               JOIN ipca_compliance_corrective_actions c ON c.submission_id = s.id
              WHERE s.finding_id = ?
                AND s.status IN ('approved','partially_approved')
                AND c.due_date IS NOT NULL
                AND COALESCE(c.status,'') NOT IN ('CANCELLED','cancelled')"
        );
        $st->execute(array($findingId));
        $date = $st->fetchColumn();
        return is_string($date) && $date !== '' ? substr($date, 0, 10) : null;
    }

    public static function nextSubmissionNo(PDO $pdo, int $findingId): int
    {
        if (!self::tablePresent($pdo)) {
            return 1;
        }
        $st = $pdo->prepare('SELECT COALESCE(MAX(submission_no), 0) + 1 FROM ipca_compliance_rca_cap_submissions WHERE finding_id = ?');
        $st->execute(array($findingId));
        return max(1, (int)$st->fetchColumn());
    }

    private static function supersedeOpenSubmissions(PDO $pdo, int $findingId): void
    {
        $pdo->prepare(
            "UPDATE ipca_compliance_rca_cap_submissions
                SET status = 'superseded', locked_at = COALESCE(locked_at, NOW()), updated_at = NOW()
              WHERE finding_id = ?
                AND status IN ('draft','submitted','under_review','rejected')"
        )->execute(array($findingId));
    }
}
