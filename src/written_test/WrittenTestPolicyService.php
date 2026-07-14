<?php
declare(strict_types=1);

require_once __DIR__ . '/WrittenTestSupport.php';

final class WrittenTestPolicyService
{
    public function __construct(private PDO $pdo)
    {
        WrittenTestSupport::ensureSchema($this->pdo);
    }

    /** @return list<array<string,mixed>> */
    public function definitions(): array
    {
        $ph = implode(',', array_fill(0, count(WrittenTestSupport::POLICY_KEYS), '?'));
        $st = $this->pdo->prepare("
            SELECT policy_key, category, value_type, default_value_text, description_text, sort_order
            FROM system_policy_definitions
            WHERE policy_key IN ($ph)
            ORDER BY sort_order ASC, policy_key ASC
        ");
        $st->execute(WrittenTestSupport::POLICY_KEYS);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array{policy:array<string,mixed>,sources:array<string,mixed>,raw:array<string,mixed>} */
    public function resolveSnapshot(int $allocationId): array
    {
        $allocation = $this->allocationById($allocationId);
        if (!$allocation) {
            throw new RuntimeException('Written Test allocation not found.');
        }

        $definitions = $this->definitions();
        if (!$definitions) {
            throw new RuntimeException('Written Test policy definitions are not installed.');
        }

        $scope = [
            'cohort_id' => (int)$allocation['cohort_id'],
            'course_id' => (int)($allocation['related_course_id'] ?: 0),
        ];

        $policy = [];
        $sources = [];
        $raw = [];
        foreach ($definitions as $definition) {
            $key = (string)$definition['policy_key'];
            $resolved = $this->resolvePolicyValueText($key, $scope);
            $valueText = $resolved['value_text'];
            if ($valueText === null) {
                $valueText = (string)($definition['default_value_text'] ?? '');
                $resolved = [
                    'source_type' => 'definition_default',
                    'source_id' => null,
                    'value_text' => $valueText,
                    'system_policy_value_id' => null,
                ];
            }
            $policy[$key] = $this->castPolicyValue((string)$definition['value_type'], $valueText);
            $sources[$key] = [
                'source_type' => $resolved['source_type'],
                'source_id' => $resolved['source_id'],
                'system_policy_value_id' => $resolved['system_policy_value_id'],
            ];
            $raw[$key] = $valueText;
        }

        return [
            'policy' => $policy,
            'sources' => $sources,
            'raw' => $raw,
        ];
    }

    /** @return array<string,mixed> */
    public function publishSnapshot(int $allocationId, int $actorUserId, string $changeSummary): array
    {
        $changeSummary = trim($changeSummary);
        if ($changeSummary === '') {
            throw new InvalidArgumentException('A policy publication reason is required.');
        }

        $snapshot = $this->resolveSnapshot($allocationId);
        $this->pdo->beginTransaction();
        try {
            $latest = $this->pdo->prepare("
                SELECT id, version_number
                FROM written_test_policy_versions
                WHERE allocation_id = ?
                ORDER BY version_number DESC, id DESC
                LIMIT 1
            ");
            $latest->execute([$allocationId]);
            $latestRow = $latest->fetch(PDO::FETCH_ASSOC) ?: null;
            $nextVersion = ((int)($latestRow['version_number'] ?? 0)) + 1;

            $published = $this->pdo->prepare("
                SELECT id
                FROM written_test_policy_versions
                WHERE allocation_id = ? AND version_status = 'published'
                ORDER BY version_number DESC, id DESC
                LIMIT 1
            ");
            $published->execute([$allocationId]);
            $replacedId = (int)($published->fetchColumn() ?: 0);
            if ($replacedId > 0) {
                $this->pdo->prepare("
                    UPDATE written_test_policy_versions
                    SET version_status = 'superseded', effective_end_at = UTC_TIMESTAMP()
                    WHERE id = ?
                ")->execute([$replacedId]);
            }

            $payload = [
                'namespace' => 'written_test',
                'allocation_id' => $allocationId,
                'created_at_utc' => WrittenTestSupport::utcNow(),
                'policy' => $snapshot['policy'],
                'raw_values' => $snapshot['raw'],
            ];
            $sourcePayload = [
                'resolver' => 'cohort > course > global > definition_default',
                'sources' => $snapshot['sources'],
            ];

            $ins = $this->pdo->prepare("
                INSERT INTO written_test_policy_versions
                  (allocation_id, version_number, version_status, resolved_policy_json, source_scope_json,
                   change_summary, effective_start_at, created_by_user_id, published_by_user_id, published_at,
                   replaced_policy_version_id)
                VALUES (?, ?, 'published', ?, ?, ?, UTC_TIMESTAMP(), ?, ?, UTC_TIMESTAMP(), ?)
            ");
            $ins->execute([
                $allocationId,
                $nextVersion,
                WrittenTestSupport::jsonEncode($payload),
                WrittenTestSupport::jsonEncode($sourcePayload),
                $changeSummary,
                $actorUserId > 0 ? $actorUserId : null,
                $actorUserId > 0 ? $actorUserId : null,
                $replacedId > 0 ? $replacedId : null,
            ]);
            $versionId = (int)$this->pdo->lastInsertId();

            $this->pdo->prepare("
                UPDATE cohort_written_test_allocations
                SET current_published_policy_version_id = ?, updated_by_user_id = ?, updated_at = UTC_TIMESTAMP()
                WHERE id = ?
            ")->execute([$versionId, $actorUserId > 0 ? $actorUserId : null, $allocationId]);

            $this->pdo->commit();
            return $this->policyVersionById($versionId) ?: [];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @return array<string,mixed>|null */
    public function currentPolicyVersionForAllocation(int $allocationId): ?array
    {
        $st = $this->pdo->prepare("
            SELECT pv.*
            FROM cohort_written_test_allocations a
            JOIN written_test_policy_versions pv ON pv.id = a.current_published_policy_version_id
            WHERE a.id = ? AND pv.version_status = 'published'
            LIMIT 1
        ");
        $st->execute([$allocationId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** @return array<string,mixed>|null */
    public function policyVersionById(int $versionId): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM written_test_policy_versions WHERE id = ? LIMIT 1');
        $st->execute([$versionId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** @return array<string,mixed>|null */
    private function allocationById(int $allocationId): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM cohort_written_test_allocations WHERE id = ? LIMIT 1');
        $st->execute([$allocationId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** @return array{source_type:string,source_id:int|null,value_text:string|null,system_policy_value_id:int|null} */
    private function resolvePolicyValueText(string $policyKey, array $scope): array
    {
        $cohortId = (int)($scope['cohort_id'] ?? 0);
        $courseId = (int)($scope['course_id'] ?? 0);

        if ($cohortId > 0) {
            $row = $this->findActivePolicyValue($policyKey, 'cohort', $cohortId);
            if ($row !== null) {
                return $row;
            }
        }

        if ($courseId > 0) {
            $row = $this->findActivePolicyValue($policyKey, 'course', $courseId);
            if ($row !== null) {
                return $row;
            }
        }

        $row = $this->findActivePolicyValue($policyKey, 'global', null);
        if ($row !== null) {
            return $row;
        }

        return [
            'source_type' => 'definition_default',
            'source_id' => null,
            'value_text' => null,
            'system_policy_value_id' => null,
        ];
    }

    /** @return array{source_type:string,source_id:int|null,value_text:string|null,system_policy_value_id:int|null}|null */
    private function findActivePolicyValue(string $policyKey, string $scopeType, ?int $scopeId): ?array
    {
        if ($scopeType === 'global') {
            $st = $this->pdo->prepare("
                SELECT id, value_text
                FROM system_policy_values
                WHERE policy_key = :policy_key
                  AND scope_type = 'global'
                  AND is_active = 1
                  AND (effective_to IS NULL OR effective_to > UTC_TIMESTAMP())
                ORDER BY effective_from DESC, id DESC
                LIMIT 1
            ");
            $st->execute([':policy_key' => $policyKey]);
        } else {
            $st = $this->pdo->prepare("
                SELECT id, value_text
                FROM system_policy_values
                WHERE policy_key = :policy_key
                  AND scope_type = :scope_type
                  AND scope_id = :scope_id
                  AND is_active = 1
                  AND (effective_to IS NULL OR effective_to > UTC_TIMESTAMP())
                ORDER BY effective_from DESC, id DESC
                LIMIT 1
            ");
            $st->execute([
                ':policy_key' => $policyKey,
                ':scope_type' => $scopeType,
                ':scope_id' => $scopeId,
            ]);
        }

        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        return [
            'source_type' => $scopeType,
            'source_id' => $scopeId,
            'value_text' => (string)$row['value_text'],
            'system_policy_value_id' => (int)$row['id'],
        ];
    }

    private function castPolicyValue(string $valueType, string $valueText): mixed
    {
        return match ($valueType) {
            'int' => (int)$valueText,
            'decimal' => (float)$valueText,
            'bool' => in_array(strtolower(trim($valueText)), ['1', 'true', 'yes', 'on'], true),
            'json' => json_decode($valueText, true) ?: [],
            default => $valueText,
        };
    }
}
