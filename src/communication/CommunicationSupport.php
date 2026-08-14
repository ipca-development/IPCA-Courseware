<?php
declare(strict_types=1);

require_once __DIR__ . '/../AuditEventService.php';
require_once __DIR__ . '/CommunicationException.php';

final class CommunicationSupport
{
    public const MAX_BODY_CHARS = 8000;
    public const SYNC_PAGE_SIZE = 200;
    public const DIRECTORY_LIMIT = 50;

    public static function uuid(): string
    {
        return AuditEventService::uuid();
    }

    public static function hashSecret(string $secret): string
    {
        return hash('sha256', $secret);
    }

    public static function nowUtc(): string
    {
        $micro = microtime(true);
        $seconds = (int)floor($micro);
        $ms = (int)round(($micro - $seconds) * 1000);
        if ($ms >= 1000) {
            $seconds++;
            $ms = 0;
        }
        return gmdate('Y-m-d H:i:s', $seconds) . sprintf('.%03d', $ms);
    }

    public static function isUuid(string $value): bool
    {
        // Courseware users.uuid values are 8-4-4-4-12 hex, but many are not RFC 4122
        // version/variant UUIDs. Treat the stored identity string as canonical.
        return (bool)preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $value
        );
    }

    public static function requireUuid(string $value, string $field): string
    {
        $value = strtolower(trim($value));
        if (!self::isUuid($value)) {
            throw new CommunicationException('validation_error', $field . ' must be a UUID.', 400);
        }
        return $value;
    }

    /**
     * @param array<string,mixed> $user
     */
    public static function userIsEligible(array $user): bool
    {
        $status = strtolower(trim((string)($user['status'] ?? '')));
        if ($status !== 'active') {
            return false;
        }
        $until = trim((string)($user['account_valid_until'] ?? ''));
        if ($until !== '') {
            $ts = strtotime($until . ' UTC');
            if ($ts !== false && $ts < time()) {
                return false;
            }
        }
        return true;
    }

    public static function ineligibleReason(array $user): string
    {
        $status = strtolower(trim((string)($user['status'] ?? '')));
        if ($status === 'locked') {
            return 'This account is locked.';
        }
        if ($status === 'retired') {
            return 'This account is no longer active.';
        }
        if ($status === 'pending_activation' || $status === 'pending') {
            return 'This account is not yet activated.';
        }
        $until = trim((string)($user['account_valid_until'] ?? ''));
        if ($until !== '') {
            $ts = strtotime($until . ' UTC');
            if ($ts !== false && $ts < time()) {
                return 'This account has expired.';
            }
        }
        return 'This account cannot use IPCA messaging.';
    }

    /**
     * @param array<string,mixed> $user
     * @return array<string,mixed>
     */
    public static function publicUser(array $user): array
    {
        return array(
            'id' => (int)$user['id'],
            'uuid' => (string)($user['uuid'] ?? ''),
            'email' => (string)($user['email'] ?? ''),
            'name' => (string)($user['name'] ?? ''),
            'first_name' => (string)($user['first_name'] ?? ''),
            'last_name' => (string)($user['last_name'] ?? ''),
            'role' => (string)($user['role'] ?? ''),
            'photo_path' => (string)($user['photo_path'] ?? ''),
        );
    }

    public static function directPairKey(int $userA, int $userB): string
    {
        $min = min($userA, $userB);
        $max = max($userA, $userB);
        return hash('sha256', $min . ':' . $max);
    }

    public static function issuePlainToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
    }

    /**
     * @param array<string,mixed> $fields
     */
    public static function log(string $event, array $fields): void
    {
        unset($fields['body'], $fields['token'], $fields['password'], $fields['authorization']);
        $fields['event'] = $event;
        $fields['at'] = self::nowUtc();
        error_log(json_encode($fields, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: $event);
    }

    public static function jsonFlags(): int
    {
        return JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
    }
}
