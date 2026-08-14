<?php
declare(strict_types=1);

require_once __DIR__ . '/CommunicationSupport.php';

final class CommunicationApnsSendResult
{
    public function __construct(
        public readonly bool $accepted,
        public readonly int $httpStatus,
        public readonly string $reason,
        public readonly bool $invalidateToken
    ) {
    }
}

interface CommunicationPushTransport
{
    public function isReady(): bool;

    /**
     * @param array<string,mixed> $payload
     */
    public function send(string $deviceToken, string $environment, array $payload): CommunicationApnsSendResult;
}

final class CommunicationPushNullTransport implements CommunicationPushTransport
{
    public function isReady(): bool
    {
        return false;
    }

    public function send(string $deviceToken, string $environment, array $payload): CommunicationApnsSendResult
    {
        return new CommunicationApnsSendResult(false, 0, 'not_configured', false);
    }
}

final class CommunicationPushRecordingTransport implements CommunicationPushTransport
{
    /** @var array<int,array<string,mixed>> */
    public array $sent = array();

    public function isReady(): bool
    {
        return true;
    }

    public function send(string $deviceToken, string $environment, array $payload): CommunicationApnsSendResult
    {
        $this->sent[] = array(
            'device_token' => $deviceToken,
            'environment' => $environment,
            'payload' => $payload,
        );
        return new CommunicationApnsSendResult(true, 200, 'recorded', false);
    }
}

final class CommunicationApnsClient implements CommunicationPushTransport
{
    private const TOKEN_TTL = 2700;

    private ?string $cachedJwt = null;
    private int $cachedJwtAt = 0;

    public function __construct(
        private string $keyId,
        private string $teamId,
        private string $bundleId,
        private string $privateKeyPem
    ) {
    }

    public static function fromEnvironment(): CommunicationPushTransport
    {
        if (class_exists('RuntimeSecrets', false) || is_file(dirname(__DIR__) . '/RuntimeSecrets.php')) {
            require_once dirname(__DIR__) . '/RuntimeSecrets.php';
            RuntimeSecrets::ensureCliEnvLoaded();
        }
        $keyId = trim((string)(getenv('IPCA_APNS_KEY_ID') ?: getenv('CW_APNS_KEY_ID') ?: ''));
        $teamId = trim((string)(getenv('IPCA_APNS_TEAM_ID') ?: getenv('CW_APNS_TEAM_ID') ?: 'W9RY547Y4P'));
        $bundleId = trim((string)(getenv('IPCA_APNS_BUNDLE_ID') ?: getenv('CW_APNS_BUNDLE_ID') ?: 'training.ipca.app'));
        $pem = self::loadPrivateKeyPem();
        if ($keyId === '' || $teamId === '' || $bundleId === '' || $pem === '') {
            return new CommunicationPushNullTransport();
        }
        return new self($keyId, $teamId, $bundleId, $pem);
    }

    public function isReady(): bool
    {
        return $this->keyId !== '' && $this->teamId !== '' && $this->privateKeyPem !== '';
    }

    public function send(string $deviceToken, string $environment, array $payload): CommunicationApnsSendResult
    {
        $token = strtolower(preg_replace('/[^0-9a-f]/', '', $deviceToken) ?? '');
        if ($token === '') {
            return new CommunicationApnsSendResult(false, 400, 'bad_device_token', true);
        }
        $host = $environment === 'production' ? 'api.push.apple.com' : 'api.sandbox.push.apple.com';
        $url = 'https://' . $host . '/3/device/' . $token;
        $body = json_encode($payload, CommunicationSupport::jsonFlags());
        if (!is_string($body) || $body === '') {
            return new CommunicationApnsSendResult(false, 500, 'payload_encode_failed', false);
        }

        $jwt = $this->jwt();
        $headers = array(
            'authorization: bearer ' . $jwt,
            'apns-topic: ' . $this->bundleId,
            'apns-push-type: alert',
            'apns-priority: 10',
            'apns-collapse-id: ' . substr((string)($payload['conversation_uuid'] ?? 'ipca'), 0, 64),
            'content-type: application/json',
        );

        $ch = curl_init($url);
        if ($ch === false) {
            return new CommunicationApnsSendResult(false, 0, 'curl_init_failed', false);
        }
        curl_setopt_array($ch, array(
            CURLOPT_POST => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2_0,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => 8,
        ));
        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false) {
            return new CommunicationApnsSendResult(false, $status, $err !== '' ? $err : 'curl_failed', false);
        }

