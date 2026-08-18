<?php
declare(strict_types=1);

require_once __DIR__ . '/SafetySupport.php';
require_once __DIR__ . '/SafetyAccessService.php';
require_once __DIR__ . '/SafetyAuditEventService.php';

final class SafetyReporterVaultService
{
    public function __construct(
        private PDO $pdo,
        private SafetyAccessService $access,
        private SafetyAuditEventService $events
    ) {
    }

    /** @param array<string,mixed> $session @return array{user_id:int} */
    public function reveal(array $session, string $reportUuid, string $reason): array
    {
        $this->access->requirePermission($session, 'vault.read');
        $organizationId = SafetySupport::organizationId($session);
        $reason = SafetySupport::cleanText($reason, 1000, 'reason');
        $stmt = $this->pdo->prepare(
            'SELECT v.id AS vault_id, v.identity_ciphertext, r.id AS report_id
             FROM ipca_safety_reporter_vault v
             INNER JOIN ipca_safety_reports r ON r.id = v.report_id
             WHERE v.organization_id = ? AND r.organization_id = ? AND r.report_uuid = ? LIMIT 1'
        );
        $stmt->execute(array($organizationId, $organizationId, strtolower(trim($reportUuid))));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new SafetyException('not_found', 'Confidential reporter identity not found.', 404);
        }
        $identity = SafetySupport::decryptReporterIdentity((string)$row['identity_ciphertext']);
        if ($identity['organization_id'] !== $organizationId) {
            throw new SafetyException('vault_integrity_error', 'Reporter vault scope is invalid.', 500);
        }
        $this->pdo->prepare(
            'UPDATE ipca_safety_reporter_vault SET accessed_at_utc = CURRENT_TIMESTAMP(3) WHERE id = ?'
        )->execute(array((int)$row['vault_id']));
        $this->events->append(
            $organizationId,
            'report',
            (int)$row['report_id'],
            'reporter_vault.accessed',
            'user',
            (int)$session['user']['id'],
            null,
            array('reason' => $reason)
        );
        return array('user_id' => $identity['user_id']);
    }
}
