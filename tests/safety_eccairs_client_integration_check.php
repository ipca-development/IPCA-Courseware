<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/safety/SafetyEccairsService.php';

putenv('CW_ECCAIRS_SANDBOX_USERNAME=test-user');
putenv('CW_ECCAIRS_SANDBOX_PASSWORD=test-password');
putenv('CW_ECCAIRS_SANDBOX_CLIENT_ID=test-client');
putenv('CW_ECCAIRS_SANDBOX_CLIENT_SECRET=test-secret');

$calls = array();
$loginCount = 0;
$createCount = 0;
$transport = static function (
    string $method,
    string $url,
    array $headers,
    ?string $body
) use (&$calls, &$loginCount, &$createCount): array {
    $calls[] = array('method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body);
    if (str_ends_with($url, '/auth/api/token')) {
        $loginCount++;
        return array(
            'status' => 200,
            'body' => json_encode(array(
                'access_token' => 'mock-token-' . $loginCount,
                'refresh_token' => 'mock-refresh-' . $loginCount,
            ), JSON_THROW_ON_ERROR),
        );
    }
    if (str_ends_with($url, '/occurrences/create')) {
        $createCount++;
        if ($createCount === 1) {
            return array('status' => 401, 'body' => '{"message":"expired"}');
        }
        return array(
            'status' => 200,
            'body' => json_encode(array(
                'data' => array('e2Id' => 'OR-MOCK-1', 'status' => 'SENT', 'version' => '0.1'),
                'errorDetails' => '',
                'returnCode' => 1,
            ), JSON_THROW_ON_ERROR),
        );
    }
    if (str_contains($url, '/occurrences/get/')) {
        return array(
            'status' => 200,
            'body' => json_encode(array(
                'data' => array('e2Id' => 'OR-MOCK-1', 'status' => 'PROCESSED', 'version' => '0.2'),
                'returnCode' => 1,
            ), JSON_THROW_ON_ERROR),
        );
    }
    return array('status' => 404, 'body' => '{}');
};

$config = (new ReflectionClass(SafetyEccairsConfig::class))->newInstanceWithoutConstructor();
$client = new SafetyEccairsApiClient($config, $transport);
$connection = array(
    'environment' => 'sandbox',
    'base_url' => 'https://example.invalid',
    'token_path' => '/auth/api/token',
    'create_path' => '/occurrences/create',
    'get_path_template' => '/occurrences/get/{e2id}',
);
$payload = array(
    'type' => 'REPORT',
    'status' => 'SENT',
    'reportingEntityId' => 123,
    'taxonomyCodes' => array('24' => array('ID' => 'IPCA-MOCK')),
);
$created = $client->create($connection, $payload);
$fetched = $client->get($connection, 'OR-MOCK-1');
$uncertainConfig = (new ReflectionClass(SafetyEccairsConfig::class))->newInstanceWithoutConstructor();
$uncertainCalls = 0;
$uncertainClient = new SafetyEccairsApiClient(
    $uncertainConfig,
    static function () use (&$uncertainCalls): array {
        $uncertainCalls++;
        if ($uncertainCalls === 1) {
            return array('status' => 200, 'body' => '{"access_token":"mock-token"}');
        }
        throw new SafetyException('eccairs_transport_error', 'Simulated timeout.', 503);
    }
);
$uncertainCode = null;
try {
    $uncertainClient->create($connection, $payload);
} catch (SafetyException $e) {
    $uncertainCode = $e->errorCode;
}

$checks = array(
    'client refreshes once after an unauthorized create' =>
        $loginCount === 2 && $createCount === 2,
    'client returns accepted E2 response without changing payload' =>
        ($created['body']['data']['e2Id'] ?? null) === 'OR-MOCK-1'
        && json_decode((string)$calls[3]['body'], true) === $payload,
    'refreshed bearer token is used for retry and status query' =>
        ($calls[3]['headers']['Authorization'] ?? '') === 'Bearer mock-token-2'
        && ($calls[4]['headers']['Authorization'] ?? '') === 'Bearer mock-token-2',
    'status query URL-encodes the remote identifier' =>
        str_ends_with((string)$calls[4]['url'], '/occurrences/get/OR-MOCK-1')
        && ($fetched['body']['data']['status'] ?? null) === 'PROCESSED',
    'token request uses form encoding and basic client authorization' =>
        ($calls[0]['headers']['Content-Type'] ?? '') === 'application/x-www-form-urlencoded'
        && str_starts_with((string)($calls[0]['headers']['Authorization'] ?? ''), 'Basic ')
        && str_contains((string)$calls[0]['body'], 'grant_type=password'),
    'expired access token uses the in-memory OAuth refresh token' =>
        str_contains((string)$calls[2]['body'], 'grant_type=refresh_token')
        && str_contains((string)$calls[2]['body'], 'refresh_token=mock-refresh-1')
        && !str_contains((string)$calls[2]['body'], 'username='),
    'transport failure after authentication is classified as delivery uncertain' =>
        $uncertainCode === 'eccairs_delivery_uncertain',
);

foreach (array(
    'CW_ECCAIRS_SANDBOX_USERNAME',
    'CW_ECCAIRS_SANDBOX_PASSWORD',
    'CW_ECCAIRS_SANDBOX_CLIENT_ID',
    'CW_ECCAIRS_SANDBOX_CLIENT_SECRET',
) as $key) {
    putenv($key);
}

$failed = array();
foreach ($checks as $name => $passed) {
    echo ($passed ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$passed) {
        $failed[] = $name;
    }
}
if ($failed !== array()) {
    fwrite(STDERR, 'Failed ECCAIRS client integration checks: ' . implode(', ', $failed) . PHP_EOL);
    exit(1);
}
echo 'OK: ECCAIRS client integration checks passed.' . PHP_EOL;
