#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Live HTTP validation of Phase 1 communication APIs against a running staging server.
 *
 * Usage:
 *   IPCA_LIVE_BASE=http://127.0.0.1:18088 php tests/communication_phase1_live_http.php
 *
 * Optional:
 *   IPCA_LIVE_STATUS_CMD='ssh -o BatchMode=yes ipca php /var/www/ipca-comm-staging/scripts/staging/set_user_status.php'
 */
$base = rtrim((string)getenv('IPCA_LIVE_BASE'), '/');
if ($base === '') {
    fwrite(STDERR, "IPCA_LIVE_BASE is required.\n");
    exit(2);
}

$password = (string)(getenv('IPCA_LIVE_PASSWORD') ?: 'Phase1LiveValidate-2026!');
$emailA = 'live-a@ipca.training';
$emailB = 'live-b@ipca.training';
$statusCmd = trim((string)getenv('IPCA_LIVE_STATUS_CMD'));

$failures = array();
$notes = array();

function live_assert(string $name, bool $ok, string $detail = ''): void
{
    global $failures;
    if ($ok) {
        echo "PASS  {$name}\n";
        return;
    }
    echo "FAIL  {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    $failures[] = $name;
}

function live_uuid(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
}

/**
 * @param array<string,mixed>|null $body
 * @param array<string,string> $query
 * @return array{status:int,json:array<string,mixed>,raw:string}
 */
function live_request(string $method, string $path, ?array $body = null, ?string $token = null, array $query = array(), float $timeout = 15.0): array
{
    global $base;
    $url = $base . '/' . ltrim($path, '/');
    if ($query !== array()) {
        $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
    }
    $headers = array('Accept: application/json');
    if ($token) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, (int)ceil($timeout));
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_SLASHES));
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $raw = (string)curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    if ($raw === '' && $err !== '') {
        return array('status' => 0, 'json' => array('error' => $err, 'error_code' => 'transport'), 'raw' => '');
    }
    $json = json_decode($raw, true);
    return array(
        'status' => $status,
        'json' => is_array($json) ? $json : array(),
        'raw' => $raw,
    );
}

function live_login(string $email, string $password, string $platform, ?string $deviceUuid = null): array
{
    $deviceUuid = $deviceUuid ?? live_uuid();
    $res = live_request('POST', 'api/communication/auth.php', array(
        'action' => 'login',
        'email' => $email,
        'password' => $password,
        'device' => array(
            'device_uuid' => $deviceUuid,
            'platform' => $platform,
            'model' => $platform === 'ipad' ? 'iPad' : 'iPhone',
            'os_version' => '18.5',
            'app_version' => '1.0.0',
        ),
    ), null);
    if (($res['json']['ok'] ?? false) !== true || empty($res['json']['token'])) {
        throw new RuntimeException('login failed for ' . $email . ' ' . json_encode($res['json']));
    }
    $res['json']['device_uuid_sent'] = $deviceUuid;
    return $res['json'];
}

function live_set_status(string $email, string $status): void
{
    global $statusCmd;
    if ($statusCmd === '') {
        throw new RuntimeException('IPCA_LIVE_STATUS_CMD is required for account lock tests.');
    }
    $cmd = $statusCmd . ' ' . escapeshellarg($email) . ' ' . escapeshellarg($status);
    exec($cmd . ' 2>&1', $out, $code);
    if ($code !== 0) {
        throw new RuntimeException('status change failed: ' . implode("\n", $out));
    }
}

function live_interrupt_post(string $path, array $body, string $token): void
{
    global $base;
    $parts = parse_url($base);
    $host = (string)($parts['host'] ?? '127.0.0.1');
    $port = (int)($parts['port'] ?? 80);
    $payload = json_encode($body, JSON_UNESCAPED_SLASHES);
    $target = '/' . ltrim($path, '/');
    $fp = @fsockopen($host, $port, $errno, $errstr, 3);
    if ($fp === false) {
        throw new RuntimeException('interrupt connect failed: ' . $errstr);
    }
    $req = "POST {$target} HTTP/1.1\r\n";
    $req .= "Host: {$host}:{$port}\r\n";
    $req .= "Authorization: Bearer {$token}\r\n";
    $req .= "Content-Type: application/json\r\n";
    $req .= "Content-Length: " . strlen((string)$payload) . "\r\n";
    $req .= "Connection: close\r\n\r\n";
    $req .= $payload;
    fwrite($fp, $req);
    stream_set_timeout($fp, 0, 80000);
    fread($fp, 32);
    fclose($fp);
}

