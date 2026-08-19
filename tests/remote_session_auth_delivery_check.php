#!/usr/bin/env php
<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/src/remote_session_auth/remote_session_auth_delivery.php';

$failures = [];

function rsa_delivery_assert(string $name, bool $ok): void
{
    global $failures;
    if ($ok) {
        echo "PASS  {$name}\n";
        return;
    }
    echo "FAIL  {$name}\n";
    $failures[] = $name;
}

putenv('CW_REMOTE_AUTH_START_CHANNEL=email');
putenv('CW_REMOTE_CODE_DELIVERY_CHANNEL');

rsa_delivery_assert('auth start email path is retired', rsa_auth_start_channel() === 'browser');
rsa_delivery_assert('default code delivery channel is app', rsa_code_delivery_channel() === 'app');
rsa_delivery_assert('app code delivery is wired', rsa_app_code_delivery_available() === true);

putenv('CW_REMOTE_CODE_DELIVERY_CHANNEL=app');
rsa_delivery_assert('app code delivery can be selected', rsa_code_delivery_channel() === 'app');

putenv('CW_REMOTE_CODE_DELIVERY_CHANNEL=auth_page');
rsa_delivery_assert('auth_page remains available as an override', rsa_code_delivery_channel() === 'auth_page');

putenv('CW_REMOTE_CODE_DELIVERY_CHANNEL=app');

$payload = rsa_remote_delivery_payload('https://ipca.training/student/progress_test_auth.php?token=abc', [
    'kind' => 'progress_test',
]);
rsa_delivery_assert('browser payload includes auth_url', ($payload['auth_url'] ?? '') !== '');
rsa_delivery_assert('browser payload asks the UI to open auth', !empty($payload['open_auth_in_browser']));
rsa_delivery_assert('payload delivers the code via the app', !empty($payload['deliver_code_via_app']));

putenv('CW_REMOTE_CODE_DELIVERY_CHANNEL=auth_page');
$authPageFields = rsa_with_code_delivery_fields(
    ['ok' => true],
    '123456',
    'progress_test_code',
    ['kind' => 'progress_test']
);
rsa_delivery_assert('auth_page still returns the plaintext code', ($authPageFields['progress_test_code'] ?? '') === '123456');
rsa_delivery_assert('auth_page show_code_on_page is true', !empty($authPageFields['show_code_on_page']));
putenv('CW_REMOTE_CODE_DELIVERY_CHANNEL=app');

$courseJs = (string)file_get_contents($root . '/public/student/course.php');
rsa_delivery_assert('course page opens the auth URL in a browser window', str_contains($courseJs, 'openRemoteAuthSession') && str_contains($courseJs, 'open_auth_in_browser'));
rsa_delivery_assert('course page tells students to check the app', str_contains($courseJs, 'check the IPCA app'));
rsa_delivery_assert('course page no longer tells students to check email', !str_contains($courseJs, 'Check your email') && !str_contains($courseJs, 'check email'));

$remotePhp = (string)file_get_contents($root . '/src/courseware_progression_v2_remote.php');
rsa_delivery_assert('progress test issues an in-browser auth session', str_contains($remotePhp, 'ptr_issue_remote_auth_session'));
rsa_delivery_assert('progress test delivers the code through the app helper', str_contains($remotePhp, 'rsa_with_code_delivery_fields'));
rsa_delivery_assert('progress test website copy does not say check email', !str_contains($remotePhp, 'Check your email') && !str_contains($remotePhp, 'Check your inbox'));

$mockPhp = (string)file_get_contents($root . '/src/courseware_progression_v2_mock_oral.php');
rsa_delivery_assert('mock oral website copy does not say check email', !str_contains($mockPhp, 'Check your email'));

$deliveryPhp = (string)file_get_contents($root . '/src/remote_session_auth/remote_session_auth_delivery.php');
rsa_delivery_assert('app code delivery persists through the app service', str_contains($deliveryPhp, 'RemoteSessionAppCodeService'));
rsa_delivery_assert('app code delivery sends APNs without using a chat body', str_contains($deliveryPhp, 'notifyRemoteSessionCode'));

$pushPhp = (string)file_get_contents($root . '/src/communication/CommunicationPushService.php');
rsa_delivery_assert('APNs payload uses remote_session_code type', str_contains($pushPhp, "'type' => 'remote_session_code'"));
rsa_delivery_assert('APNs alert does not include a code placeholder', !str_contains($pushPhp, 'progress_test_code') && str_contains($pushPhp, 'Open IPCA to view it.'));

rsa_delivery_assert(
    'remote session code API exists',
    is_file($root . '/public/api/communication/remote_session_code.php')
);

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$service = new RemoteSessionAppCodeService($pdo);
$stored = $service->persist('654321', array(
    'student_id' => 41,
    'kind' => 'progress_test',
    'authorization_id' => 9,
));
rsa_delivery_assert('app code persist returns a UUID', is_array($stored) && CommunicationSupport::isUuid((string)($stored['code_uuid'] ?? '')));
$envelope = $service->publicEnvelope(41, (string)$stored['code_uuid']);
rsa_delivery_assert('app code envelope reveals six digits once', ($envelope['code'] ?? '') === '654321' && empty($envelope['viewed']));
$actions = $service->pendingTrainingActions(41);
rsa_delivery_assert(
    'training action includes code_id and no digits',
    ($actions[0]['source'] ?? '') === 'remote_session_code'
    && ($actions[0]['code_id'] ?? '') === $stored['code_uuid']
    && !str_contains(json_encode($actions) ?: '', '654321')
);
$service->markViewed(41, (string)$stored['code_uuid']);
$after = $service->publicEnvelope(41, (string)$stored['code_uuid']);
rsa_delivery_assert('viewed app code omits digits', ($after['code'] ?? '') === '' && !empty($after['viewed']));

$browserMessage = rsa_browser_auth_message('progress_test', false);
rsa_delivery_assert('browser prompt tells the student to check the app', str_contains($browserMessage, 'Check the app'));

if ($failures) {
    fwrite(STDERR, "\n" . count($failures) . " check(s) failed.\n");
    exit(1);
}

echo "\nAll remote session delivery checks passed.\n";
exit(0);