        $headerSize = strpos($raw, "\r\n\r\n");
        $responseBody = is_int($headerSize) ? substr($raw, $headerSize + 4) : '';
        $decoded = json_decode($responseBody, true);
        $reason = '';
        if (is_array($decoded) && isset($decoded['reason'])) {
            $reason = (string)$decoded['reason'];
        }
        if ($status === 200) {
            return new CommunicationApnsSendResult(true, 200, 'accepted', false);
        }
        $invalidate = in_array($reason, array('BadDeviceToken', 'Unregistered', 'ExpiredProviderToken', 'DeviceTokenNotForTopic'), true)
            || $status === 410;
        if ($reason === '') {
            $reason = 'http_' . $status;
        }
        return new CommunicationApnsSendResult(false, $status, $reason, $invalidate);
    }

    public static function jwtFromPem(string $keyId, string $teamId, string $pem, int $issuedAt): string
    {
        $header = self::b64url(json_encode(array('alg' => 'ES256', 'kid' => $keyId), JSON_UNESCAPED_SLASHES) ?: '');
        $claims = self::b64url(json_encode(array('iss' => $teamId, 'iat' => $issuedAt), JSON_UNESCAPED_SLASHES) ?: '');
        $signingInput = $header . '.' . $claims;
        $key = openssl_pkey_get_private($pem);
        if ($key === false) {
            throw new RuntimeException('APNs private key is invalid.');
        }
        $signature = '';
        if (!openssl_sign($signingInput, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('APNs JWT signing failed.');
        }
        return $signingInput . '.' . self::b64url(self::derToJose($signature));
    }

    private function jwt(): string
    {
        $now = time();
        if ($this->cachedJwt !== null && ($now - $this->cachedJwtAt) < self::TOKEN_TTL) {
            return $this->cachedJwt;
        }
        $this->cachedJwt = self::jwtFromPem($this->keyId, $this->teamId, $this->privateKeyPem, $now);
        $this->cachedJwtAt = $now;
        return $this->cachedJwt;
    }

    private static function loadPrivateKeyPem(): string
    {
        $inline = trim((string)(getenv('IPCA_APNS_KEY_P8') ?: getenv('CW_APNS_KEY_P8') ?: ''));
        if ($inline !== '') {
            $inline = str_replace('\\n', "\n", $inline);
            if (!str_contains($inline, 'BEGIN')) {
                $inline = "-----BEGIN PRIVATE KEY-----\n" . trim($inline) . "\n-----END PRIVATE KEY-----\n";
            }
            return $inline;
        }
        $path = trim((string)(getenv('IPCA_APNS_KEY_PATH') ?: getenv('CW_APNS_KEY_PATH') ?: ''));
        if ($path === '') {
            $root = dirname(__DIR__, 2);
            foreach (glob($root . '/secrets/AuthKey_*.p8') ?: array() as $candidate) {
                $path = $candidate;
                break;
            }
        }
        if ($path === '' || !is_readable($path)) {
            return '';
        }
        $pem = trim((string)file_get_contents($path));
        if ($pem !== '' && !str_contains($pem, 'BEGIN')) {
            $pem = "-----BEGIN PRIVATE KEY-----\n" . $pem . "\n-----END PRIVATE KEY-----\n";
        }
        return $pem;
    }

    private static function b64url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function derToJose(string $der): string
    {
        $offset = 0;
        $len = strlen($der);
        if ($len < 8 || ord($der[$offset++]) !== 0x30) {
            throw new RuntimeException('APNs signature is not a DER sequence.');
        }
        $seqLen = ord($der[$offset++]);
        if ($seqLen & 0x80) {
            $count = $seqLen & 0x7f;
            $offset += $count;
        }
        $r = self::readDerInt($der, $offset);
        $s = self::readDerInt($der, $offset);
        return $r . $s;
    }

    private static function readDerInt(string $der, int &$offset): string
    {
        if (!isset($der[$offset]) || ord($der[$offset]) !== 0x02) {
            throw new RuntimeException('APNs signature is missing an integer.');
        }
        $offset++;
        $len = ord($der[$offset++]);
        $raw = substr($der, $offset, $len);
        $offset += $len;
        $raw = ltrim($raw, "\x00");
        if (strlen($raw) > 32) {
            $raw = substr($raw, -32);
        }
        return str_pad($raw, 32, "\x00", STR_PAD_LEFT);
    }
}
