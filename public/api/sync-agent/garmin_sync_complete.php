<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

try {
    $payload = sync_agent_payload();
    (new SyncAgentAuthService($pdo))->requireToken('sync_agent.garmin_sync_complete');
    $result = (new SyncAgentGarminIngestionService($pdo))->completeSync(isset($payload['cursor']) ? (string)$payload['cursor'] : null);
    sync_agent_json(200, $result);
} catch (Throwable $e) {
    sync_agent_error($e);
}
