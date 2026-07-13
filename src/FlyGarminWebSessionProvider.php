<?php
declare(strict_types=1);

require_once __DIR__ . '/GarminFlightDataProviderInterface.php';
require_once __DIR__ . '/GarminProviderResult.php';

final class FlyGarminWebSessionProvider implements GarminFlightDataProviderInterface
{
    private string $providerName = 'flygarmin_web';

    public function getProviderName(): string
    {
        return $this->providerName;
    }

    public function getProviderType(): string
    {
        return 'temporary_web_session';
    }

    public function isConfigured(): bool
    {
        return $this->workerUrl() !== '' || $this->workerCommand() !== '';
    }

    /**
     * @return array<string,mixed>
     */
    public function getAuthenticationStatus(): array
    {
        $result = $this->runWorker('status', array());
        return $result->toArray();
    }

    public function isAuthenticated(): bool
    {
        $status = $this->getAuthenticationStatus();
        return !empty($status['ok']) && (string)($status['status'] ?? '') === 'authenticated';
    }

    public function requiresReauthentication(): bool
    {
        $status = $this->getAuthenticationStatus();
        return in_array((string)($status['status'] ?? ''), array('authentication_required', 'session_expired'), true);
    }

    public function testConnection(): GarminProviderResult
    {
        return $this->runWorker('test-connection', array());
    }

    public function runInitialSync(): GarminSyncResult
    {
        return new GarminSyncResult($this->runWorker('sync-initial', array()));
    }

    public function runIncrementalSync(?string $cursor): GarminSyncResult
    {
        return new GarminSyncResult($this->runWorker('sync-incremental', array('cursor' => $cursor)));
    }

    public function runFullReconciliation(): GarminSyncResult
    {
        return new GarminSyncResult($this->runWorker('sync-reconcile', array()));
    }

    public function downloadFlightDataLog(string $flightDataLogUuid): GarminDownloadResult
    {
        return new GarminDownloadResult($this->runWorker('download-source', array('flightDataLogUUID' => $flightDataLogUuid)));
    }

    public function disconnect(): void
    {
        $this->runWorker('disconnect', array());
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function runWorker(string $operation, array $payload): GarminProviderResult
    {
        if (!$this->isConfigured()) {
            return new GarminProviderResult(false, $operation, $this->providerName, 'not_configured', null, array(), array(
                'code' => 'GARMIN_WORKER_NOT_CONFIGURED',
                'message' => 'Set GARMIN_WORKER_URL for remote/server worker mode or GARMIN_WORKER_COMMAND for local CLI mode.',
            ));
        }
        $payload['operation'] = $operation;
        try {
            $raw = $this->workerUrl() !== ''
                ? $this->runHttpWorker($payload)
                : $this->runCliWorker($payload);
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                throw new RuntimeException('Garmin worker returned non-JSON output.');
            }
            return GarminProviderResult::fromWorkerPayload($decoded, $operation);
        } catch (Throwable $e) {
            return new GarminProviderResult(false, $operation, $this->providerName, 'sync_error', null, array(), array(
                'code' => 'GARMIN_WORKER_ERROR',
                'message' => $e->getMessage(),
            ));
        }
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function runHttpWorker(array $payload): string
    {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if (!is_string($body)) {
            throw new RuntimeException('Could not encode Garmin worker payload.');
        }
        $headers = "Content-Type: application/json\r\n";
        $token = (string)(getenv('GARMIN_WORKER_TOKEN') ?: '');
        if ($token !== '') {
            $headers .= "Authorization: Bearer {$token}\r\n";
        }
        $context = stream_context_create(array(
            'http' => array(
                'method' => 'POST',
                'header' => $headers,
                'content' => $body,
                'timeout' => 120,
                'ignore_errors' => true,
            ),
        ));
        $response = file_get_contents(rtrim($this->workerUrl(), '/') . '/garmin-worker', false, $context);
        if ($response === false) {
            throw new RuntimeException('Could not reach Garmin worker.');
        }
        return $response;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function runCliWorker(array $payload): string
    {
        $command = $this->workerCommand();
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if (!is_string($body)) {
            throw new RuntimeException('Could not encode Garmin worker payload.');
        }
        $descriptor = array(
            0 => array('pipe', 'r'),
            1 => array('pipe', 'w'),
            2 => array('pipe', 'w'),
        );
        $process = proc_open($command, $descriptor, $pipes, dirname(__DIR__));
        if (!is_resource($process)) {
            throw new RuntimeException('Could not start Garmin worker command.');
        }
        fwrite($pipes[0], $body);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        if ($exit !== 0) {
            throw new RuntimeException('Garmin worker command failed: ' . trim((string)$stderr));
        }
        return (string)$stdout;
    }

    private function workerUrl(): string
    {
        return trim((string)(getenv('GARMIN_WORKER_URL') ?: ''));
    }

    private function workerCommand(): string
    {
        return trim((string)(getenv('GARMIN_WORKER_COMMAND') ?: ''));
    }
}
