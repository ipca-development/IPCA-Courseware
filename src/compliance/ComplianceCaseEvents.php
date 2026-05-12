<?php
declare(strict_types=1);

/**
 * Append a row to ipca_compliance_case_events (compliance-specific audit trail).
 *
 * @param array<string,mixed>|null $before
 * @param array<string,mixed>|null $after
 * @param array<string,mixed>|null $metadata
 */
function compliance_log_case_event(
    PDO $pdo,
    ?int $caseId,
    string $entityType,
    ?int $entityId,
    string $eventKind,
    ?int $actorUserId,
    ?string $summary,
    ?array $before = null,
    ?array $after = null,
    ?array $metadata = null
): void {
    $actorIp = null;
    if (!empty($_SERVER['REMOTE_ADDR']) && is_string($_SERVER['REMOTE_ADDR'])) {
        $actorIp = substr($_SERVER['REMOTE_ADDR'], 0, 64);
    }

    $beforeJson = null;
    if ($before !== null) {
        $enc = json_encode($before, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $beforeJson = ($enc !== false) ? $enc : null;
    }
    $afterJson = null;
    if ($after !== null) {
        $enc = json_encode($after, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $afterJson = ($enc !== false) ? $enc : null;
    }
    $metaJson = null;
    if ($metadata !== null) {
        $enc = json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $metaJson = ($enc !== false) ? $enc : null;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO ipca_compliance_case_events (
            case_id, entity_type, entity_id, event_kind, actor_user_id, actor_ip,
            summary, before_json, after_json, metadata_json, occurred_at
        ) VALUES (
            ?, ?, ?, ?, ?, ?,
            ?, ?, ?, ?, NOW()
        )'
    );

    $stmt->execute(array(
        $caseId,
        $entityType,
        $entityId,
        $eventKind,
        $actorUserId,
        $actorIp,
        $summary,
        $beforeJson,
        $afterJson,
        $metaJson,
    ));
}