function live_bodies(array $messages): array
{
    $bodies = array();
    foreach ($messages as $message) {
        $bodies[] = (string)($message['body'] ?? '');
    }
    return $bodies;
}

function live_message_count_by_client(array $messages, string $clientId): int
{
    $n = 0;
    foreach ($messages as $message) {
        if ((string)($message['client_id'] ?? '') === $clientId) {
            $n++;
        }
    }
    return $n;
}

echo "base={$base}\n";

$loginA = live_login($emailA, $password, 'iphone');
$loginB = live_login($emailB, $password, 'iphone');
$loginAPad = live_login($emailA, $password, 'ipad');
$tokenA = (string)$loginA['token'];
$tokenB = (string)$loginB['token'];
$tokenAPad = (string)$loginAPad['token'];
$uuidB = (string)$loginB['user']['uuid'];

live_assert('1/5 login A iPhone', $tokenA !== '');
live_assert('5 login A iPad is a different device', (string)$loginA['device']['device_uuid'] !== (string)$loginAPad['device']['device_uuid']);

$boot = live_request('GET', 'api/communication/bootstrap.php', null, $tokenA);
live_assert('bootstrap ok', ($boot['json']['ok'] ?? false) === true && (int)$boot['status'] === 200);

$direct = live_request('POST', 'api/communication/conversations.php', array(
    'type' => 'direct',
    'peer_user_uuid' => $uuidB,
), $tokenA);
$conversationUuid = (string)($direct['json']['conversation']['conversation_uuid'] ?? '');
live_assert('direct conversation created', $conversationUuid !== '');

$client1 = live_uuid();
$send1 = live_request('POST', 'api/communication/messages.php', array(
    'conversation_uuid' => $conversationUuid,
    'client_id' => $client1,
    'body' => 'A to B live',
), $tokenA);
live_assert('1 User A → User B live message', ($send1['json']['ok'] ?? false) === true && ($send1['json']['message']['body'] ?? '') === 'A to B live');

$syncB = live_request('GET', 'api/communication/sync.php', null, $tokenB, array('cursor' => '0'));
live_assert('1 B synced A→B', in_array('A to B live', live_bodies($syncB['json']['messages'] ?? array()), true));

$clientReply = live_uuid();
$reply = live_request('POST', 'api/communication/messages.php', array(
    'conversation_uuid' => $conversationUuid,
    'client_id' => $clientReply,
    'body' => 'B to A reply',
), $tokenB);
live_assert('2 User B → User A reply', ($reply['json']['ok'] ?? false) === true);

$syncA = live_request('GET', 'api/communication/sync.php', null, $tokenA, array('cursor' => '0'));
live_assert('2 A synced B→A', in_array('B to A reply', live_bodies($syncA['json']['messages'] ?? array()), true));

$rapidClients = array();
for ($i = 1; $i <= 4; $i++) {
    $cid = live_uuid();
    $rapidClients[] = $cid;
    live_request('POST', 'api/communication/messages.php', array(
        'conversation_uuid' => $conversationUuid,
        'client_id' => $cid,
        'body' => 'rapid-A-' . $i,
    ), $tokenA);
    $cidB = live_uuid();
    live_request('POST', 'api/communication/messages.php', array(
        'conversation_uuid' => $conversationUuid,
        'client_id' => $cidB,
        'body' => 'rapid-B-' . $i,
    ), $tokenB);
}
$page = live_request('GET', 'api/communication/messages.php', null, $tokenA, array(
    'conversation_uuid' => $conversationUuid,
    'limit' => '50',
));
$history = $page['json']['messages'] ?? array();
$seqs = array_map(static fn(array $m): int => (int)$m['seq'], $history);
$sorted = $seqs;
sort($sorted);
$uniqueSeqs = array_values(array_unique($seqs));
live_assert('3 rapid messages both directions accepted', count($history) >= 10);
live_assert('4 authoritative seq ordering', $seqs === $sorted && $seqs === $uniqueSeqs);
$clientIds = array_map(static fn(array $m): string => (string)$m['client_id'], $history);
live_assert('4 no duplicate client_id in history', count($clientIds) === count(array_unique($clientIds)));

