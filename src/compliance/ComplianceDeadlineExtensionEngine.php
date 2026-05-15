<?php
declare(strict_types=1);

require_once __DIR__ . '/ComplianceApprovalEngine.php';

final class ComplianceDeadlineExtensionEngine
{
    public static function rcaCapTablePresent(PDO $pdo): bool
    {
        try {
            $pdo->query('SELECT id FROM ipca_compliance_rca_cap_deadline_extensions LIMIT 0');
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public static function capTablePresent(PDO $pdo): bool
    {
        try {
            $pdo->query('SELECT id FROM ipca_compliance_corrective_action_deadline_extensions LIMIT 0');
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return list<array<string,mixed>>
     */
    public static function listForSubmission(PDO $pdo, int $submissionId): array
    {
        if (!self::rcaCapTablePresent($pdo) || $submissionId <= 0) {
            return array();
        }
        $st = $pdo->prepare(
            'SELECT *
               FROM ipca_compliance_rca_cap_deadline_extensions
              WHERE submission_id = ?
              ORDER BY extension_no ASC, id ASC'
        );
        $st->execute(array($submissionId));
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : array();
    }

    /**
     * @return list<array<string,mixed>>
     */
    public static function listForCorrectiveAction(PDO $pdo, int $correctiveActionId): array
    {
        if (!self::capTablePresent($pdo) || $correctiveActionId <= 0) {
            return array();
        }
        $st = $pdo->prepare(
            'SELECT *
               FROM ipca_compliance_corrective_action_deadline_extensions
              WHERE corrective_action_id = ?
              ORDER BY extension_no ASC, id ASC'
        );
        $st->execute(array($correctiveActionId));
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : array();
    }

    public static function effectiveCorrectiveActionDeadline(PDO $pdo, int $correctiveActionId, ?string $baseDueDate): ?string
    {
        $effective = $baseDueDate !== null && trim($baseDueDate) !== '' ? substr(trim($baseDueDate), 0, 10) : null;
        if (!self::capTablePresent($pdo) || $correctiveActionId <= 0) {
            return $effective;
        }
        $st = $pdo->prepare(
            "SELECT approved_deadline
               FROM ipca_compliance_corrective_action_deadline_extensions
              WHERE corrective_action_id = ?
                AND status = 'approved'
                AND approved_deadline IS NOT NULL
              ORDER BY approved_deadline DESC, id DESC
              LIMIT 1"
        );
        $st->execute(array($correctiveActionId));
        $approved = $st->fetchColumn();
        return is_string($approved) && $approved !== '' ? substr($approved, 0, 10) : $effective;
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function requestCorrectiveActionExtension(PDO $pdo, int $correctiveActionId, array $data): ?int
    {
        if (!self::capTablePresent($pdo)) {
            return null;
        }
        $previous = trim((string)($data['previous_deadline'] ?? ''));
        $requested = trim((string)($data['requested_deadline'] ?? ''));
        if ($previous === '' || $requested === '') {
            throw new InvalidArgumentException('Previous and requested deadlines are required.');
        }
        $stNo = $pdo->prepare('SELECT COALESCE(MAX(extension_no), 0) + 1 FROM ipca_compliance_corrective_action_deadline_extensions WHERE corrective_action_id = ?');
        $stNo->execute(array($correctiveActionId));
        $extensionNo = (int)$stNo->fetchColumn();
        $st = $pdo->prepare(
            'INSERT INTO ipca_compliance_corrective_action_deadline_extensions
                (corrective_action_id, extension_no, previous_deadline, requested_deadline, reason, status, submitted_at, email_thread_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $status = in_array((string)($data['status'] ?? 'draft'), array('draft', 'submitted'), true) ? (string)$data['status'] : 'draft';
        $st->execute(array(
            $correctiveActionId,
            $extensionNo,
            substr($previous, 0, 10),
            substr($requested, 0, 10),
            trim((string)($data['reason'] ?? '')) !== '' ? trim((string)$data['reason']) : null,
            $status,
            $status === 'submitted' ? date('Y-m-d H:i:s') : null,
            isset($data['email_thread_id']) && (int)$data['email_thread_id'] > 0 ? (int)$data['email_thread_id'] : null,
        ));
        return (int)$pdo->lastInsertId();
    }

    public static function recordApprovedCorrectiveActionExtension(
        PDO $pdo,
        int $correctiveActionId,
        string $previousDeadline,
        string $approvedDeadline,
        int $reviewedBy,
        ?string $reason = null
    ): ?int {
        if (!self::capTablePresent($pdo)) {
            return null;
        }
        $previousDeadline = substr(trim($previousDeadline), 0, 10);
        $approvedDeadline = substr(trim($approvedDeadline), 0, 10);
        if ($previousDeadline === '' || $approvedDeadline === '' || $previousDeadline === $approvedDeadline) {
            return null;
        }
        $stNo = $pdo->prepare('SELECT COALESCE(MAX(extension_no), 0) + 1 FROM ipca_compliance_corrective_action_deadline_extensions WHERE corrective_action_id = ?');
        $stNo->execute(array($correctiveActionId));
        $extensionNo = (int)$stNo->fetchColumn();
        $st = $pdo->prepare(
            "INSERT INTO ipca_compliance_corrective_action_deadline_extensions
                (corrective_action_id, extension_no, previous_deadline, requested_deadline, approved_deadline,
                 reason, status, submitted_at, reviewed_at, reviewed_by, review_notes)
             VALUES (?, ?, ?, ?, ?, ?, 'approved', NOW(), NOW(), ?, ?)"
        );
        $note = $reason !== null && trim($reason) !== '' ? trim($reason) : 'Approved through corrective-action deadline update.';
        $st->execute(array(
            $correctiveActionId,
            $extensionNo,
            $previousDeadline,
            $approvedDeadline,
            $approvedDeadline,
            $note,
            $reviewedBy > 0 ? $reviewedBy : null,
            $note,
        ));
        $id = (int)$pdo->lastInsertId();
        ComplianceApprovalEngine::record($pdo, array(
            'object_type' => 'corrective_action_deadline_extension',
            'object_id' => $id,
            'approval_type' => 'extension',
            'decision' => 'approved',
            'reviewed_by' => $reviewedBy,
            'notes' => $note,
        ));
        return $id;
    }
}
