<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/AuditEventService.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

$displayName = trim((string)($argv[1] ?? 'IPCA Desktop Mac Sync Agent'));
$token = 'ipca_sync_' . bin2hex(random_bytes(32));
$hash = hash('sha256', $token);
$uuid = AuditEventService::uuid();
$scopes = array(
    'sync_agent.status',
    'sync_agent.garmin_entries',
    'sync_agent.garmin_source',
    'sync_agent.garmin_sync_complete',
);

$stmt = $pdo->prepare("
    INSERT INTO ipca_sync_agent_tokens
      (token_uuid, token_hash, display_name, scope_json)
    VALUES (?, ?, ?, ?)
");
$stmt->execute(array($uuid, $hash, $displayName, AuditEventService::jsonEncode($scopes)));

echo "Created IPCA Sync Agent token for: {$displayName}\n";
echo "Token UUID: {$uuid}\n";
echo "Copy this token into IPCA Sync Agent now. It will not be shown again:\n";
echo $token . "\n";
