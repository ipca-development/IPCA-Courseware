<?php
declare(strict_types=1);

/**
 * Persist every compliance-domain AI invocation to ipca_compliance_ai_runs.
 */
final class ComplianceAiRunLogger
{
    /**
     * @param array<string,mixed>|null $evidenceSnapshot
     * @param array<string,mixed>|null $responseJson
     */
    public static function insert(
        PDO $pdo,
        string $sourceObjectType,
        ?int $sourceObjectId,
        string $runType,
        string $status,
        ?string $model,
        ?string $promptText,
        ?array $evidenceSnapshot,
        ?array $responseJson,
        ?string $responseText,
        ?int $latencyMs,
        ?string $errorMessage,
        ?int $createdByUserId
    ): int {
        $promptHash = null;
        if ($promptText === null || $promptText === '') {
            $promptText = null;
        } elseif (strlen($promptText) > 16000) {
            $promptHash = hash('sha256', $promptText);
            $promptText = null;
        }

        $evidenceJson = null;
        if ($evidenceSnapshot !== null) {
            $enc = json_encode($evidenceSnapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $evidenceJson = ($enc !== false) ? $enc : null;
        }

        $responseJsonStr = null;
        if ($responseJson !== null) {
            $enc = json_encode($responseJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $responseJsonStr = ($enc !== false) ? $enc : null;
        }

        $sql = 'INSERT INTO ipca_compliance_ai_runs (
            source_object_type, source_object_id, run_type, status, model,
            prompt_text, prompt_hash, evidence_snapshot_json, response_json, response_text,
            latency_ms, error_message, created_by
        ) VALUES (
            ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?,
            ?, ?, ?
        )';

        $stmt = $pdo->prepare($sql);
        $stmt->execute(array(
            $sourceObjectType,
            $sourceObjectId,
            $runType,
            $status,
            $model,
            $promptText,
            $promptHash,
            $evidenceJson,
            $responseJsonStr,
            $responseText,
            $latencyMs,
            $errorMessage,
            $createdByUserId,
        ));

        return (int)$pdo->lastInsertId();
    }
}
