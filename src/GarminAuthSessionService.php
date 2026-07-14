<?php
declare(strict_types=1);

require_once __DIR__ . '/AuditEventService.php';
require_once __DIR__ . '/GarminProviderStateService.php';

final class GarminAuthSessionService
{
    private const ALLOWED_ACTIONS = array('start', 'status', 'verify', 'stop');

    public function __construct(private PDO $pdo, private string $providerName = 'flygarmin_web')
    {
    }

    /**
     * @return array<string,mixed>
     */
    public function start(?int $actorUserId = null): array
    {
        return $this->run('start', 'garmin_auth_session_started', $actorUserId);
    }

    /**
     * @return array<string,mixed>
     */
    public function reauthenticate(?int $actorUserId = null): array
    {
        return $this->run('start', 'garmin_auth_session_reauthentication_started', $actorUserId);
    }

    /**
     * @return array<string,mixed>
     */
    public function status(?int $actorUserId = null): array
    {
        return $this->run('status', 'garmin_auth_session_status_checked', $actorUserId);
    }

    /**
     * @return array<string,mixed>
     */
    public function complete(?int $actorUserId = null): array
    {
        $result = $this->run('verify', 'garmin_auth_session_verification_attempted', $actorUserId);
        if (($result['status'] ?? '') === 'authenticated') {
            $state = new GarminProviderStateService($this->pdo, $this->providerName);
            $state->updateState(array(
                'enabled' => 1,
                'authentication_status' => 'authenticated',
                'connection_status' => 'healthy',
                'reauthentication_required' => 0,
                'browser_profile_present' => 1,
                'worker_reachable' => 1,
                'last_authenticated_at' => gmdate('Y-m-d H:i:s.v'),
                'last_connection_test_at' => gmdate('Y-m-d H:i:s.v'),
                'last_error_code' => null,
                'last_error_summary' => null,
            ), 'garmin_authentication_verified', $actorUserId);
            $state->markAcceptanceCheck('worker_authentication_test', true, 'Garmin authentication verified through temporary headed browser session.');
        }
        return $result;
    }

    /**
     * @return array<string,mixed>
     */
    public function cancel(?int $actorUserId = null): array
    {
        return $this->run('stop', 'garmin_auth_session_cancelled', $actorUserId);
    }

    /**
     * @return array<string,mixed>
     */
    private function run(string $action, string $auditAction, ?int $actorUserId): array
    {
        if (!in_array($action, self::ALLOWED_ACTIONS, true)) {
            throw new InvalidArgumentException('Unsupported Garmin auth session action.');
        }
        $helper = $this->helperPath();
        if (!is_file($helper)) {
            throw new RuntimeException('Garmin auth helper is missing.');
        }
        $command = array('sudo', '-n', $helper, $action);
        $descriptor = array(
            0 => array('pipe', 'r'),
            1 => array('pipe', 'w'),
            2 => array('pipe', 'w'),
        );
        $process = proc_open($command, $descriptor, $pipes, dirname(__DIR__));
        if (!is_resource($process)) {
            throw new RuntimeException('Could not start Garmin auth helper.');
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        $decoded = json_decode((string)$stdout, true);
        if ($exit !== 0 || !is_array($decoded)) {
            throw new RuntimeException('Garmin auth helper failed: ' . $this->safeError((string)$stderr));
        }
        (new AuditEventService($this->pdo))->record(
            $auditAction,
            'garmin_auth_session',
            $action,
            null,
            $this->auditSafePayload($decoded),
            null,
            'user',
            $actorUserId,
            null,
            null,
            1,
            'web'
        );
        return $decoded;
    }

    private function helperPath(): string
    {
        return dirname(__DIR__) . '/scripts/garmin/garmin-auth-session.sh';
    }

    private function safeError(string $stderr): string
    {
        $stderr = trim(preg_replace('/\s+/', ' ', $stderr) ?? '');
        return $stderr !== '' ? substr($stderr, 0, 300) : 'unknown helper error';
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function auditSafePayload(array $payload): array
    {
        unset($payload['vnc_password']);
        return $payload;
    }
}
