<?php
declare(strict_types=1);

require_once __DIR__ . '/BooksManualsChangeAssistantService.php';
require_once __DIR__ . '/BooksManualsContextImpactService.php';

/**
 * Small database queue with atomic leases and bounded exponential retry.
 */
final class BooksManualsChangeAssistantJobService
{
    private const LEASE_SECONDS = 600;

    public function __construct(private PDO $pdo)
    {
    }

    /** @return array<string,mixed> */
    public function enqueueAnalysis(int $projectId, int $actorUserId): array
    {
        $assistant = new BooksManualsChangeAssistantService($this->pdo);
        $project = $assistant->getProject($projectId);
        $key = hash('sha256', implode('|', array(
            'analysis-v4-context-fallback',
            $projectId,
            (string)($project['source_fingerprint'] ?? ''),
            (string)($project['scope_fingerprint'] ?? ''),
        )));
        $existing = $this->row('SELECT * FROM ipca_manual_ai_jobs WHERE idempotency_key=?', array($key));
        if ($existing !== null) {
            if (in_array((string)$existing['status'], array('queued', 'retry'), true)) {
                $this->spawnBackgroundWorker($projectId);
            }
            return $this->format($existing, true);
        }
        $dailyLimit = max(1, min(200, (int)(getenv('CW_MANUAL_AI_DAILY_JOB_LIMIT') ?: 20)));
        $limitStmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM ipca_manual_ai_jobs
             WHERE created_by=? AND created_at>=CURRENT_DATE'
        );
        $limitStmt->execute(array($actorUserId));
        if ((int)$limitStmt->fetchColumn() >= $dailyLimit) {
            throw new RuntimeException('Daily AI analysis limit reached. Continue tomorrow or ask an administrator to adjust the limit.');
        }
        $this->pdo->prepare(
            "INSERT INTO ipca_manual_ai_jobs
             (project_id,job_type,idempotency_key,status,created_by)
             VALUES (?,'analysis',?,'queued',?)"
        )->execute(array($projectId, $key, $actorUserId));
        $job = $this->row('SELECT * FROM ipca_manual_ai_jobs WHERE id=?', array((int)$this->pdo->lastInsertId()));
        if ($job === null) {
            throw new RuntimeException('Analysis job could not be created.');
        }
        $spawned = $this->spawnBackgroundWorker($projectId);
        return $this->format($job, false) + array('worker_spawned' => $spawned);
    }

    /** @param list<int> $impactAreaIds @return array<string,mixed> */
    public function enqueueComposition(int $projectId, array $impactAreaIds, int $actorUserId): array
    {
        $assistant = new BooksManualsChangeAssistantService($this->pdo);
        $project = $assistant->getProject($projectId);
        $impactAreaIds = array_values(array_unique(array_filter(
            array_map('intval', $impactAreaIds),
            static fn(int $id): bool => $id > 0
        )));
        if ($impactAreaIds === array()) {
            throw new InvalidArgumentException('Approve and select at least one impact area before composing amendments.');
        }
        sort($impactAreaIds, SORT_NUMERIC);
        $key = hash('sha256', implode('|', array(
            'compose-v1-context-impact',
            $projectId,
            (string)($project['source_fingerprint'] ?? ''),
            (string)($project['scope_fingerprint'] ?? ''),
            implode(',', $impactAreaIds),
        )));
        $existing = $this->row('SELECT * FROM ipca_manual_ai_jobs WHERE idempotency_key=?', array($key));
        if ($existing !== null) {
            if (in_array((string)$existing['status'], array('queued', 'retry'), true)) {
                $this->spawnBackgroundWorker($projectId);
            }
            return $this->format($existing, true);
        }
        $dailyLimit = max(1, min(200, (int)(getenv('CW_MANUAL_AI_DAILY_JOB_LIMIT') ?: 20)));
        $limitStmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM ipca_manual_ai_jobs
             WHERE created_by=? AND created_at>=CURRENT_DATE'
        );
        $limitStmt->execute(array($actorUserId));
        if ((int)$limitStmt->fetchColumn() >= $dailyLimit) {
            throw new RuntimeException('Daily AI job limit reached. Continue tomorrow or ask an administrator to adjust the limit.');
        }
        $payload = json_encode(
            array('impact_area_ids' => $impactAreaIds),
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
        $this->pdo->prepare(
            "INSERT INTO ipca_manual_ai_jobs
             (project_id,job_type,idempotency_key,status,result_json,created_by)
             VALUES (?,'compose',?,'queued',?,?)"
        )->execute(array($projectId, $key, $payload, $actorUserId));
        $job = $this->row('SELECT * FROM ipca_manual_ai_jobs WHERE id=?', array((int)$this->pdo->lastInsertId()));
        if ($job === null) {
            throw new RuntimeException('Amendment composition job could not be created.');
        }
        $spawned = $this->spawnBackgroundWorker($projectId);
        return $this->format($job, false) + array('worker_spawned' => $spawned);
    }

    /** @return array<string,mixed>|null */
    public function status(int $projectId): ?array
    {
        $job = $this->row(
            'SELECT * FROM ipca_manual_ai_jobs WHERE project_id=? ORDER BY id DESC LIMIT 1',
            array($projectId)
        );
        return $job === null ? null : $this->format($job, true);
    }

    /**
     * Claim and process one eligible job.
     * @return array{processed:bool,done:bool,job_id?:int,status?:string,error?:string}
     */
    public function processOne(?int $projectId = null): array
    {
        $job = $this->claim($projectId);
        if ($job === null) {
            return array('processed' => false, 'done' => true);
        }
        $jobId = (int)$job['id'];
        $lease = (string)$job['lease_token'];
        $runId = 'manual-ai-job-' . $jobId . '-' . substr($lease, 0, 8);
        try {
            $this->pdo->prepare(
                'UPDATE ipca_manual_ai_jobs SET ai_run_id=?,progress_percent=10 WHERE id=? AND lease_token=?'
            )->execute(array($runId, $jobId, $lease));
            $this->pdo->prepare(
                "UPDATE ipca_manual_ai_projects SET status='analyzing',updated_at=CURRENT_TIMESTAMP WHERE id=?"
            )->execute(array((int)$job['project_id']));
            $progress = function (int $percent, string $stage = '') use ($jobId, $lease): void {
                $this->pdo->prepare(
                    'UPDATE ipca_manual_ai_jobs
                     SET progress_percent=?,lease_expires_at=(CURRENT_TIMESTAMP + INTERVAL '
                     . self::LEASE_SECONDS . ' SECOND) WHERE id=? AND lease_token=?'
                )->execute(array(max(10, min(95, $percent)), $jobId, $lease));
            };
            $engine = new BooksManualsContextImpactService($this->pdo);
            if ((string)($job['job_type'] ?? 'analysis') === 'compose') {
                $request = json_decode((string)($job['result_json'] ?? ''), true);
                $impactAreaIds = is_array($request) && is_array($request['impact_area_ids'] ?? null)
                    ? array_map('intval', $request['impact_area_ids'])
                    : array();
                $result = $engine->compose(
                    (int)$job['project_id'],
                    $impactAreaIds,
                    (int)($job['created_by'] ?? 0),
                    $progress
                );
            } else {
                $result = $engine->analyze(
                    (int)$job['project_id'],
                    $runId,
                    (int)($job['created_by'] ?? 0),
                    $progress
                );
            }
            $stmt = $this->pdo->prepare(
                "UPDATE ipca_manual_ai_jobs
                 SET status='completed',progress_percent=100,result_json=?,completed_at=CURRENT_TIMESTAMP,
                     lease_token=NULL,lease_expires_at=NULL,error_message=NULL
                 WHERE id=? AND lease_token=?"
            );
            $stmt->execute(array(
                json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                $jobId, $lease,
            ));
            $this->pdo->prepare(
                "UPDATE ipca_manual_ai_projects SET status='completed',updated_at=CURRENT_TIMESTAMP WHERE id=?"
            )->execute(array((int)$job['project_id']));
            return array('processed' => true, 'done' => true, 'job_id' => $jobId, 'status' => 'completed');
        } catch (Throwable $e) {
            $attempt = (int)$job['attempt_count'];
            $max = (int)$job['max_attempts'];
            $terminal = $attempt >= $max;
            $delay = min(3600, 30 * (2 ** max(0, $attempt - 1)));
            $sql = $terminal
                ? "UPDATE ipca_manual_ai_jobs
                   SET status='failed',error_message=?,completed_at=CURRENT_TIMESTAMP,
                       lease_token=NULL,lease_expires_at=NULL WHERE id=? AND lease_token=?"
                : "UPDATE ipca_manual_ai_jobs
                   SET status='retry',error_message=?,available_at=(CURRENT_TIMESTAMP + INTERVAL {$delay} SECOND),
                       lease_token=NULL,lease_expires_at=NULL WHERE id=? AND lease_token=?";
            $this->pdo->prepare($sql)->execute(array(mb_substr($e->getMessage(), 0, 4000), $jobId, $lease));
            $this->pdo->prepare(
                'UPDATE ipca_manual_ai_projects SET status=? WHERE id=?'
            )->execute(array($terminal ? 'failed' : 'ready', (int)$job['project_id']));
            if (!$terminal) {
                $this->spawnBackgroundWorker((int)$job['project_id'], $delay);
            }
            return array(
                'processed' => true,
                'done' => $terminal,
                'job_id' => $jobId,
                'status' => $terminal ? 'failed' : 'retry',
                'error' => $e->getMessage(),
            );
        }
    }

    /** @return array<string,mixed>|null */
    private function claim(?int $projectId): ?array
    {
        $this->pdo->beginTransaction();
        try {
            $params = array();
            $filter = '';
            if ($projectId !== null && $projectId > 0) {
                $filter = ' AND project_id=?';
                $params[] = $projectId;
            }
            $stmt = $this->pdo->prepare(
                "SELECT * FROM ipca_manual_ai_jobs
                 WHERE status IN ('queued','retry')
                   AND available_at<=CURRENT_TIMESTAMP
                   AND (lease_expires_at IS NULL OR lease_expires_at<CURRENT_TIMESTAMP)"
                . $filter . ' ORDER BY id LIMIT 1 FOR UPDATE'
            );
            $stmt->execute($params);
            $job = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($job)) {
                $this->pdo->commit();
                return null;
            }
            $lease = $this->uuid();
            $this->pdo->prepare(
                "UPDATE ipca_manual_ai_jobs
                 SET status='running',attempt_count=attempt_count+1,lease_token=?,
                     lease_expires_at=(CURRENT_TIMESTAMP + INTERVAL " . self::LEASE_SECONDS . " SECOND),
                     started_at=COALESCE(started_at,CURRENT_TIMESTAMP),progress_percent=5
                 WHERE id=?"
            )->execute(array($lease, (int)$job['id']));
            $this->pdo->commit();
            $job['lease_token'] = $lease;
            $job['attempt_count'] = (int)$job['attempt_count'] + 1;
            return $job;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function spawnBackgroundWorker(int $projectId, int $delaySeconds = 0): bool
    {
        $script = realpath(__DIR__ . '/../../scripts/books_manuals_change_assistant_worker.php');
        if ($script === false) {
            return false;
        }
        $logDir = __DIR__ . '/../../storage/logs';
        if (!is_dir($logDir) && !@mkdir($logDir, 0770, true) && !is_dir($logDir)) {
            return false;
        }
        $logFile = $logDir . '/manual_change_assistant_' . $projectId . '.log';
        $php = $this->cliPhpBinary();
        $delaySeconds = max(0, min(3600, $delaySeconds));
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $prefix = $delaySeconds > 0 ? 'timeout /T ' . $delaySeconds . ' /NOBREAK >NUL & ' : '';
            $command = 'start /B "" ' . $prefix . escapeshellarg($php) . ' ' . escapeshellarg($script)
                . ' --once --project-id=' . $projectId . ' >> ' . escapeshellarg($logFile) . ' 2>&1';
        } else {
            $prefix = $delaySeconds > 0 ? 'sleep ' . $delaySeconds . '; ' : '';
            $command = '(' . $prefix . escapeshellarg($php) . ' ' . escapeshellarg($script)
                . ' --once --project-id=' . $projectId . ')'
                . ' >> ' . escapeshellarg($logFile) . ' 2>&1 &';
        }
        exec($command);
        return true;
    }

    private function cliPhpBinary(): string
    {
        $configured = trim((string)(getenv('CW_PHP_CLI') ?: ''));
        $candidates = array_filter(array(
            $configured,
            PHP_BINDIR !== '' ? PHP_BINDIR . DIRECTORY_SEPARATOR . 'php' : '',
            '/usr/bin/php',
            '/usr/local/bin/php',
            PHP_BINARY,
        ));
        foreach ($candidates as $candidate) {
            $name = strtolower(basename((string)$candidate));
            if (str_contains($name, 'fpm') || str_contains($name, 'cgi')) {
                continue;
            }
            if (is_file($candidate) && is_executable($candidate)) {
                return (string)$candidate;
            }
        }
        return 'php';
    }

    /** @return array<string,mixed> */
    private function format(array $job, bool $idempotent): array
    {
        foreach (array('result_json') as $key) {
            if (isset($job[$key]) && is_string($job[$key])) {
                $decoded = json_decode($job[$key], true);
                $job[$key] = is_array($decoded) ? $decoded : null;
            }
        }
        $job['id'] = (int)$job['id'];
        $job['project_id'] = (int)$job['project_id'];
        $job['progress_percent'] = (int)$job['progress_percent'];
        $job['idempotent'] = $idempotent;
        return $job;
    }

    /** @return array<string,mixed>|null */
    private function row(string $sql, array $params): ?array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4),
            substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20));
    }
}
