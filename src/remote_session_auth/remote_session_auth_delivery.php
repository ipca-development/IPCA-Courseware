<?php
declare(strict_types=1);

require_once __DIR__ . '/RemoteSessionAppCodeService.php';

/**
 * Remote session delivery channels (progress tests + mock orals).
 *
 * auth_start_channel: browser only. The authentication-link email is retired.
 * code_delivery_channel: app (default). The one-time code is revealed in the IPCA app.
 *
 * Env override:
 *   CW_REMOTE_CODE_DELIVERY_CHANNEL=app|auth_page
 */

function rsa_auth_start_channel(): string
{
    return 'browser';
}

function rsa_code_delivery_channel(): string
{
    if (!rsa_app_code_delivery_available()) {
        return 'auth_page';
    }
    $value = strtolower(trim((string)(getenv('CW_REMOTE_CODE_DELIVERY_CHANNEL') ?: 'app')));
    if (!in_array($value, array('auth_page', 'app'), true)) {
        $value = 'app';
    }
    return $value;
}

function rsa_app_auth_start_available(): bool
{
    return false;
}

function rsa_app_code_delivery_available(): bool
{
    return true;
}

/**
 * @param array<string,mixed> $context
 * @return array<string,mixed>
 */
function rsa_remote_delivery_payload(string $authUrl, array $context = array()): array
{
    $startChannel = rsa_auth_start_channel();
    $codeChannel = rsa_code_delivery_channel();

    return array(
        'auth_url' => $authUrl,
        'auth_start_channel' => $startChannel,
        'code_delivery_channel' => $codeChannel,
        'open_auth_in_browser' => true,
        'deliver_code_via_app' => $codeChannel === 'app',
        'kind' => (string)($context['kind'] ?? ''),
    );
}

/**
 * @param array<string,mixed> $context
 * @return array{channel:string,show_code_on_page:bool,delivered:bool}
 */
function rsa_deliver_verification_code(string $code, array $context = array()): array
{
    $channel = rsa_code_delivery_channel();
    if ($channel === 'app') {
        $sent = rsa_deliver_verification_code_via_app($code, $context);
        if ($sent) {
            return array(
                'channel' => 'app',
                'show_code_on_page' => false,
                'delivered' => true,
            );
        }
        $channel = 'auth_page';
    }

    return array(
        'channel' => $channel,
        'show_code_on_page' => true,
        'delivered' => true,
    );
}

/**
 * @param array<string,mixed> $context
 */
function rsa_deliver_verification_code_via_app(string $code, array $context = array()): bool
{
    $pdo = $context['pdo'] ?? null;
    if (!$pdo instanceof PDO && function_exists('cw_db')) {
        try {
            $pdo = cw_db();
        } catch (Throwable) {
            $pdo = null;
        }
    }
    if (!$pdo instanceof PDO) {
        return false;
    }

    try {
        $stored = (new RemoteSessionAppCodeService($pdo))->persist($code, $context);
    } catch (Throwable) {
        return false;
    }
    if ($stored === null) {
        return false;
    }

    $userId = (int)($context['student_id'] ?? $context['user_id'] ?? 0);
    rsa_notify_remote_session_code($pdo, $userId, $stored['code_uuid'], $stored['kind']);
    return true;
}

function rsa_notify_remote_session_code(PDO $pdo, int $userId, string $codeUuid, string $kind): void
{
    if ($userId < 1 || $codeUuid === '') {
        return;
    }
    try {
        require_once dirname(__DIR__) . '/communication/CommunicationKernel.php';
        $kernel = new CommunicationKernel($pdo);
        $kernel->push->notifyRemoteSessionCode($userId, $codeUuid, $kind);
    } catch (Throwable $e) {
        if (class_exists('CommunicationSupport')) {
            CommunicationSupport::log('communication.remote_session_code.push.error', array(
                'error' => $e->getMessage(),
            ));
        }
    }
}

function rsa_consume_app_code(PDO $pdo, int $userId, string $kind, int $authorizationId): void
{
    try {
        (new RemoteSessionAppCodeService($pdo))->consumeForAuthorization($userId, $kind, $authorizationId);
    } catch (Throwable) {
    }
}

/**
 * @param array<string,mixed> $payload
 * @param array<string,mixed> $context
 * @return array<string,mixed>
 */
function rsa_with_code_delivery_fields(array $payload, string $code, string $codeKey, array $context = array()): array
{
    $delivery = rsa_deliver_verification_code($code, $context);
    $payload['code_delivery_channel'] = $delivery['channel'];
    $payload['show_code_on_page'] = $delivery['show_code_on_page'];
    $payload[$codeKey] = $delivery['show_code_on_page'] ? $code : '';
    return $payload;
}

function rsa_browser_auth_message(string $kind, bool $reissued): string
{
    $codeLabel = $kind === 'mock_oral' ? 'Mock Oral Code' : 'Progress Test Code';
    $prefix = $reissued ? 'Authentication reopened. ' : '';
    return $prefix
        . 'Complete photo and password verification in the authentication window. '
        . 'Your ' . $codeLabel . ' will appear in the IPCA app. Check the app, write the code down, then enter it on this page.';
}
