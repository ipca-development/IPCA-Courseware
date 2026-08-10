#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/CvrCrewMessageService.php';

$root = dirname(__DIR__);
$migration = file_get_contents($root . '/scripts/sql/2026_08_10_cvr_crew_messages.sql') ?: '';
$serviceSource = file_get_contents($root . '/src/CvrCrewMessageService.php') ?: '';
$adminEndpoint = file_get_contents($root . '/public/admin/api/cvr_crew_messages.php') ?: '';
$deviceEndpoint = file_get_contents($root . '/public/api/cvr/crew_messages.php') ?: '';
$schedulePage = file_get_contents($root . '/public/admin/schedule.php') ?: '';
$scheduleJs = file_get_contents($root . '/public/admin/assets/flight_schedule.js') ?: '';
$masterRead = file_get_contents($root . '/src/CvrDataIntakeReadService.php') ?: '';
$masterPage = file_get_contents($root . '/public/admin/master_logbook_intake.php') ?: '';
$iosStore = file_get_contents(
    $root . '/ipca-cvr-unit/IPCACVRUnit/Services/CrewMessagesStore.swift'
) ?: '';
$iosContent = file_get_contents(
    $root . '/ipca-cvr-unit/IPCACVRUnit/Views/ContentView.swift'
) ?: '';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE ipca_cvr_devices (
  id INTEGER PRIMARY KEY, organization_id INTEGER NOT NULL, aircraft_id INTEGER,
  active INTEGER NOT NULL, revoked_at TEXT NULL
)');
$pdo->exec('CREATE TABLE ipca_flight_schedule_slots (
  id INTEGER PRIMARY KEY, organization_id INTEGER NOT NULL,
  claimed_dispatch_uuid TEXT, status TEXT NOT NULL
)');
$pdo->exec('CREATE TABLE ipca_cvr_dispatches (
  id INTEGER PRIMARY KEY, organization_id INTEGER NOT NULL, aircraft_id INTEGER,
  device_id INTEGER NOT NULL, dispatch_uuid TEXT NOT NULL,
  workflow_flight_record_uuid TEXT NOT NULL, operational_session_uuid TEXT,
  status TEXT NOT NULL, last_received_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
)');
$pdo->exec('CREATE TABLE ipca_flight_sessions (
  id INTEGER PRIMARY KEY, organization_id INTEGER NOT NULL, aircraft_id INTEGER,
  device_id INTEGER NOT NULL, session_uuid TEXT NOT NULL, status TEXT NOT NULL,
  avionics_off_utc TEXT NULL
)');
$pdo->exec('CREATE TABLE ipca_cvr_crew_messages (
  id INTEGER PRIMARY KEY AUTOINCREMENT, message_uuid TEXT NOT NULL UNIQUE,
  organization_id INTEGER NOT NULL, aircraft_id INTEGER NOT NULL, device_id INTEGER NOT NULL,
  operational_session_uuid TEXT NOT NULL, workflow_flight_record_uuid TEXT NOT NULL,
  dispatch_uuid TEXT NOT NULL, sender_user_id INTEGER NOT NULL, sender_name TEXT NOT NULL,
  sender_role TEXT NOT NULL, body TEXT NOT NULL, sent_at_utc TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
)');
$pdo->exec('CREATE TABLE ipca_cvr_crew_message_acknowledgements (
  id INTEGER PRIMARY KEY AUTOINCREMENT, acknowledgement_uuid TEXT NOT NULL UNIQUE,
  message_id INTEGER NOT NULL, message_uuid TEXT NOT NULL, organization_id INTEGER NOT NULL,
  aircraft_id INTEGER NOT NULL, device_id INTEGER NOT NULL,
  operational_session_uuid TEXT NOT NULL, workflow_flight_record_uuid TEXT NOT NULL,
  device_event_at_utc TEXT NOT NULL,
  server_received_at_utc TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE(message_id, device_id)
)');