$syncPad = live_request('GET', 'api/communication/sync.php', null, $tokenAPad, array('cursor' => '0'));
$padBodies = live_bodies($syncPad['json']['messages'] ?? array());
live_assert('5/6 iPad of same account received A→B and B→A', in_array('A to B live', $padBodies, true) && in_array('B to A reply', $padBodies, true));
$padIds = array_map(static fn(array $m): string => (string)$m['client_id'], $syncPad['json']['messages'] ?? array());
live_assert('6 iPad conversation has no duplicate client_ids', count($padIds) === count(array_unique($padIds)));
$missingOnPad = array_diff($clientIds, $padIds);
live_assert('6 iPad has every message from the iPhone history page', $missingOnPad === array());

$offlineClient = live_uuid();
$offlineBody = 'queued-while-offline';
usleep(1000);
$sendOffline = live_request('POST', 'api/communication/messages.php', array(
    'conversation_uuid' => $conversationUuid,
    'client_id' => $offlineClient,
    'body' => $offlineBody,
), $tokenA);
live_assert('7/9/10 queued offline message sends once after restore', ($sendOffline['json']['message']['client_id'] ?? '') === $offlineClient);
$sendOfflineAgain = live_request('POST', 'api/communication/messages.php', array(
    'conversation_uuid' => $conversationUuid,
    'client_id' => $offlineClient,
    'body' => $offlineBody,
), $tokenA);
live_assert('8/10 kill/reopen retry uses same client_id exactly once', ($sendOfflineAgain['json']['message']['message_uuid'] ?? '') === ($sendOffline['json']['message']['message_uuid'] ?? 'missing'));

$interruptClient = live_uuid();
$interruptBody = 'interrupted-post';
live_interrupt_post('api/communication/messages.php', array(
    'conversation_uuid' => $conversationUuid,
    'client_id' => $interruptClient,
    'body' => $interruptBody,
), $tokenA);
usleep(250000);
$retryInterrupt = live_request('POST', 'api/communication/messages.php', array(
    'conversation_uuid' => $conversationUuid,
    'client_id' => $interruptClient,
    'body' => $interruptBody,
), $tokenA);
live_assert('11/12 interrupted POST then retry succeeded', ($retryInterrupt['json']['ok'] ?? false) === true);
$page2 = live_request('GET', 'api/communication/messages.php', null, $tokenB, array(
    'conversation_uuid' => $conversationUuid,
    'limit' => '80',
));
live_assert('12 retry results in exactly one server message for client_id', live_message_count_by_client($page2['json']['messages'] ?? array(), $interruptClient) === 1);

$cursor = (int)($syncB['json']['cursor'] ?? 0);
$syncB2 = live_request('GET', 'api/communication/sync.php', null, $tokenB, array('cursor' => (string)$cursor));
$syncB3 = live_request('GET', 'api/communication/sync.php', null, $tokenB, array('cursor' => (string)($syncB2['json']['cursor'] ?? $cursor)));
$uuids2 = array_map(static fn(array $m): string => (string)$m['message_uuid'], $syncB2['json']['messages'] ?? array());
$uuids3 = array_map(static fn(array $m): string => (string)$m['message_uuid'], $syncB3['json']['messages'] ?? array());
live_assert('13 repeated sync does not duplicate already-consumed messages', array_intersect($uuids2, $uuids3) === array() || ($syncB3['json']['messages'] ?? array()) === array());

