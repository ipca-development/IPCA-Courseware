<?php
declare(strict_types=1);

final class SafetyException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 400
    ) {
        parent::__construct($message);
    }
}

final class SafetySupport
{
    public static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }

    public static function nowUtc(): string
    {
        return gmdate('Y-m-d H:i:s') . sprintf('.%03d', (int)((microtime(true) * 1000) % 1000));
    }

    public static function token(int $bytes = 32): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    public static function secretHash(string $secret): string
    {
        return password_hash($secret, PASSWORD_ARGON2ID);
    }

    public static function digest(string $value): string
    {
        return hash('sha256', $value);
    }

    /**
     * The caller must pass only a transient network value. The returned HMAC is
     * the only value suitable for persistence; raw network values are never logged.
     */
    public static function rateLimitFingerprint(string $transientNetworkValue): string
    {
        $key = trim((string)getenv('CW_SAFETY_RATE_LIMIT_KEY'));
        if ($key === '') {
            throw new SafetyException('server_configuration_error', 'Anonymous intake is temporarily unavailable.', 503);
        }
        return hash_hmac('sha256', $transientNetworkValue, $key);
    }

    public static function reporterSubjectHash(int $organizationId, int $userId): string
    {
        $key = trim((string)getenv('CW_SAFETY_IDENTITY_LOOKUP_KEY'));
        if ($key === '') {
            throw new SafetyException(
                'server_configuration_error',
                'Confidential safety reporting is temporarily unavailable.',
                503
            );
        }
        return hash_hmac('sha256', $organizationId . ':user:' . $userId, $key);
    }

    public static function analyticsReferenceHash(int $organizationId, string $reference): string
    {
        $key = trim((string)getenv('CW_SAFETY_ANALYTICS_LINK_KEY'));
        if ($key === '') {
            throw new SafetyException('server_configuration_error', 'Safety correlation is unavailable.', 503);
        }
        return hash_hmac('sha256', $organizationId . ':' . $reference, $key);
    }

    /** @return array{ciphertext:string,key_reference:string,identity_digest:string} */
    public static function encryptReporterIdentity(int $organizationId, int $userId): array
    {
        if (!function_exists('sodium_crypto_secretbox')) {
            throw new SafetyException(
                'server_configuration_error',
                'Confidential safety reporting is temporarily unavailable.',
                503
            );
        }
        $encodedKey = trim((string)getenv('CW_SAFETY_VAULT_KEY'));
        $key = base64_decode($encodedKey, true);
        if (!is_string($key) || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new SafetyException(
                'server_configuration_error',
                'Confidential safety reporting is temporarily unavailable.',
                503
            );
        }
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plaintext = self::json(array(
            'organization_id' => $organizationId,
            'user_id' => $userId,
        ));
        $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $key);
        return array(
            'ciphertext' => $nonce . $ciphertext,
            'key_reference' => trim((string)getenv('CW_SAFETY_VAULT_KEY_REFERENCE')) ?: 'env:v1',
            'identity_digest' => self::reporterSubjectHash($organizationId, $userId),
        );
    }

    /** @return array{organization_id:int,user_id:int} */
    public static function decryptReporterIdentity(string $ciphertext): array
    {
        if (!function_exists('sodium_crypto_secretbox_open')) {
            throw new SafetyException('server_configuration_error', 'Reporter vault is unavailable.', 503);
        }
        $key = base64_decode(trim((string)getenv('CW_SAFETY_VAULT_KEY')), true);
        if (!is_string($key) || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES
            || strlen($ciphertext) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new SafetyException('server_configuration_error', 'Reporter vault is unavailable.', 503);
        }
        $nonce = substr($ciphertext, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $encrypted = substr($ciphertext, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plaintext = sodium_crypto_secretbox_open($encrypted, $nonce, $key);
        if (!is_string($plaintext)) {
            throw new SafetyException('vault_integrity_error', 'Reporter vault entry could not be verified.', 500);
        }
        $identity = json_decode($plaintext, true);
        if (!is_array($identity) || (int)($identity['organization_id'] ?? 0) < 1
            || (int)($identity['user_id'] ?? 0) < 1) {
            throw new SafetyException('vault_integrity_error', 'Reporter vault entry is invalid.', 500);
        }
        return array(
            'organization_id' => (int)$identity['organization_id'],
            'user_id' => (int)$identity['user_id'],
        );
    }

    /**
     * @param array<string,mixed> $session
     */
    public static function organizationId(array $session): int
    {
        $organizationId = (int)($session['user']['organization_id'] ?? $session['device']['organization_id'] ?? 1);
        return max(1, $organizationId);
    }

    /**
     * @param mixed $value
     */
    public static function json($value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    public static function cleanText(string $value, int $max, string $field, bool $required = true): string
    {
        $value = trim(str_replace("\0", '', $value));
        if ($required && $value === '') {
            throw new SafetyException('validation_error', $field . ' is required.', 400);
        }
        if (mb_strlen($value) > $max) {
            throw new SafetyException('validation_error', $field . ' is too long.', 400);
        }
        return $value;
    }
}

final class SafetyRateLimitService
{
    public function __construct(private PDO $pdo)
    {
    }

    public function consume(int $organizationId, string $action, string $fingerprintHmac, int $limit, int $windowSeconds): void
    {
        $windowEpoch = intdiv(time(), $windowSeconds) * $windowSeconds;
        $window = gmdate('Y-m-d H:i:s', $windowEpoch) . '.000';
        $this->pdo->prepare(
            'INSERT INTO ipca_safety_rate_limits
             (organization_id, action_code, fingerprint_hmac, window_started_at_utc, request_count)
             VALUES (?, ?, ?, ?, 1)
             ON DUPLICATE KEY UPDATE request_count = request_count + 1'
        )->execute(array($organizationId, $action, $fingerprintHmac, $window));

        $stmt = $this->pdo->prepare(
            'SELECT request_count, blocked_until_utc FROM ipca_safety_rate_limits
             WHERE organization_id = ? AND action_code = ? AND fingerprint_hmac = ? AND window_started_at_utc = ?'
        );
        $stmt->execute(array($organizationId, $action, $fingerprintHmac, $window));
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: array();
        if ((int)($row['request_count'] ?? 0) > $limit) {
            $blockedUntil = gmdate('Y-m-d H:i:s', $windowEpoch + $windowSeconds) . '.000';
            $this->pdo->prepare(
                'UPDATE ipca_safety_rate_limits SET blocked_until_utc = ?
                 WHERE organization_id = ? AND action_code = ? AND fingerprint_hmac = ? AND window_started_at_utc = ?'
            )->execute(array($blockedUntil, $organizationId, $action, $fingerprintHmac, $window));
            throw new SafetyException('rate_limited', 'Too many requests. Try again later.', 429);
        }
    }
}
