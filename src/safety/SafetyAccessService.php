<?php
declare(strict_types=1);

require_once __DIR__ . '/SafetySupport.php';

final class SafetyAccessService
{
    private const PERMISSIONS = array(
        'safety_admin' => array('*'),
        'safety_manager' => array('report.read_all', 'report.triage', 'report.close', 'workflow.transition', 'risk.manage',
            'risk.accept',
            'investigation.manage', 'action.manage', 'bulletin.manage', 'analytics.read', 'analytics.manage',
            'correlation.manage', 'ai.assist', 'ai.review', 'vault.read'),
        'safety_investigator' => array('report.read_all', 'workflow.transition', 'risk.manage',
            'investigation.manage', 'action.manage'),
        'safety_action_owner' => array('action.update', 'action.evidence'),
        'safety_analyst' => array('report.read_deidentified', 'analytics.read', 'ai.assist'),
    );

    public function __construct(private PDO $pdo)
    {
    }

    /** @param array<string,mixed> $session */
    public function requirePermission(array $session, string $permission): void
    {
        if ($this->hasPermission($session, $permission)) {
            return;
        }
        throw new SafetyException('forbidden', 'You do not have permission to perform this safety operation.', 403);
    }

    /** @param array<string,mixed> $session */
    public function hasPermission(array $session, string $permission): bool
    {
        $organizationId = SafetySupport::organizationId($session);
        $userId = (int)($session['user']['id'] ?? 0);
        if ($userId < 1) {
            return false;
        }
        foreach ($this->roles($organizationId, $userId) as $role) {
            $grants = self::PERMISSIONS[$role] ?? array();
            if (in_array('*', $grants, true) || in_array($permission, $grants, true)) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string,mixed> $session */
    public function requireOwnReport(array $session, string $reportUuid, bool $editable = false): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM ipca_safety_reports
             WHERE organization_id = ? AND report_uuid = ?
               AND (reporter_user_id = ? OR reporter_subject_hash = ?) LIMIT 1'
        );
        $organizationId = SafetySupport::organizationId($session);
        $userId = (int)$session['user']['id'];
        $stmt->execute(array(
            $organizationId,
            strtolower(trim($reportUuid)),
            $userId,
            SafetySupport::reporterSubjectHash($organizationId, $userId),
        ));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new SafetyException('not_found', 'Safety report not found.', 404);
        }
        if ($editable && !in_array((string)$row['status'], array('draft', 'returned'), true)) {
            throw new SafetyException('workflow_gate_failed', 'Only draft or returned reports can be edited.', 409);
        }
        return $row;
    }

    /** @return array<int,string> */
    private function roles(int $organizationId, int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT role_code FROM ipca_safety_role_assignments
             WHERE organization_id = ? AND user_id = ? AND revoked_at_utc IS NULL
               AND valid_from_utc <= CURRENT_TIMESTAMP(3)
               AND (valid_until_utc IS NULL OR valid_until_utc > CURRENT_TIMESTAMP(3))'
        );
        $stmt->execute(array($organizationId, $userId));
        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
}