$dispatchUuid = '11111111-1111-4111-8111-111111111111';
$sessionUuid = '22222222-2222-4222-8222-222222222222';
$flightUuid = '33333333-3333-4333-8333-333333333333';
$pdo->exec("INSERT INTO ipca_cvr_devices VALUES (7, 42, 9, 1, NULL)");
$pdo->exec("INSERT INTO ipca_flight_schedule_slots VALUES (1, 42, '$dispatchUuid', 'claimed')");
$pdo->exec("INSERT INTO ipca_cvr_dispatches VALUES
  (1, 42, 9, 7, '$dispatchUuid', '$flightUuid', '$sessionUuid', 'active', CURRENT_TIMESTAMP)");
$pdo->exec("INSERT INTO ipca_flight_sessions VALUES (1, 42, 9, 7, '$sessionUuid', 'intended', NULL)");

$service = new CvrCrewMessageService($pdo);
$sender = array('id' => 12, 'name' => 'Dispatcher', 'role' => 'instructor');
$device = array('id' => 7, 'organization_id' => 42, 'aircraft_id' => 9);
$initialStatus = $service->activeStatusForAircraft(9, $dispatchUuid);
$sent = $service->sendForAircraft(9, $dispatchUuid, 'Return to base.', $sender);
$messageUuid = (string)$sent['message']['message_uuid'];
$pending = $service->pendingForDevice($device, $sessionUuid);
$serverResolvedPending = $service->pendingForDevice($device, '');
$ackPayload = array(
    'message_uuid' => $messageUuid,
    'acknowledgement_uuid' => '44444444-4444-4444-8444-444444444444',
    'operational_session_uuid' => $sessionUuid,
    'device_event_at_utc' => gmdate('Y-m-d\TH:i:s\Z', time() + 1),
);
$firstAck = $service->acknowledge($device, $ackPayload);
$retryAck = $service->acknowledge($device, $ackPayload);
$afterAck = $service->pendingForDevice($device, $sessionUuid);
$history = $service->historyByOperationalSession(42, $sessionUuid);
$otherOrganizationHistory = $service->historyByOperationalSession(99, $sessionUuid);

$tooLongRejected = false;
try {
    $service->send(42, $dispatchUuid, str_repeat('x', 513), $sender);
} catch (InvalidArgumentException) {
    $tooLongRejected = true;
}
$lateMessage = $service->send(42, $dispatchUuid, 'Acknowledge before shutdown.', $sender);
$lateMessageUuid = (string)$lateMessage['message']['message_uuid'];
$closedAt = gmdate('Y-m-d H:i:s', time() + 3);
$pdo->exec(
    "UPDATE ipca_flight_sessions
     SET status = 'completed', avionics_off_utc = " . $pdo->quote($closedAt) . "
     WHERE session_uuid = '$sessionUuid'"
);
$lateAck = $service->acknowledge($device, array(
    'message_uuid' => $lateMessageUuid,
    'acknowledgement_uuid' => '55555555-5555-4555-8555-555555555555',
    'operational_session_uuid' => $sessionUuid,
    'device_event_at_utc' => gmdate('Y-m-d\TH:i:s\Z', time() + 2),
));
$completedRejected = false;
try {
    $service->send(42, $dispatchUuid, 'Must not send.', $sender);
} catch (RuntimeException) {
    $completedRejected = true;
}

$checks = array(
    'migration is additive and evidence preserving' =>
        str_contains($migration, 'CREATE TABLE IF NOT EXISTS ipca_cvr_crew_messages')
        && str_contains($migration, 'CREATE TABLE IF NOT EXISTS ipca_cvr_crew_message_acknowledgements')
        && !preg_match('/^\s*(DROP|TRUNCATE|DELETE|UPDATE)\b/im', $migration),
    'both tables carry complete operational scope' =>
        preg_match_all('/^\s+organization_id\s+/m', $migration) === 2
        && preg_match_all('/^\s+aircraft_id\s+/m', $migration) === 2
        && preg_match_all('/^\s+device_id\s+/m', $migration) === 2
        && preg_match_all('/^\s+operational_session_uuid\s+/m', $migration) === 2
        && preg_match_all('/^\s+workflow_flight_record_uuid\s+/m', $migration) === 2,
    'message sender and flight scope are indexed' =>
        str_contains($migration, 'idx_ipca_cvr_crew_messages_sender')
        && str_contains($migration, 'idx_ipca_cvr_crew_messages_flight')
        && str_contains($migration, 'idx_ipca_cvr_crew_messages_org_session'),
    'acknowledgements are append-only and duplicate constrained' =>
        str_contains($migration, 'uk_ipca_cvr_crew_ack_uuid')
        && str_contains($migration, 'uk_ipca_cvr_crew_ack_message_device')
        && str_contains($migration, 'device_event_at_utc')
        && str_contains($migration, 'server_received_at_utc'),
    'admin API requires schedule editor and supports POST plus GET' =>
        str_contains($adminEndpoint, 'cw_require_flight_schedule_editor()')
        && str_contains($adminEndpoint, "\$method === 'GET'")
        && str_contains($adminEndpoint, "\$method === 'POST'")
        && str_contains($adminEndpoint, "SESSION['flight_schedule_csrf']")
        && str_contains($adminEndpoint, 'activeStatusForAircraft')
        && str_contains($adminEndpoint, 'sendForAircraft'),
    'device API requires bearer device auth and supports pending plus acknowledgement' =>
        str_contains($deviceEndpoint, 'DeviceAuthService')
        && str_contains($deviceEndpoint, 'requireDevice()')
        && str_contains($deviceEndpoint, 'pendingForDevice')
        && str_contains($deviceEndpoint, 'acknowledge('),
    'schedule modal sends only to the claimed active Dispatch with CSRF protection' =>
        str_contains($schedulePage, 'flightCrewMessagePanel')
        && str_contains($schedulePage, "'crewMessagesCsrfToken' => \$csrfToken")
        && str_contains($scheduleJs, 'claimed_dispatch_uuid: dispatchUUID')
        && str_contains($scheduleJs, 'crewMessagesCsrfToken')
        && str_contains($scheduleJs, 'startCrewMessagePolling')
        && str_contains($scheduleJs, 'stopCrewMessagePolling')
        && str_contains($scheduleJs, 'escapeHtml(body ||'),
    'closed-session communications are visible only in Master Logbook' =>
        str_contains($masterRead, 'attachClosedSessionCrewMessages')
        && str_contains($masterRead, "empty(\$row['has_closure'])")
        && str_contains($masterPage, 'Crew Communications')
        && str_contains($masterPage, 'Not acknowledged before session closure')
        && !str_contains($schedulePage, 'Not acknowledged before session closure'),
    'iOS polling and acknowledgement are local-first and recording independent' =>
        str_contains($iosStore, 'crew-messages.json')
        && str_contains($iosStore, 'pendingAcknowledgements')
        && str_contains($iosStore, 'self.messageSessionUUID == nil ? .seconds(15) : .seconds(5)')
        && str_contains($iosStore, 'network?.isSatisfied == true')
        && str_contains($iosStore, 'for acknowledgement in pendingAcknowledgements')
        && str_contains($iosStore, 'dispatch.operationalSessionUUID')
        && str_contains($iosStore, 'flight.dispatchID == dispatch.id')
        && str_contains($iosStore, 'operationalSessionUUID: ""')
        && str_contains($iosStore, 'serverActiveOperationalSessionUUID')
        && !str_contains($iosStore, 'allowCellularUpload')
        && str_contains($iosContent, 'SYSTEM MESSAGE')
        && str_contains($iosContent, 'ACKNOWLEDGE'),
    'active resolution binds claim dispatch session aircraft and assigned device' =>
        str_contains($serviceSource, 'slot.claimed_dispatch_uuid = d.dispatch_uuid')
        && str_contains($serviceSource, 's.device_id = d.device_id')
        && str_contains($serviceSource, 's.aircraft_id = d.aircraft_id')
        && str_contains($serviceSource, "TERMINAL_STATUSES = \"'completed','cancelled','released'\"")
        && substr_count($serviceSource, 'NOT IN (\' . self::TERMINAL_STATUSES') >= 6,
    'active message is delivered only to its assigned device' =>
        $initialStatus['active_session'] === true
        && count($pending['messages']) === 1
        && (string)$pending['messages'][0]['message_uuid'] === $messageUuid
        && count($serverResolvedPending['messages']) === 1
        && (string)$serverResolvedPending['operational_session_uuid'] === $sessionUuid,
    'acknowledgement retry is duplicate safe' =>
        $firstAck['already_acknowledged'] === false
        && $firstAck['acknowledged'] === true
        && (string)$firstAck['message_uuid'] === $messageUuid
        && $retryAck['already_acknowledged'] === true
        && (int)$pdo->query(
            "SELECT COUNT(*) FROM ipca_cvr_crew_message_acknowledgements
             WHERE message_uuid = " . $pdo->quote($messageUuid)
        )->fetchColumn() === 1
        && count($afterAck['messages']) === 0,
    'offline acknowledgement can arrive after closure when device event occurred in-session' =>
        $lateAck['acknowledged'] === true
        && (int)$pdo->query(
            "SELECT COUNT(*) FROM ipca_cvr_crew_message_acknowledgements
             WHERE message_uuid = " . $pdo->quote($lateMessageUuid)
        )->fetchColumn() === 1,
    'history is read-only and organization scoped' =>
        count($history) === 1
        && (string)$history[0]['acknowledgement_uuid'] === $ackPayload['acknowledgement_uuid']
        && count($otherOrganizationHistory) === 0,
    'body limit and completed session boundary are enforced' =>
        $tooLongRejected && $completedRejected,
);

$failed = array_keys(array_filter($checks, static fn(bool $passed): bool => !$passed));
foreach ($checks as $name => $passed) {
    echo ($passed ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
}
if ($failed !== array()) {
    fwrite(STDERR, "CVR crew messaging contract FAILED\n - " . implode("\n - ", $failed) . "\n");
    exit(1);
}
echo 'CVR crew messaging contract passed (' . count($checks) . " checks).\n";
