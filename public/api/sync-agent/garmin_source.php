<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

try {
    $payload = sync_agent_payload();
    $token = (new SyncAgentAuthService($pdo))->requireToken('sync_agent.garmin_source');
    $result = (new SyncAgentGarminIngestionService($pdo))->ingestSource($token, $payload);
    sync_agent_json(!empty($result['ok']) ? 200 : 400, $result);
} catch (Throwable $e) {
    sync_agent_error($e);
}
