<?php
declare(strict_types=1);

require_once __DIR__ . '/EvidenceSchema.php';
require_once __DIR__ . '/ProcessingRunRepository.php';

/**
 * Heartbeat updates during an active evidence worker run.
 */
final class ProcessingRunLifecycleService
{
    public function __construct(private readonly ProcessingRunRepository $processingRuns)
    {
    }

    public static function fromPdo(PDO $pdo): self
    {
        return new self(new ProcessingRunRepository($pdo));
    }

    public function beginExecution(int $runId): ProcessingRunExecution
    {
        $this->processingRuns->touchHeartbeat($runId, 'starting');
        return new ProcessingRunExecution($this->processingRuns, $runId);
    }
}

final class ProcessingRunExecution
{
    private bool $finished = false;

    public function __construct(
        private readonly ProcessingRunRepository $processingRuns,
        private readonly int $runId,
    ) {
    }

    public function heartbeat(string $phase): void
    {
        if ($this->finished || $this->runId <= 0) {
            return;
        }
        $this->processingRuns->touchHeartbeat($this->runId, $phase);
    }

    public function complete(): void
    {
        if ($this->finished || $this->runId <= 0) {
            return;
        }
        $this->finished = true;
        $this->processingRuns->markCompleted($this->runId);
    }

    public function fail(string $reason): void
    {
        if ($this->finished || $this->runId <= 0) {
            return;
        }
        $this->finished = true;
        $this->processingRuns->markFailed($this->runId, $reason);
    }

    public function runId(): int
    {
        return $this->runId;
    }

    public function __destruct()
    {
        if (!$this->finished && $this->runId > 0) {
            $this->processingRuns->markFailed($this->runId, 'worker_terminated');
        }
    }
}
