<?php
declare(strict_types=1);

const RSA_AUTH_TTL_MINUTES = 60;
const RSA_MAX_REQUESTS_PER_HOUR = 5;
const RSA_MAX_CODE_FAILURES = 5;
const RSA_ACTIVE_SESSION_MINUTES = 15;
const RSA_SESSION_MAX_DURATION_SEC = 300;
const RSA_HEARTBEAT_STALE_SEC = 90;

function rsa_hash(string $value): string
{
    return hash('sha256', $value);
}

function rsa_generate_token(): string
{
    return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
}

function rsa_generate_code(): string
{
    return str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

function rsa_user_agent_hash(): string
{
    $ua = trim((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
    return $ua === '' ? '' : rsa_hash($ua);
}

function rsa_client_ip(): string
{
    if (function_exists('cw_progress_test_client_ip')) {
        return cw_progress_test_client_ip();
    }
    return trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
}

function rsa_store_auth_photo(string $storageDir, int $authorizationId, string $binary, string $mime, string $prefix = 'auth'): array
{
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($allowed[$mime])) {
        throw new RuntimeException('Photo must be JPEG, PNG, or WebP.');
    }
    if (strlen($binary) > 6 * 1024 * 1024) {
        throw new RuntimeException('Photo is too large (max 6 MB).');
    }
    if (!is_dir($storageDir)) {
        @mkdir($storageDir, 0750, true);
    }
    $ext = $allowed[$mime];
    $rel = $prefix . '_' . $authorizationId . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    $abs = rtrim($storageDir, '/') . '/' . $rel;
    if (@file_put_contents($abs, $binary) === false) {
        throw new RuntimeException('Unable to store authentication photo.');
    }
    @chmod($abs, 0640);
    return [
        'path' => $rel,
        'hash' => rsa_hash($binary),
        'absolute' => $abs,
    ];
}

function rsa_photo_absolute_path(string $storageDir, ?string $storedPath): ?string
{
    $storedPath = trim((string)$storedPath);
    if ($storedPath === '' || str_contains($storedPath, '..') || str_contains($storedPath, '\\')) {
        return null;
    }
    $abs = rtrim($storageDir, '/') . '/' . basename($storedPath);
    return is_file($abs) ? $abs : null;
}

function rsa_status_label(string $status): string
{
    return match ($status) {
        'REQUESTED' => 'Requested',
        'EMAIL_SENT' => 'Email sent',
        'AUTHENTICATED' => 'Authenticated',
        'CODE_VERIFIED' => 'Code verified',
        'USED' => 'Used',
        'EXPIRED' => 'Expired',
        'REVOKED' => 'Revoked',
        'FAILED' => 'Failed',
        default => $status,
    };
}
