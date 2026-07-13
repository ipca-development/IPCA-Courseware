<?php
declare(strict_types=1);

require_once __DIR__ . '/AuditEventService.php';

final class ValidationResultService
{
    public const INFO = 'INFO';
    public const WARNING = 'WARNING';
    public const REVIEW_REQUIRED = 'REVIEW_REQUIRED';
    public const BLOCKING = 'BLOCKING';
    public const INVALID = 'INVALID';

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @param array<string,mixed>|null $evidence
     */
    public function add(
        string $entityType,
        string $entityId,
        string $machineCode,
        string $severity,
        string $humanExplanation,
        ?string $affectedField = null,
        ?array $evidence = null,
        bool $blocksOperationalFinalization = false,
        bool $blocksLogbookAcceptance = false,
        bool $blocksDispatch = false,
        int $organizationId = 1
    ): int {
        $severity = $this->normalizeSeverity($severity);
        $stmt = $this->pdo->prepare("
            INSERT INTO ipca_validation_results
              (validation_uuid, organization_id, entity_type, entity_id, affected_field, machine_code, severity,
               human_explanation, evidence_json, blocks_operational_finalization, blocks_logbook_acceptance, blocks_dispatch)
            VALUES
              (:validation_uuid, :organization_id, :entity_type, :entity_id, :affected_field, :machine_code, :severity,
               :human_explanation, :evidence_json, :blocks_operational_finalization, :blocks_logbook_acceptance, :blocks_dispatch)
        ");
        $stmt->execute(array(
            ':validation_uuid' => AuditEventService::uuid(),
            ':organization_id' => $organizationId,
            ':entity_type' => substr($entityType, 0, 96),
            ':entity_id' => substr($entityId, 0, 128),
            ':affected_field' => $affectedField !== null ? substr($affectedField, 0, 128) : null,
            ':machine_code' => substr($machineCode, 0, 128),
            ':severity' => $severity,
            ':human_explanation' => $humanExplanation,
            ':evidence_json' => $evidence !== null ? AuditEventService::jsonEncode($evidence) : null,
            ':blocks_operational_finalization' => $blocksOperationalFinalization ? 1 : 0,
            ':blocks_logbook_acceptance' => $blocksLogbookAcceptance ? 1 : 0,
            ':blocks_dispatch' => $blocksDispatch ? 1 : 0,
        ));
        return (int)$this->pdo->lastInsertId();
    }

    private function normalizeSeverity(string $severity): string
    {
        $severity = strtoupper(trim($severity));
        return in_array($severity, array(self::INFO, self::WARNING, self::REVIEW_REQUIRED, self::BLOCKING, self::INVALID), true)
            ? $severity
            : self::WARNING;
    }
}
