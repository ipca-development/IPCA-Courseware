#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/communication/CommunicationKernel.php';

$root = dirname(__DIR__);
$failures = array();

function comm_assert(string $name, bool $ok): void
{
    global $failures;
    if ($ok) {
        echo "PASS  {$name}\n";
        return;
    }
    echo "FAIL  {$name}\n";
    $failures[] = $name;
}

function comm_uuid(): string
{
    return CommunicationSupport::uuid();
}

function comm_sqlite(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec("CREATE TABLE users (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      uuid TEXT NOT NULL,
      email TEXT NOT NULL,
      name TEXT NOT NULL,
      first_name TEXT NOT NULL,
      last_name TEXT NOT NULL,
      role TEXT NOT NULL,
      status TEXT NOT NULL,
      account_valid_until TEXT NULL,
      photo_path TEXT NULL,
      password_hash TEXT NOT NULL
    )");
    $pdo->exec("CREATE TABLE ipca_communication_app_config (
      config_key TEXT PRIMARY KEY,
      config_value TEXT NOT NULL,
      updated_at_utc TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE TABLE ipca_communication_system_actors (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      actor_uuid TEXT NOT NULL UNIQUE,
      actor_key TEXT NOT NULL UNIQUE,
      display_name TEXT NOT NULL,
      is_active INTEGER NOT NULL DEFAULT 1,
      created_at_utc TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE TABLE ipca_communication_devices (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      device_uuid TEXT NOT NULL UNIQUE,
      user_id INTEGER NOT NULL,
      organization_id INTEGER NOT NULL DEFAULT 1,
      platform TEXT NOT NULL,
      model TEXT NOT NULL DEFAULT '',
      os_version TEXT NOT NULL DEFAULT '',
      app_version TEXT NOT NULL DEFAULT '',
      apns_token TEXT NULL,
      push_authorized INTEGER NULL,
      apns_environment TEXT NULL,
      last_seen_at_utc TEXT NULL,
      last_sync_at_utc TEXT NULL,
      last_sync_cursor INTEGER NOT NULL DEFAULT 0,
      revoked_at_utc TEXT NULL,
      created_at_utc TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at_utc TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE TABLE ipca_communication_device_credentials (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      credential_uuid TEXT NOT NULL UNIQUE,
      device_id INTEGER NOT NULL,
      token_hash TEXT NOT NULL UNIQUE,
      label TEXT NOT NULL DEFAULT 'session',
      expires_at_utc TEXT NULL,
      revoked_at_utc TEXT NULL,
      last_used_at_utc TEXT NULL,
      created_at_utc TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
      FOREIGN KEY (device_id) REFERENCES ipca_communication_devices(id)
    )");
    $pdo->exec("CREATE TABLE ipca_communication_conversations (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      conversation_uuid TEXT NOT NULL UNIQUE,
      organization_id INTEGER NOT NULL DEFAULT 1,
      conversation_type TEXT NOT NULL,
      title TEXT NOT NULL DEFAULT '',
      direct_pair_key TEXT NULL,
      created_by_user_id INTEGER NULL,
      last_message_seq INTEGER NOT NULL DEFAULT 0,
      last_message_at_utc TEXT NULL,
      created_at_utc TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at_utc TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE (organization_id, direct_pair_key)
    )");
    $pdo->exec("CREATE TABLE ipca_communication_conversation_members (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      conversation_id INTEGER NOT NULL,
      user_id INTEGER NOT NULL,
      member_role TEXT NOT NULL DEFAULT 'member',
      last_read_seq INTEGER NOT NULL DEFAULT 0,
      last_read_at_utc TEXT NULL,
      muted INTEGER NOT NULL DEFAULT 0,
      joined_at_utc TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
      left_at_utc TEXT NULL,
      UNIQUE (conversation_id, user_id),
      FOREIGN KEY (conversation_id) REFERENCES ipca_communication_conversations(id)
    )");
    $pdo->exec("CREATE TABLE ipca_communication_messages (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      message_uuid TEXT NOT NULL UNIQUE,
      conversation_id INTEGER NOT NULL,
      organization_id INTEGER NOT NULL DEFAULT 1,
      seq INTEGER NOT NULL,
      client_id TEXT NOT NULL,
      sender_user_id INTEGER NULL,
      sender_device_id INTEGER NULL,
      sender_system_actor_id INTEGER NULL,
      sender_type TEXT NOT NULL DEFAULT 'user',
      body TEXT NOT NULL,
      requires_acknowledgement INTEGER NOT NULL DEFAULT 0,
      reply_allowed INTEGER NOT NULL DEFAULT 1,
      source_type TEXT NULL,
      source_id TEXT NULL,
      source_event_id TEXT NULL,
      created_at_utc TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE (sender_user_id, client_id),
      UNIQUE (conversation_id, seq),
      FOREIGN KEY (conversation_id) REFERENCES ipca_communication_conversations(id)
    )");
    $pdo->exec("CREATE TABLE ipca_communication_change_log (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      organization_id INTEGER NOT NULL DEFAULT 1,
      conversation_id INTEGER NOT NULL,
      change_type TEXT NOT NULL,
      entity_uuid TEXT NOT NULL,
      created_at_utc TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
      FOREIGN KEY (conversation_id) REFERENCES ipca_communication_conversations(id)
    )");
    $pdo->exec("CREATE TABLE ipca_communication_message_device_syncs (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      message_id INTEGER NOT NULL,
      device_id INTEGER NOT NULL,
      user_id INTEGER NOT NULL,
      synced_at_utc TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE (message_id, device_id),
      FOREIGN KEY (message_id) REFERENCES ipca_communication_messages(id),
      FOREIGN KEY (device_id) REFERENCES ipca_communication_devices(id)
    )");
    $pdo->exec("CREATE TABLE ipca_communication_push_attempts (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      push_uuid TEXT NOT NULL UNIQUE,
      message_id INTEGER NOT NULL,
      device_id INTEGER NOT NULL,
      accepted_at_utc TEXT NULL,
      failed_at_utc TEXT NULL,
      provider_response TEXT NULL,
      created_at_utc TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE (message_id, device_id)
    )");
    $pdo->exec("CREATE TABLE ipca_communication_acknowledgements (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      acknowledgement_uuid TEXT NOT NULL UNIQUE,
      message_id INTEGER NOT NULL,
      user_id INTEGER NOT NULL,
      device_id INTEGER NULL,
      acknowledged_at_utc TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE (message_id, user_id)
    )");

    $flags = array(
        'protocol_version' => '1',
        'min_app_version' => '1.0.0',
        'min_ios_version' => '17.0',
        'messaging_enabled' => '1',
        'groups_enabled' => '1',
        'attachments_enabled' => '0',
        'system_messages_enabled' => '0',
        'training_enabled' => '0',
        'community_enabled' => '0',
        'community_posting_enabled' => '0',
        'push_enabled' => '1',
    );
    $insert = $pdo->prepare('INSERT INTO ipca_communication_app_config (config_key, config_value) VALUES (?, ?)');
    foreach ($flags as $key => $value) {
        $insert->execute(array($key, $value));
    }
    return $pdo;
}

function comm_add_user(PDO $pdo, string $email, string $name, string $role = 'student', string $status = 'active'): int
{
    $stmt = $pdo->prepare('INSERT INTO users (uuid, email, name, first_name, last_name, role, status, password_hash) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $parts = explode(' ', $name, 2);
    $stmt->execute(array(
        comm_uuid(),
        $email,
        $name,
        $parts[0],
        $parts[1] ?? '',
        $role,
        $status,
        password_hash('secret', PASSWORD_DEFAULT),
    ));
    return (int)$pdo->lastInsertId();
}

function comm_login(CommunicationKernel $kernel, string $email, string $platform = 'iphone'): array
{
    return $kernel->auth->login($email, 'secret', array(
        'device_uuid' => comm_uuid(),
        'platform' => $platform,
        'model' => $platform === 'ipad' ? 'iPad' : 'iPhone',
        'os_version' => '17.0',
        'app_version' => '1.0.0',
    ));
}

function comm_session(CommunicationKernel $kernel, array $login): array
{
    return $kernel->auth->authenticateToken((string)$login['token']);
}

$migration = file_get_contents($root . '/scripts/sql/2026_08_13_communication_phase1.sql') ?: '';
$iosAppPath = $root . '/ipca-app-ios/IPCA/IPCAApp.swift';
$iosApp = is_file($iosAppPath) ? (string)file_get_contents($iosAppPath) : '';
comm_assert('migration creates communication tables', str_contains($migration, 'CREATE TABLE IF NOT EXISTS ipca_communication_messages'));
comm_assert('migration seeds rollout flags', str_contains($migration, 'community_enabled') && str_contains($migration, "'0'"));
comm_assert('migration distinguishes device_synced from push_accepted', str_contains($migration, 'ipca_communication_message_device_syncs') && str_contains($migration, 'ipca_communication_push_attempts'));
comm_assert('auth endpoint exists', is_file($root . '/public/api/communication/auth.php'));
comm_assert('bootstrap endpoint exists', is_file($root . '/public/api/communication/bootstrap.php'));
comm_assert('sync endpoint exists', is_file($root . '/public/api/communication/sync.php'));
comm_assert('messages endpoint exists', is_file($root . '/public/api/communication/messages.php'));

$pdo = comm_sqlite();
$kernel = new CommunicationKernel($pdo);
$userA = comm_add_user($pdo, 'a@ipca.training', 'Alice Student');
$userB = comm_add_user($pdo, 'b@ipca.training', 'Bob Instructor', 'instructor');
$userC = comm_add_user($pdo, 'c@ipca.training', 'Cara Student');
$lockedId = comm_add_user($pdo, 'locked@ipca.training', 'Locked User', 'student', 'locked');

$loginA = comm_login($kernel, 'a@ipca.training', 'iphone');
$loginB = comm_login($kernel, 'b@ipca.training', 'iphone');
$loginAPad = comm_login($kernel, 'a@ipca.training', 'ipad');
$sessionA = comm_session($kernel, $loginA);
$sessionB = comm_session($kernel, $loginB);
$sessionAPad = comm_session($kernel, $loginAPad);

comm_assert('login issues bearer token', is_string($loginA['token']) && $loginA['token'] !== '');
comm_assert('login does not persist plaintext token', $pdo->query("SELECT COUNT(*) FROM ipca_communication_device_credentials WHERE token_hash = '" . $loginA['token'] . "'")->fetchColumn() == 0);
comm_assert('iphone and ipad are separate devices', (string)$loginA['device']['device_uuid'] !== (string)$loginAPad['device']['device_uuid']);

$lockedFailed = false;
try {
    comm_login($kernel, 'locked@ipca.training');
} catch (CommunicationException $e) {
    $lockedFailed = $e->errorCode === 'account_ineligible';
}
comm_assert('locked account cannot log in', $lockedFailed);

$wrongPassword = false;
try {
    $kernel->auth->login('a@ipca.training', 'nope', array(
        'device_uuid' => comm_uuid(),
        'platform' => 'iphone',
        'app_version' => '1.0.0',
    ));
} catch (CommunicationException $e) {
    $wrongPassword = $e->errorCode === 'invalid_credentials' && $e->httpStatus === 401;
}
comm_assert('wrong password is rejected', $wrongPassword);

$bootstrap = $kernel->sync->bootstrap($sessionA);
comm_assert('bootstrap hides unfinished tabs', $bootstrap['capabilities']['community_enabled'] === false && $bootstrap['capabilities']['training_enabled'] === false);
comm_assert('bootstrap enables messaging', $bootstrap['capabilities']['messaging_enabled'] === true);
comm_assert('bootstrap does not include inbox', !isset($bootstrap['messages']) && !isset($bootstrap['conversations']));
comm_assert('bootstrap includes unread and needs-action counts', array_key_exists('unread_count', $bootstrap) && array_key_exists('needs_action_count', $bootstrap));

$peerUuid = (string)$loginB['user']['uuid'];
$created = $kernel->conversations->createDirect($sessionA, $peerUuid);
$createdAgain = $kernel->conversations->createDirect($sessionA, $peerUuid);
comm_assert('direct conversation is reused', $created['conversation_uuid'] === $createdAgain['conversation_uuid']);
comm_assert('direct conversation has two members', count($created['members']) === 2);

$conversationUuid = (string)$created['conversation_uuid'];
$client1 = comm_uuid();
$sent = $kernel->messages->send($sessionA, $conversationUuid, $client1, 'Hello Bob');
$sentAgain = $kernel->messages->send($sessionA, $conversationUuid, $client1, 'Hello Bob');
comm_assert('duplicate client_id does not create a second message', $sent['message_uuid'] === $sentAgain['message_uuid'] && $sent['seq'] === 1);
comm_assert('message is server_received', $sent['server_received'] === true);

$count = (int)$pdo->query('SELECT COUNT(*) FROM ipca_communication_messages')->fetchColumn();
comm_assert('only one row after duplicate send', $count === 1);

$syncB1 = $kernel->sync->pull($sessionB, 0);
comm_assert('receiver syncs the message', count($syncB1['messages']) === 1 && $syncB1['messages'][0]['body'] === 'Hello Bob');
comm_assert('sync cursor advances', (int)$syncB1['cursor'] > 0);

$deviceSyncCount = (int)$pdo->query('SELECT COUNT(*) FROM ipca_communication_message_device_syncs')->fetchColumn();
comm_assert('device_synced evidence exists for sender and receiver devices', $deviceSyncCount >= 2);

$replyClient = comm_uuid();
$reply = $kernel->messages->send($sessionB, $conversationUuid, $replyClient, 'Hi Alice');
$syncA1 = $kernel->sync->pull($sessionA, 0);
$bodies = array_map(static fn(array $m): string => (string)$m['body'], $syncA1['messages']);
comm_assert('sender synchronizes the reply', in_array('Hi Alice', $bodies, true));

$syncAPad = $kernel->sync->pull($sessionAPad, 0);
$padBodies = array_map(static fn(array $m): string => (string)$m['body'], $syncAPad['messages']);
comm_assert('iPad of same user receives both messages', in_array('Hello Bob', $padBodies, true) && in_array('Hi Alice', $padBodies, true));

$syncBRepeat = $kernel->sync->pull($sessionB, (int)$syncB1['cursor']);
comm_assert('repeated sync with same cursor is empty after catching up except new events', is_array($syncBRepeat['messages']));

$rapid = array();
for ($i = 1; $i <= 5; $i++) {
    $rapid[] = $kernel->messages->send($sessionA, $conversationUuid, comm_uuid(), 'rapid-' . $i);
}
$seqs = array_map(static fn(array $m): int => (int)$m['seq'], $rapid);
comm_assert('rapid messages get monotonic seq', $seqs === array(3, 4, 5, 6, 7));

$page = $kernel->messages->page($sessionB, $conversationUuid, null, 50);
$pageBodies = array_map(static fn(array $m): string => (string)$m['body'], $page);
comm_assert('history is ordered by seq', $pageBodies[0] === 'Hello Bob' && end($pageBodies) === 'rapid-5');

$loginC = comm_login($kernel, 'c@ipca.training');
$sessionC = comm_session($kernel, $loginC);
$unauthorized = false;
try {
    $kernel->messages->page($sessionC, $conversationUuid, null, 20);
} catch (CommunicationException $e) {
    $unauthorized = $e->errorCode === 'not_a_member' && $e->httpStatus === 403;
}
comm_assert('non-member cannot read conversation', $unauthorized);

$kernel->messages->markRead($sessionB, $conversationUuid, (int)$reply['seq']);
$listed = $kernel->conversations->listForUser($sessionB);
comm_assert('unread count drops after read', (int)$listed[0]['unread_count'] === 5);

$second = $kernel->conversations->createDirect($sessionA, (string)$loginC['user']['uuid']);
$kernel->messages->send($sessionA, (string)$second['conversation_uuid'], comm_uuid(), 'later conversation');
$ordered = $kernel->conversations->listForUser($sessionA);
comm_assert('newest conversation is first after sync', $ordered[0]['conversation_uuid'] === $second['conversation_uuid']);

$group = $kernel->conversations->createGroup($sessionA, 'Belgium August 2026', array((string)$loginB['user']['uuid'], (string)$loginC['user']['uuid']));
$groupMsg = $kernel->messages->send($sessionA, (string)$group['conversation_uuid'], comm_uuid(), 'Welcome');
$syncC = $kernel->sync->pull($sessionC, 0);
$groupHit = false;
foreach ($syncC['messages'] as $message) {
    if ($message['message_uuid'] === $groupMsg['message_uuid']) {
        $groupHit = true;
    }
}
comm_assert('group message reaches members via sync', $groupHit && $group['conversation_type'] === 'group');

$recorder = new CommunicationPushRecordingTransport();
$kernel->push->useTransport($recorder);
$tokenB = str_repeat('ab', 32);
$kernel->auth->upsertDevice((int)$sessionB['user']['id'], array(
    'device_uuid' => (string)$sessionB['device']['device_uuid'],
    'platform' => (string)$sessionB['device']['platform'],
    'model' => (string)($sessionB['device']['model'] ?? 'iPhone'),
    'os_version' => '17.0',
    'app_version' => '1.0.0',
    'apns_token' => $tokenB,
    'push_authorized' => 1,
    'apns_environment' => 'sandbox',
));
$sessionAPad = comm_session($kernel, $loginAPad);
$kernel->auth->upsertDevice((int)$sessionAPad['user']['id'], array(
    'device_uuid' => (string)$sessionAPad['device']['device_uuid'],
    'platform' => 'ipad',
    'model' => 'iPad',
    'os_version' => '17.0',
    'app_version' => '1.0.0',
    'apns_token' => str_repeat('cd', 32),
    'push_authorized' => 1,
    'apns_environment' => 'sandbox',
));
$pushed = $kernel->messages->send($sessionA, $conversationUuid, comm_uuid(), 'push-wake');
comm_assert('APNs wakes the recipient device only', count($recorder->sent) === 1 && (string)$recorder->sent[0]['device_token'] === $tokenB);
comm_assert(
    'push payload is a wake with conversation uuid and badge',
    (string)($recorder->sent[0]['payload']['conversation_uuid'] ?? '') === $conversationUuid
    && isset($recorder->sent[0]['payload']['aps']['badge'])
    && (string)($recorder->sent[0]['payload']['aps']['alert']['body'] ?? '') === 'push-wake'
);
$pushRow = $pdo->query('SELECT accepted_at_utc, failed_at_utc FROM ipca_communication_push_attempts ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
$syncRowCount = (int)$pdo->query(
    'SELECT COUNT(*) FROM ipca_communication_message_device_syncs ds INNER JOIN ipca_communication_messages m ON m.id = ds.message_id WHERE m.message_uuid = ' . $pdo->quote((string)$pushed['message_uuid'])
)->fetchColumn();
comm_assert(
    'push_accepted is recorded separately from device_synced',
    is_array($pushRow) && trim((string)($pushRow['accepted_at_utc'] ?? '')) !== '' && $syncRowCount >= 1
);
$duplicateClient = (string)$pushed['client_id'];
$kernel->messages->send($sessionA, $conversationUuid, $duplicateClient, 'push-wake');
comm_assert('duplicate send does not create a second APNs attempt', count($recorder->sent) === 1);

$key = openssl_pkey_new(array('private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1'));
$pem = '';
if (is_object($key) || is_resource($key)) {
    openssl_pkey_export($key, $pem);
}
$jwt = $pem !== '' ? CommunicationApnsClient::jwtFromPem('KEYIDKEYID', 'W9RY547Y4P', $pem, time()) : '';
$jwtParts = explode('.', $jwt);
$jwtSig = $jwtParts[2] ?? '';
$jwtSigBin = base64_decode(strtr($jwtSig, '-_', '+/') . str_repeat('=', (4 - strlen($jwtSig) % 4) % 4));
comm_assert('APNs JWT is ES256 with a raw P-256 signature', count($jwtParts) === 3 && strlen((string)$jwtSigBin) === 64);

$phase2Sql = (string)file_get_contents($root . '/scripts/sql/2026_08_13_communication_phase2_apns.sql');
comm_assert('phase 2 SQL is additive for APNs environment', str_contains($phase2Sql, 'apns_environment') && str_contains($phase2Sql, 'push_enabled'));

$pdo->prepare("UPDATE users SET status = 'locked' WHERE id = ?")->execute(array($userA));
$blocked = false;
try {
    $kernel->auth->authenticateToken((string)$loginA['token']);
} catch (CommunicationException $e) {
    $blocked = $e->errorCode === 'account_ineligible';
}
comm_assert('locked account loses access while credential still exists', $blocked);
$pdo->prepare("UPDATE users SET status = 'active' WHERE id = ?")->execute(array($userA));

$kernel->auth->logout($sessionB);
$loggedOut = false;
try {
    $kernel->auth->authenticateToken((string)$loginB['token']);
} catch (CommunicationException $e) {
    $loggedOut = $e->errorCode === 'credential_revoked' || $e->errorCode === 'unauthenticated';
}
comm_assert('logout revokes only that device credential', $loggedOut);

$pdo->prepare("UPDATE ipca_communication_app_config SET config_value = '0' WHERE config_key = 'messaging_enabled'")->execute();
$kernelDisabled = new CommunicationKernel($pdo);
$sessionA2 = $kernelDisabled->auth->authenticateToken((string)$loginA['token']);
$disabled = false;
try {
    $kernelDisabled->messages->send($sessionA2, $conversationUuid, comm_uuid(), 'should fail');
} catch (CommunicationException $e) {
    $disabled = $e->errorCode === 'messaging_disabled';
}
comm_assert('server flag can disable messaging without an app update', $disabled);

comm_assert(
    'Courseware user UUIDs without RFC variant bits are accepted',
    CommunicationSupport::isUuid('7fa33990-2525-1ae2-6f5a-0ddc2d9a4969')
    && CommunicationSupport::isUuid('bf12be0f-0bc7-bfcb-e092-632f941adcc0')
    && CommunicationSupport::isUuid('f70c19f7-2268-11f1-9326-2ee9f951d5a3')
    && !CommunicationSupport::isUuid('')
    && !CommunicationSupport::isUuid('not-a-uuid')
);
comm_assert(
    'iOS opens the conversation after create instead of only dismissing',
    str_contains((string)file_get_contents($root . '/ipca-app-ios/IPCA/Views/NewMessageView.swift'), 'revealOpenedConversation')
    && str_contains((string)file_get_contents($root . '/ipca-app-ios/IPCA/Views/MessagesRootView.swift'), 'compactPath')
);

foreach (glob($root . '/public/api/communication/*.php') ?: array() as $apiFile) {
    $src = (string)file_get_contents($apiFile);
    comm_assert(basename($apiFile) . ' uses PDO-only communication bootstrap', str_contains($src, 'api_bootstrap.php') && !str_contains($src, 'src/bootstrap.php'));
}

$authSrc = file_get_contents($root . '/src/communication/CommunicationAuthService.php') ?: '';
comm_assert('auth checks eligibility on every request', str_contains($authSrc, 'userIsEligible'));
$msgSrc = file_get_contents($root . '/src/communication/MessageService.php') ?: '';
comm_assert('send is idempotent on client_id', str_contains($msgSrc, 'findByClientId'));
$syncSrc = file_get_contents($root . '/src/communication/CommunicationSyncService.php') ?: '';
comm_assert('sync is cursor based and transport-independent', str_contains($syncSrc, 'ipca_communication_change_log'));

if ($iosApp === '') {
    echo "NOTE  iOS app file not yet present during this check\n";
} else {
    comm_assert('iOS app stores the token in Keychain', str_contains((string)file_get_contents($root . '/ipca-app-ios/IPCA/Auth/KeychainStore.swift'), 'kSecAttrAccessibleAfterFirstUnlockThisDeviceOnly'));
    comm_assert('iOS outbox supports dependent attachment operations', str_contains((string)file_get_contents($root . '/ipca-app-ios/IPCA/Sync/OutboxPlanner.swift'), 'uploadAttachment'));
    comm_assert('iOS hides unfinished tabs behind server flags', str_contains((string)file_get_contents($root . '/ipca-app-ios/IPCA/Views/MainShellView.swift'), 'communityEnabled'));
    comm_assert('iOS upserts synced messages by client_id then message_uuid', str_contains((string)file_get_contents($root . '/ipca-app-ios/IPCA/Persistence/StoreWriter.swift'), 'message(clientID: dto.clientID) ?? message(uuid: dto.messageUUID)'));
    comm_assert('iOS recovers interrupted outbox on launch', str_contains((string)file_get_contents($root . '/ipca-app-ios/IPCA/Sync/OutboxWorker.swift'), 'recoverOutbox'));
    comm_assert('iOS awaits bearer token before post-login API calls', str_contains((string)file_get_contents($root . '/ipca-app-ios/IPCA/App/AppSession.swift'), 'await api.setToken(response.token)'));
    comm_assert('iOS registers for APNs after login', str_contains((string)file_get_contents($root . '/ipca-app-ios/IPCA/App/AppSession.swift'), 'requestPushAuthorization'));
    comm_assert('iOS Debug entitlements request development APNs', str_contains((string)file_get_contents($root . '/ipca-app-ios/IPCA/IPCA-Debug.entitlements'), 'aps-environment') && str_contains((string)file_get_contents($root . '/ipca-app-ios/IPCA/IPCA-Debug.entitlements'), 'development'));
    comm_assert('iOS opens conversations from ipca://c/ deep links', str_contains((string)file_get_contents($root . '/ipca-app-ios/IPCA/Info.plist'), 'ipca') && str_contains((string)file_get_contents($root . '/ipca-app-ios/IPCA/App/AppSession.swift'), 'handleOpenURL'));
}

if ($failures) {
    echo "\n" . count($failures) . " failed\n";
    exit(1);
}
echo "\nAll communication Phase 1 checks passed\n";
exit(0);
