<?php
declare(strict_types=1);

require_once __DIR__ . '/ControlledPublishingMccfBrowserService.php';
require_once __DIR__ . '/ControlledPublishingMccfIntegrityService.php';
require_once __DIR__ . '/ControlledPublishingMccfRegulationLinkService.php';
require_once __DIR__ . '/ControlledPublishingMccfLinkedManualService.php';
require_once __DIR__ . '/ControlledPublishingBookSectionIndexService.php';

/**
 * Background MCCF integrity scoring with DB-backed progress and cached scores.
 */
final class ControlledPublishingMccfIntegrityJobService
{
    private const BATCH_SIZE = 12;
    private const STALE_MINUTES = 15;

    private ControlledPublishingMccfIntegrityService $integrity;

    public function __construct(private PDO $pdo)
    {
        $this->integrity = new ControlledPublishingMccfIntegrityService($pdo);
    }

    public static function tablesPresent(PDO $pdo): bool
    {
        try {
            $stmt = $pdo->query("SHOW TABLES LIKE 'ipca_mccf_integrity_runs'");

            return (bool)$stmt->fetchColumn();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return array{ok:bool,run_id?:int,error?:string,worker_spawned?:bool}
     */
    public function startRun(int $sourceSetId, ?int $createdBy = null): array
    {
        if (!self::tablesPresent($this->pdo)) {
            return array('ok' => false, 'error' => 'Apply scripts/sql/2026_06_17_mccf_integrity_jobs.sql first.');
        }
        if ($sourceSetId <= 0) {
            return array('ok' => false, 'error' => 'source_set_id is required.');
        }

        $this->markStaleRunsFailed($sourceSetId);

        $active = $this->findActiveRun($sourceSetId);
        if ($active !== null) {
            return array(
                'ok' => true,
                'run_id' => (int)$active['id'],
                'worker_spawned' => $this->spawnBackgroundProcessor((int)$active['id']),
            );
        }

        $total = $this->countRequirements($sourceSetId);
        $stmt = $this->pdo->prepare("
            INSERT INTO ipca_mccf_integrity_runs (
              source_set_id, status, total_count, processed_count, created_by
            ) VALUES (
              :source_set_id, 'queued', :total_count, 0, :created_by
            )
        ");
        $stmt->execute(array(
            ':source_set_id' => $sourceSetId,
            ':total_count' => $total,
            ':created_by' => $createdBy,
        ));
        $runId = (int)$this->pdo->lastInsertId();

        return array(
            'ok' => true,
            'run_id' => $runId,
            'worker_spawned' => $this->spawnBackgroundProcessor($runId),
        );
    }

    /**
     * @return array{ok:bool,done:bool,processed_count?:int,total_count?:int,error?:string}
     */
    public function processBatch(int $runId, int $batchSize = self::BATCH_SIZE): array
    {
        if (!self::tablesPresent($this->pdo)) {
            return array('ok' => false, 'done' => true, 'error' => 'Integrity job tables missing.');
        }

        $run = $this->loadRun($runId);
        if ($run === null) {
            return array('ok' => false, 'done' => true, 'error' => 'Run not found.');
        }

        $status = (string)($run['status'] ?? '');
        if ($status === 'completed' || $status === 'cancelled') {
            return array(
                'ok' => true,
                'done' => true,
                'processed_count' => (int)($run['processed_count'] ?? 0),
                'total_count' => (int)($run['total_count'] ?? 0),
            );
        }
        if ($status === 'failed') {
            return array('ok' => false, 'done' => true, 'error' => (string)($run['error_message'] ?? 'Run failed.'));
        }

        if ($status === 'queued') {
            $this->pdo->prepare("
                UPDATE ipca_mccf_integrity_runs
                SET status = 'running',
                    started_at = COALESCE(started_at, CURRENT_TIMESTAMP),
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :id
            ")->execute(array(':id' => $runId));
        }

        $sourceSetId = (int)($run['source_set_id'] ?? 0);
        $offset = (int)($run['processed_count'] ?? 0);
        $total = (int)($run['total_count'] ?? 0);
        if ($total <= 0) {
            $total = $this->countRequirements($sourceSetId);
        }

        if ($offset >= $total) {
            $this->finishRun($runId, 'completed');

            return array('ok' => true, 'done' => true, 'processed_count' => $total, 'total_count' => $total);
        }

        try {
            $rows = $this->loadRequirementBatch($sourceSetId, $offset, max(1, min(50, $batchSize)));
            if ($rows === array()) {
                $this->finishRun($runId, 'completed');

                return array('ok' => true, 'done' => true, 'processed_count' => $total, 'total_count' => $total);
            }

            $reqIds = array_map(static fn(array $row): int => (int)$row['id'], $rows);
            $scores = $this->scoreRequirements($rows, $reqIds, $sourceSetId);
            $this->persistScores($sourceSetId, $runId, $scores);

            $processed = $offset + count($rows);
            $this->pdo->prepare("
                UPDATE ipca_mccf_integrity_runs
                SET processed_count = :processed_count,
                    total_count = :total_count,
                    status = 'running',
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :id
            ")->execute(array(
                ':processed_count' => $processed,
                ':total_count' => max($total, $processed),
                ':id' => $runId,
            ));

            $done = $processed >= max($total, $processed);
            if ($done) {
                $this->finishRun($runId, 'completed');
            }

            return array(
                'ok' => true,
                'done' => $done,
                'processed_count' => $processed,
                'total_count' => max($total, $processed),
            );
        } catch (Throwable $e) {
            $this->pdo->prepare("
                UPDATE ipca_mccf_integrity_runs
                SET status = 'failed',
                    error_message = :error_message,
                    completed_at = CURRENT_TIMESTAMP,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :id
            ")->execute(array(
                ':id' => $runId,
                ':error_message' => mb_substr($e->getMessage(), 0, 2000),
            ));

            return array('ok' => false, 'done' => true, 'error' => $e->getMessage());
        }
    }

    /**
     * @return array{ok:bool,run?:array<string,mixed>}
     */
    public function statusForSourceSet(int $sourceSetId): array
    {
        if (!self::tablesPresent($this->pdo) || $sourceSetId <= 0) {
            return array('ok' => true, 'run' => null);
        }

        $this->markStaleRunsFailed($sourceSetId);
        $run = $this->findLatestRun($sourceSetId);

        return array('ok' => true, 'run' => $run !== null ? $this->formatRun($run) : null);
    }

    /**
     * @param list<int> $requirementIds
     * @return array<int,array{score:int,label:string,tone:string,reasons:list<string>}>
     */
    public function getCachedScores(array $requirementIds): array
    {
        $requirementIds = array_values(array_filter(array_map('intval', $requirementIds), static fn(int $id): bool => $id > 0));
        if ($requirementIds === array() || !self::tablesPresent($this->pdo)) {
            return array();
        }

        $in = implode(',', $requirementIds);
        $stmt = $this->pdo->query("
            SELECT requirement_id, score, label, tone, reasons_json
            FROM ipca_mccf_integrity_scores
            WHERE requirement_id IN ({$in})
        ");

        $scores = array();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: array() as $row) {
            $rid = (int)($row['requirement_id'] ?? 0);
            if ($rid <= 0) {
                continue;
            }
            $reasons = json_decode((string)($row['reasons_json'] ?? '[]'), true);

            $scores[$rid] = array(
                'score' => (int)($row['score'] ?? 0),
                'label' => (string)($row['label'] ?? ''),
                'tone' => (string)($row['tone'] ?? 'muted'),
                'reasons' => is_array($reasons) ? $reasons : array(),
            );
        }

        return $scores;
    }

    /**
     * @return array{ok:bool,error?:string}
     */
    public function cancelRun(int $runId): array
    {
        $run = $this->loadRun($runId);
        if ($run === null) {
            return array('ok' => false, 'error' => 'Run not found.');
        }
        if (!in_array((string)($run['status'] ?? ''), array('queued', 'running'), true)) {
            return array('ok' => false, 'error' => 'Run is not active.');
        }

        $this->pdo->prepare("
            UPDATE ipca_mccf_integrity_runs
            SET status = 'cancelled',
                completed_at = CURRENT_TIMESTAMP,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ")->execute(array(':id' => $runId));

        return array('ok' => true);
    }

    public function spawnBackgroundProcessor(int $runId): bool
    {
        if ($runId <= 0) {
            return false;
        }

        $php = PHP_BINARY !== '' ? PHP_BINARY : 'php';
        $script = realpath(__DIR__ . '/../../scripts/run_mccf_integrity_job.php');
        if ($script === false) {
            return false;
        }

        $logDir = realpath(__DIR__ . '/../../storage/logs');
        if ($logDir === false) {
            $candidate = __DIR__ . '/../../storage/logs';
            if (!is_dir($candidate)) {
                @mkdir($candidate, 0775, true);
            }
            $logDir = realpath($candidate) ?: sys_get_temp_dir();
        }
        $logFile = $logDir . '/mccf_integrity_run_' . $runId . '.log';

        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $cmd = 'start /B "" '
                . escapeshellarg($php) . ' '
                . escapeshellarg($script) . ' '
                . '--run-id=' . $runId
                . ' >> ' . escapeshellarg($logFile) . ' 2>&1';
        } else {
            $cmd = escapeshellarg($php) . ' '
                . escapeshellarg($script) . ' '
                . '--run-id=' . $runId
                . ' >> ' . escapeshellarg($logFile) . ' 2>&1 &';
        }

        exec($cmd);

        return true;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @param list<int> $requirementIds
     * @return array<int,array{score:int,label:string,tone:string,reasons:list<string>}>
     */
    private function scoreRequirements(array $rows, array $requirementIds, int $sourceSetId): array
    {
        $linkedManual = new ControlledPublishingMccfLinkedManualService($this->pdo);
        $excerptMap = $linkedManual->linkedSectionsForRequirements($requirementIds);
        $regLinkMap = $this->loadRegulationLinks($requirementIds);

        $bookVersionId = 0;
        if ($rows !== array()) {
            $bookVersionId = $this->resolveBookVersionId((string)($rows[0]['manual_code'] ?? 'OM'));
        }

        $sectionRefs = array();
        foreach ($excerptMap as $sections) {
            foreach ($sections as $section) {
                $ref = rtrim(trim((string)($section['section_ref'] ?? '')), '.');
                if ($ref !== '') {
                    $sectionRefs[] = $ref;
                }
            }
        }
        if ($bookVersionId > 0 && $sectionRefs !== array()) {
            $index = new ControlledPublishingBookSectionIndexService($this->pdo);
            foreach (array_unique($sectionRefs) as $sectionRef) {
                $index->plainTextForSectionRefs($bookVersionId, array($sectionRef), true);
            }
        }

        $scores = array();
        foreach ($rows as $row) {
            $rid = (int)($row['id'] ?? 0);
            if ($rid <= 0) {
                continue;
            }
            $manualCode = strtoupper(trim((string)($row['manual_code'] ?? 'OM')));
            $rowBookVersionId = $this->resolveBookVersionId($manualCode);
            if ($rowBookVersionId <= 0) {
                $rowBookVersionId = $bookVersionId;
            }

            $result = $this->integrity->scoreRequirement(
                $row,
                $excerptMap[$rid] ?? array(),
                $regLinkMap[$rid] ?? array(),
                $rowBookVersionId
            );
            $scores[$rid] = array(
                'score' => (int)($result['score'] ?? 0),
                'label' => (string)($result['label'] ?? ''),
                'tone' => (string)($result['tone'] ?? 'muted'),
                'reasons' => is_array($result['reasons'] ?? null) ? $result['reasons'] : array(),
            );
        }

        unset($sourceSetId);

        return $scores;
    }

    /**
     * @param array<int,array{score:int,label:string,tone:string,reasons:list<string>}> $scores
     */
    private function persistScores(int $sourceSetId, int $runId, array $scores): void
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO ipca_mccf_integrity_scores (
              requirement_id, source_set_id, run_id, score, label, tone, reasons_json, scored_at
            ) VALUES (
              :requirement_id, :source_set_id, :run_id, :score, :label, :tone, :reasons_json, CURRENT_TIMESTAMP
            )
            ON DUPLICATE KEY UPDATE
              source_set_id = VALUES(source_set_id),
              run_id = VALUES(run_id),
              score = VALUES(score),
              label = VALUES(label),
              tone = VALUES(tone),
              reasons_json = VALUES(reasons_json),
              scored_at = CURRENT_TIMESTAMP,
              updated_at = CURRENT_TIMESTAMP
        ");

        foreach ($scores as $requirementId => $score) {
            $stmt->execute(array(
                ':requirement_id' => (int)$requirementId,
                ':source_set_id' => $sourceSetId,
                ':run_id' => $runId,
                ':score' => (int)($score['score'] ?? 0),
                ':label' => (string)($score['label'] ?? ''),
                ':tone' => (string)($score['tone'] ?? 'muted'),
                ':reasons_json' => json_encode($score['reasons'] ?? array(), JSON_UNESCAPED_UNICODE),
            ));
        }
    }

    /**
     * @param list<int> $requirementIds
     * @return array<int,list<array<string,mixed>>>
     */
    private function loadRegulationLinks(array $requirementIds): array
    {
        if ($requirementIds === array()
            || !ControlledPublishingMccfRegulationLinkService::regulationLinksTablePresent($this->pdo)) {
            return array();
        }

        $in = implode(',', array_map('intval', $requirementIds));
        try {
            $stmt = $this->pdo->query("
                SELECT requirement_id, rule_token, match_confidence, target_batch_id, target_node_uid
                FROM ipca_canonical_requirement_regulation_links
                WHERE requirement_id IN ({$in}) AND source_status = 'active'
                ORDER BY requirement_id, rule_token
            ");
        } catch (Throwable) {
            return array();
        }

        $map = array();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: array() as $row) {
            $rid = (int)$row['requirement_id'];
            unset($row['requirement_id']);
            $map[$rid][] = $row;
        }

        return $map;
    }

    private function resolveBookVersionId(string $manualCode): int
    {
        $manualCode = strtoupper(trim($manualCode));
        if ($manualCode === '') {
            $manualCode = 'OM';
        }
        $rev = $manualCode === 'OMM' ? '4.0' : '6.0';

        try {
            $stmt = $this->pdo->prepare("
                SELECT bv.id
                FROM ipca_publishing_book_versions bv
                INNER JOIN ipca_publishing_books b ON b.id = bv.book_id
                WHERE b.book_key = :book_key
                  AND bv.version_label = :version_label
                ORDER BY bv.id DESC
                LIMIT 1
            ");
            $stmt->execute(array(
                ':book_key' => $manualCode,
                ':version_label' => $rev,
            ));

            return (int)$stmt->fetchColumn();
        } catch (Throwable) {
            return 0;
        }
    }

    private function countRequirements(int $sourceSetId): int
    {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*)
            FROM ipca_canonical_requirements
            WHERE source_set_id = :source_set_id
              AND source_status = 'active'
        ");
        $stmt->execute(array(':source_set_id' => $sourceSetId));

        return (int)$stmt->fetchColumn();
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function loadRequirementBatch(int $sourceSetId, int $offset, int $limit): array
    {
        $stmt = $this->pdo->prepare("
            SELECT *
            FROM ipca_canonical_requirements
            WHERE source_set_id = :source_set_id
              AND source_status = 'active'
            ORDER BY manual_part, item_no, sub_item_no, requirement_key
            LIMIT :limit OFFSET :offset
        ");
        $stmt->bindValue(':source_set_id', $sourceSetId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: array();
    }

    /**
     * @return array<string,mixed>|null
     */
    private function loadRun(int $runId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ipca_mccf_integrity_runs WHERE id = :id LIMIT 1');
        $stmt->execute(array(':id' => $runId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function findActiveRun(int $sourceSetId): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT *
            FROM ipca_mccf_integrity_runs
            WHERE source_set_id = :source_set_id
              AND status IN ('queued', 'running')
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->execute(array(':source_set_id' => $sourceSetId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function findLatestRun(int $sourceSetId): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT *
            FROM ipca_mccf_integrity_runs
            WHERE source_set_id = :source_set_id
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->execute(array(':source_set_id' => $sourceSetId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    private function markStaleRunsFailed(int $sourceSetId): void
    {
        $this->pdo->prepare("
            UPDATE ipca_mccf_integrity_runs
            SET status = 'failed',
                error_message = 'Run interrupted or timed out.',
                completed_at = CURRENT_TIMESTAMP,
                updated_at = CURRENT_TIMESTAMP
            WHERE source_set_id = :source_set_id
              AND status IN ('queued', 'running')
              AND updated_at < (CURRENT_TIMESTAMP - INTERVAL " . self::STALE_MINUTES . " MINUTE)
        ")->execute(array(':source_set_id' => $sourceSetId));
    }

    private function finishRun(int $runId, string $status): void
    {
        $this->pdo->prepare("
            UPDATE ipca_mccf_integrity_runs
            SET status = :status,
                processed_count = total_count,
                completed_at = CURRENT_TIMESTAMP,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ")->execute(array(
            ':status' => $status,
            ':id' => $runId,
        ));
    }

    /**
     * @param array<string,mixed> $run
     * @return array<string,mixed>
     */
    private function formatRun(array $run): array
    {
        $total = max(0, (int)($run['total_count'] ?? 0));
        $processed = max(0, (int)($run['processed_count'] ?? 0));
        if ($total > 0 && $processed > $total) {
            $processed = $total;
        }

        return array(
            'id' => (int)($run['id'] ?? 0),
            'source_set_id' => (int)($run['source_set_id'] ?? 0),
            'status' => (string)($run['status'] ?? ''),
            'total_count' => $total,
            'processed_count' => $processed,
            'percent' => $total > 0 ? (int)round(($processed / $total) * 100) : 0,
            'started_at' => (string)($run['started_at'] ?? ''),
            'completed_at' => (string)($run['completed_at'] ?? ''),
            'updated_at' => (string)($run['updated_at'] ?? ''),
            'error_message' => (string)($run['error_message'] ?? ''),
            'is_active' => in_array((string)($run['status'] ?? ''), array('queued', 'running'), true),
        );
    }
}
