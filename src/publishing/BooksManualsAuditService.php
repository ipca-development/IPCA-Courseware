<?php
declare(strict_types=1);

require_once __DIR__ . '/ControlledPublishingFoundationService.php';
require_once __DIR__ . '/ControlledPublishingMccfIntegrityJobService.php';
require_once __DIR__ . '/ControlledPublishingReaderService.php';
require_once __DIR__ . '/BooksManualsWorkflowService.php';

final class BooksManualsAuditService
{
    public function __construct(
        private PDO $pdo,
        private ?ControlledPublishingFoundationService $foundation = null
    ) {
        $this->foundation ??= new ControlledPublishingFoundationService($pdo);
    }

    /**
     * @return array<string,mixed>
     */
    public function liveCoverage(int $versionId): array
    {
        $version = $this->foundation->getVersion($versionId);
        if ($version === null) {
            throw new RuntimeException('Manual version not found.');
        }
        $sourceSets = $this->foundation->getVersionSourceSelections($versionId);
        $requiredSourceSets = array();
        $requirements = array();
        foreach ($sourceSets as $sourceSet) {
            if (empty($sourceSet['is_required_for_release'])) {
                continue;
            }
            $requiredSourceSets[] = array(
                'source_set_id' => (int)$sourceSet['source_set_id'],
                'source_set_key' => (string)($sourceSet['source_set_key'] ?? ''),
                'source_family' => (string)($sourceSet['source_family'] ?? ''),
                'revision_label' => (string)($sourceSet['revision_label'] ?? ''),
                'source_hash' => (string)($sourceSet['source_hash'] ?? ''),
            );
            foreach ($this->requirementsForSourceSet((int)$sourceSet['source_set_id']) as $row) {
                $requirements[] = $row;
            }
        }

        $covered = 0;
        $insufficient = 0;
        $missing = 0;
        foreach ($requirements as &$requirement) {
            $score = $requirement['score'] === null ? null : (int)$requirement['score'];
            if ($score !== null && $score >= 80) {
                $requirement['coverage_state'] = 'covered';
                $covered++;
            } elseif ($score !== null && $score >= 55) {
                $requirement['coverage_state'] = 'insufficient';
                $insufficient++;
            } else {
                $requirement['coverage_state'] = 'missing';
                $missing++;
            }
        }
        unset($requirement);

        $total = count($requirements);
        $coverage = $total > 0 ? round(($covered / $total) * 100, 2) : 0.0;
        $foundationCheck = $this->foundationChecks($version);
        return array(
            'book_version_id' => $versionId,
            'total_count' => $total,
            'covered_count' => $covered,
            'insufficient_count' => $insufficient,
            'missing_count' => $missing,
            'coverage_percent' => $coverage,
            'required_source_sets' => $requiredSourceSets,
            'requirements' => $requirements,
            'source_baseline_ok' => $foundationCheck['source_baseline_ok'],
            'authoritative_pagination_ok' => $foundationCheck['authoritative_pagination_ok'],
            'foundation_errors' => $foundationCheck['errors'],
            'passed' => $total > 0
                && $insufficient === 0
                && $missing === 0
                && $foundationCheck['source_baseline_ok']
                && $foundationCheck['authoritative_pagination_ok'],
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function createSnapshot(int $versionId, int $actorUserId): array
    {
        $coverage = $this->liveCoverage($versionId);
        $identity = (new BooksManualsWorkflowService($this->pdo))->syncUpdateIdentity($versionId);
        $version = $this->foundation->getVersion($versionId);
        $routeStmt = $this->pdo->prepare(
            'SELECT approval_route FROM ipca_publishing_book_profiles WHERE book_id = ? LIMIT 1'
        );
        $routeStmt->execute(array((int)($version['book_id'] ?? 0)));
        $auditType = $routeStmt->fetchColumn() === 'authority' ? 'authority' : 'internal';
        $uuid = $this->uuidV4();
        $status = !empty($coverage['passed']) ? 'passed' : 'failed';
        $result = array(
            'schema_version' => 1,
            'captured_at' => gmdate('c'),
            'update_code' => $identity['update_code'],
            'source_fingerprint' => $identity['source_fingerprint'],
            'page_map_hash' => $identity['page_map_hash'],
            'manifest_hash' => $identity['manifest_hash'],
            'coverage' => $coverage,
        );
        $stmt = $this->pdo->prepare(
            'INSERT INTO ipca_publishing_audit_snapshots
              (snapshot_uuid, book_version_id, audit_type, status, coverage_percent,
               covered_count, insufficient_count, missing_count, result_json,
               source_fingerprint, created_by, completed_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP(3))'
        );
        $stmt->execute(array(
            $uuid,
            $versionId,
            $auditType,
            $status,
            $coverage['coverage_percent'],
            $coverage['covered_count'],
            $coverage['insufficient_count'],
            $coverage['missing_count'],
            json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            $identity['source_fingerprint'],
            $actorUserId,
        ));
        return array_merge($coverage, array(
            'snapshot_id' => (int)$this->pdo->lastInsertId(),
            'snapshot_uuid' => $uuid,
            'status' => $status,
        ));
    }

    /**
     * @return list<array{source_set_id:int,ok:bool,run_id?:int,error?:string,worker_spawned?:bool}>
     */
    public function startIntegrityRefresh(int $versionId, int $actorUserId): array
    {
        if (!ControlledPublishingMccfIntegrityJobService::tablesPresent($this->pdo)) {
            throw new RuntimeException('Install the MCCF integrity jobs migration first.');
        }
        $job = new ControlledPublishingMccfIntegrityJobService($this->pdo);
        $results = array();
        foreach ($this->foundation->getVersionSourceSelections($versionId) as $selection) {
            if (empty($selection['is_required_for_release'])) {
                continue;
            }
            $sourceSetId = (int)$selection['source_set_id'];
            $result = $job->startRun($sourceSetId, $actorUserId);
            $result['source_set_id'] = $sourceSetId;
            $results[] = $result;
        }
        return $results;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listSnapshots(int $versionId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, snapshot_uuid, audit_type, status, coverage_percent,
                    covered_count, insufficient_count, missing_count,
                    source_fingerprint, created_at, completed_at
             FROM ipca_publishing_audit_snapshots
             WHERE book_version_id = ? ORDER BY id DESC'
        );
        $stmt->execute(array($versionId));
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: array();
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listComplianceOverrides(int $versionId): array
    {
        $present = (int)$this->pdo->query(
            "SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'ipca_publishing_compliance_overrides'"
        )->fetchColumn() === 1;
        if (!$present) {
            return array();
        }
        $stmt = $this->pdo->prepare(
            'SELECT o.id, o.override_uuid, o.override_scope, o.rationale,
                    o.blockers_json, o.source_fingerprint, o.created_at,
                    u.name AS actor_name, u.email AS actor_email
             FROM ipca_publishing_compliance_overrides o
             LEFT JOIN users u ON u.id = o.actor_user_id
             WHERE o.book_version_id = ?
             ORDER BY o.id DESC'
        );
        $stmt->execute(array($versionId));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: array();
        foreach ($rows as &$row) {
            $blockers = json_decode((string)($row['blockers_json'] ?? '{}'), true);
            $row['blockers'] = is_array($blockers) ? $blockers : array();
            unset($row['blockers_json']);
        }
        unset($row);
        return $rows;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function requirementsForSourceSet(int $sourceSetId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT r.id, r.source_set_id, r.requirement_key, r.manual_code,
                    r.manual_part, r.subject, r.regulation_ref, r.manual_section_ref,
                    r.applicable, s.score, s.label, s.tone, s.reasons_json, s.scored_at
             FROM ipca_canonical_requirements r
             LEFT JOIN ipca_mccf_integrity_scores s ON s.requirement_id = r.id
             WHERE r.source_set_id = ? AND r.source_status = 'active'
             ORDER BY r.manual_part, r.item_no, r.sub_item_no, r.id"
        );
        $stmt->execute(array($sourceSetId));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: array();
        foreach ($rows as &$row) {
            $reasons = json_decode((string)($row['reasons_json'] ?? '[]'), true);
            $row['reasons'] = is_array($reasons) ? $reasons : array();
            unset($row['reasons_json']);
        }
        unset($row);
        return $rows;
    }

    /**
     * @param array<string,mixed> $version
     * @return array{source_baseline_ok:bool,authoritative_pagination_ok:bool,errors:list<string>}
     */
    private function foundationChecks(array $version): array
    {
        $errors = array();
        $sourceOk = (string)($version['baseline_status'] ?? '') === 'frozen'
            && trim((string)($version['baseline_hash'] ?? '')) !== '';
        if (!$sourceOk) {
            $errors[] = 'A frozen source baseline is required.';
        }
        $paginationOk = false;
        try {
            (new ControlledPublishingReaderService($this->pdo))
                ->assertAuthoritativePageMapReadyForRelease($version);
            $paginationOk = true;
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
        return array(
            'source_baseline_ok' => $sourceOk,
            'authoritative_pagination_ok' => $paginationOk,
            'errors' => $errors,
        );
    }

    private function uuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20)
        );
    }
}
