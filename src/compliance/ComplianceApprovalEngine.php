<?php
declare(strict_types=1);

final class ComplianceApprovalEngine
{
    public static function tablePresent(PDO $pdo): bool
    {
        try {
            $pdo->query('SELECT id FROM ipca_compliance_approvals LIMIT 0');
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function record(PDO $pdo, array $data): ?int
    {
        if (!self::tablePresent($pdo)) {
            return null;
        }
        $objectType = trim((string)($data['object_type'] ?? ''));
        $objectId = (int)($data['object_id'] ?? 0);
        $approvalType = trim((string)($data['approval_type'] ?? ''));
        $decision = trim((string)($data['decision'] ?? ''));
        if ($objectType === '' || $objectId <= 0) {
            throw new InvalidArgumentException('Approval object is required.');
        }
        if (!in_array($approvalType, array('rca', 'cap', 'deadline', 'extension', 'closure'), true)) {
            throw new InvalidArgumentException('Unsupported approval type.');
        }
        if (!in_array($decision, array('approved', 'rejected', 'partially_approved'), true)) {
            throw new InvalidArgumentException('Unsupported approval decision.');
        }

        $reviewedAt = trim((string)($data['reviewed_at'] ?? ''));
        if ($reviewedAt === '') {
            $reviewedAt = date('Y-m-d H:i:s');
        }
        $reviewedBy = isset($data['reviewed_by']) && (int)$data['reviewed_by'] > 0 ? (int)$data['reviewed_by'] : null;
        $notes = trim((string)($data['notes'] ?? ''));

        $st = $pdo->prepare(
            'INSERT INTO ipca_compliance_approvals
                (object_type, object_id, approval_type, decision, reviewed_by, reviewed_at, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $st->execute(array(
            substr($objectType, 0, 100),
            $objectId,
            $approvalType,
            $decision,
            $reviewedBy,
            $reviewedAt,
            $notes !== '' ? $notes : null,
        ));

        return (int)$pdo->lastInsertId();
    }

    /**
     * @return list<array<string,mixed>>
     */
    public static function listForObject(PDO $pdo, string $objectType, int $objectId): array
    {
        if (!self::tablePresent($pdo) || $objectId <= 0) {
            return array();
        }
        $st = $pdo->prepare(
            'SELECT *
               FROM ipca_compliance_approvals
              WHERE object_type = ? AND object_id = ?
              ORDER BY reviewed_at DESC, id DESC'
        );
        $st->execute(array($objectType, $objectId));
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : array();
    }
}
