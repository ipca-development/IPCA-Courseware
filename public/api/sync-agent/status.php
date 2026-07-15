<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

try {
    sync_agent_payload();
    $token = (new SyncAgentAuthService($pdo))->requireToken('sync_agent.status');
    sync_agent_json(200, array(
        'ok' => true,
        'status' => 'accepted',
        'device' => array(
            'token_uuid' => $token['token_uuid'] ?? null,
            'display_name' => $token['display_name'] ?? 'IPCA Sync Agent',
        ),
        'server_time_utc' => gmdate('c'),
    ));
} catch (Throwable $e) {
    sync_agent_error($e);
}