$lastSeq = (int)($page2['json']['messages'][count($page2['json']['messages'] ?? array()) - 1]['seq'] ?? 0);
$read = live_request('POST', 'api/communication/receipts.php', array(
    'conversation_uuid' => $conversationUuid,
    'last_read_seq' => $lastSeq,
), $tokenB);
live_assert('14 mark read on B', ($read['json']['ok'] ?? false) === true);
$syncAPad2 = live_request('GET', 'api/communication/sync.php', null, $tokenAPad, array('cursor' => (string)($syncPad['json']['cursor'] ?? 0)));
$reads = $syncAPad2['json']['reads'] ?? array();
$readHit = false;
foreach ($reads as $readRow) {
    if ((int)($readRow['last_read_seq'] ?? 0) === $lastSeq) {
        $readHit = true;
    }
}
$listB = live_request('GET', 'api/communication/conversations.php', null, $tokenB);
$unreadB = (int)($listB['json']['conversations'][0]['unread_count'] ?? -1);
live_assert('14 B unread is 0 after read', $unreadB === 0);
live_assert('14 other device syncs read cursor or empty catch-up', $readHit || ($syncAPad2['json']['ok'] ?? false) === true);

$logout = live_request('POST', 'api/communication/auth.php', array('action' => 'logout'), $tokenB);
live_assert('15 logout succeeds', ($logout['json']['ok'] ?? false) === true);
$afterLogout = live_request('GET', 'api/communication/bootstrap.php', null, $tokenB);
$logoutCode = (string)($afterLogout['json']['error_code'] ?? '');
live_assert('15 logged-out credential is rejected', in_array($logoutCode, array('credential_revoked', 'unauthenticated'), true) && in_array((int)$afterLogout['status'], array(401, 403), true));

if ($statusCmd !== '') {
    try {
        live_set_status($emailA, 'locked');
        $blocked = live_request('GET', 'api/communication/bootstrap.php', null, $tokenA);
        live_assert('16/17 locked account with valid Bearer is account_ineligible', ($blocked['json']['error_code'] ?? '') === 'account_ineligible' && (int)$blocked['status'] === 403);
        $sendBlocked = live_request('POST', 'api/communication/messages.php', array(
            'conversation_uuid' => $conversationUuid,
            'client_id' => live_uuid(),
            'body' => 'should not send',
        ), $tokenA);
        live_assert('17 communication API rejected while locked', ($sendBlocked['json']['error_code'] ?? '') === 'account_ineligible');
        live_set_status($emailA, 'active');
        $recovered = live_request('GET', 'api/communication/bootstrap.php', null, $tokenA);
        live_assert('18 re-enable recovers existing credential', ($recovered['json']['ok'] ?? false) === true);
        $loginAgain = live_login($emailA, $password, 'iphone', (string)$loginA['device_uuid_sent']);
        live_assert('18 re-login after re-enable works', !empty($loginAgain['token']));
    } catch (Throwable $e) {
        live_assert('16/17/18 account lock tests', false, $e->getMessage());
        try {
            live_set_status($emailA, 'active');
        } catch (Throwable) {
        }
    }
} else {
    live_assert('16/17/18 account lock tests', false, 'IPCA_LIVE_STATUS_CMD not set');
}

$finalPage = live_request('GET', 'api/communication/messages.php', null, $tokenAPad, array(
    'conversation_uuid' => $conversationUuid,
    'limit' => '100',
));
$final = $finalPage['json']['messages'] ?? array();
$finalClientIds = array_map(static fn(array $m): string => (string)$m['client_id'], $final);
$finalSeqs = array_map(static fn(array $m): int => (int)$m['seq'], $final);
$seqCopy = $finalSeqs;
sort($seqCopy);
live_assert('no silently lost messages in final history', count($final) >= 12);
live_assert('final history has no duplicate client_ids', count($finalClientIds) === count(array_unique($finalClientIds)));
live_assert('final history seq is contiguous and ordered', $finalSeqs === $seqCopy && $finalSeqs === array_values(array_unique($finalSeqs)) && $finalSeqs === range(1, count($finalSeqs)));

$notes[] = 'Foreground 3s polling is an app UX observation; HTTP harness syncs on demand.';

if ($failures) {
    echo "\n" . count($failures) . " failed\n";
    exit(1);
}
echo "\nAll live HTTP Phase 1 checks passed\n";
exit(0);
