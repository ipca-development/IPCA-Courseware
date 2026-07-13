<?php
declare(strict_types=1);

require_once __DIR__ . '/AuditEventService.php';

final class GarminProviderStateService
{
    private const REQUIRED_ACCEPTANCE_CHECKS = array(
        'worker_authentication_test',
        'initial_garmin_logbook_sync',
        'cursor_persistence',
        'flight_data_uuid_discovered',
        'source_download',
        'source_classification',
        'immutable_evidence_storage',
        'validation',
        'session_match_or_expected_review',
        'flight_log_status_visible',
    );

    public function __construct(private PDO $pdo, private string $providerName = 'flygarmin_web')
    {
    }

    /**
     * @return array<string,mixed>
     */
    public function current(): array
    {
        $this->ensureRow();
        $stmt = $this->pdo->prepare('SELECT * FROM ipca_garmin_provider_states WHERE organization_id = 1 AND provider_name = ? LIMIT 1');
        $stmt->execute(array($this->providerName));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : array();
    }

    public function ensureRow(): void
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO ipca_garmin_provider_states
              (provider_name, provider_type, worker_mode, enabled, scheduled_sync_enabled, authentication_status, connection_status, acceptance_checks_json)
            VALUES
              (?, 'temporary_web_session', ?, 0, 0, 'not_configured', 'not_configured', ?)
            ON DUPLICATE KEY UPDATE
              provider_type = VALUES(provider_type),
              updated_at = CURRENT_TIMESTAMP(3)
        ");
        $stmt->execute(array(
            $this->providerName,
            $this->workerMode(),
            AuditEventService::jsonEncode($this->defaultAcceptanceChecks()),
        ));
    }

    /**
     * @param array<string,mixed> $fields
     */
    public function updateState(array $fields, string $auditAction = 'garmin_provider_state_updated', ?int $actorUserId = null): void
    {
        $allowed = array(
            'enabled', 'scheduled_sync_enabled', 'deployment_acceptance_passed', 'authentication_status',
            'connection_status', 'reauthentication_required', 'browser_profile_present', 'worker_reachable',
            'safe_account_label', 'last_authenticated_at', 'last_connection_test_at', 'last_successful_sync_at',
            'last_attempted_sync_at', 'last_initial_sync_at', 'last_incremental_sync_at', 'last_reconciliation_at',
            'last_version_cursor', 'last_version_cursor_decoded_at', 'acceptance_checks_json', 'last_error_code',
            'last_error_summary',
        );
        $sets = array();
        $params = array(':provider_name' => $this->providerName);
        foreach ($fields as $key => $value) {
            if (!in_array($key, $allowed, true)) {
                continue;
            }
            if ($key === 'scheduled_sync_enabled' && (int)$value === 1 && !$this->acceptancePassed()) {
                throw new RuntimeException('Scheduled Garmin synchronization cannot be enabled until all testing acceptance checks pass.');
            }
            $sets[] = $key . ' = :' . $key;
            $params[':' . $key] = is_bool($value) ? ($value ? 1 : 0) : $value;
        }
        if (!$sets) {
            return;
        }
        $sets[] = 'updated_at = CURRENT_TIMESTAMP(3)';
        $this->pdo->prepare('UPDATE ipca_garmin_provider_states SET ' . implode(', ', $sets) . ' WHERE organization_id = 1 AND provider_name = :provider_name')
            ->execute($params);
        (new AuditEventService($this->pdo))->record($auditAction, 'ipca_garmin_provider_states', $this->providerName, null, $fields, null, 'user', $actorUserId);
    }

    public function markAcceptanceCheck(string $checkKey, bool $passed, ?string $note = null): void
    {
        $state = $this->current();
        $checks = json_decode((string)($state['acceptance_checks_json'] ?? '{}'), true);
        $checks = is_array($checks) ? $checks : $this->defaultAcceptanceChecks();
        if (!array_key_exists($checkKey, $checks)) {
            throw new RuntimeException('Unknown Garmin acceptance check: ' . $checkKey);
        }
        $checks[$checkKey] = array(
            'passed' => $passed,
            'note' => $note,
            'checked_at' => gmdate('Y-m-d H:i:s'),
        );
        $allPassed = $this->allChecksPassed($checks);
        $this->updateState(array(
            'acceptance_checks_json' => AuditEventService::jsonEncode($checks),
            'deployment_acceptance_passed' => $allPassed ? 1 : 0,
            'scheduled_sync_enabled' => $allPassed && !empty($state['scheduled_sync_enabled']) ? 1 : 0,
        ), 'garmin_acceptance_check_updated');
    }

    public function acceptancePassed(): bool
    {
        $state = $this->current();
        if ((int)($state['deployment_acceptance_passed'] ?? 0) === 1) {
            return true;
        }
        $checks = json_decode((string)($state['acceptance_checks_json'] ?? '{}'), true);
        return is_array($checks) && $this->allChecksPassed($checks);
    }

    /**
     * @return array<string,array{passed:bool,note:?string,checked_at:?string}>
     */
    public function defaultAcceptanceChecks(): array
    {
        $checks = array();
        foreach (self::REQUIRED_ACCEPTANCE_CHECKS as $check) {
            $checks[$check] = array('passed' => false, 'note' => null, 'checked_at' => null);
        }
        return $checks;
    }

    /**
     * @param array<string,mixed> $checks
     */
    private function allChecksPassed(array $checks): bool
    {
        foreach (self::REQUIRED_ACCEPTANCE_CHECKS as $check) {
            if (empty($checks[$check]['passed'])) {
                return false;
            }
        }
        return true;
    }

    private function workerMode(): string
    {
        $mode = strtolower(trim((string)(getenv('GARMIN_WORKER_MODE') ?: 'remote_worker')));
        return in_array($mode, array('remote_worker', 'server_worker', 'local_cli'), true) ? $mode : 'remote_worker';
    }
}
