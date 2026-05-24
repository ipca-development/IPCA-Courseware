<?php
declare(strict_types=1);

require_once __DIR__ . '/progress_test_access.php';

const PTR_AUTH_TTL_MINUTES = 60;
const PTR_MAX_REQUESTS_PER_HOUR = 5;
const PTR_MAX_CODE_FAILURES = 5;
const PTR_ACTIVE_ATTEMPT_MINUTES = 15;

function ptr_ensure_tables(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $migration = dirname(__DIR__) . '/scripts/sql/2026_05_26_progress_test_remote_authorization.sql';
    if (!is_readable($migration)) {
        return;
    }
    $sql = (string)file_get_contents($migration);
    foreach (array_filter(array_map('trim', preg_split('/;\s*\n/', $sql) ?: [])) as $stmt) {
        if ($stmt === '' || stripos($stmt, 'SET @') === 0) {
            continue;
        }
        try {
            $pdo->exec($stmt);
        } catch (Throwable $e) {
            // idempotent
        }
    }
}

function ptr_hash(string $value): string
{
    return hash('sha256', $value);
}

function ptr_generate_token(): string
{
    return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
}

function ptr_generate_code(): string
{
    return str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

function ptr_user_agent_hash(): string
{
    $ua = trim((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
    return $ua === '' ? '' : ptr_hash($ua);
}

function ptr_photo_storage_dir(): string
{
    $dir = dirname(__DIR__) . '/storage/remote_progress_test_photos';
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    return $dir;
}

function ptr_store_auth_photo(int $authorizationId, string $binary, string $mime): array
{
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($allowed[$mime])) {
        throw new RuntimeException('Photo must be JPEG, PNG, or WebP.');
    }
    if (strlen($binary) > 6 * 1024 * 1024) {
        throw new RuntimeException('Photo is too large (max 6 MB).');
    }
    $ext = $allowed[$mime];
    $rel = 'auth_' . $authorizationId . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    $abs = ptr_photo_storage_dir() . '/' . $rel;
    if (@file_put_contents($abs, $binary) === false) {
        throw new RuntimeException('Unable to store authentication photo.');
    }
    @chmod($abs, 0640);
    return [
        'path' => $rel,
        'hash' => ptr_hash($binary),
        'absolute' => $abs,
    ];
}

function ptr_photo_absolute_path(?string $storedPath): ?string
{
    $storedPath = trim((string)$storedPath);
    if ($storedPath === '' || str_contains($storedPath, '..') || str_contains($storedPath, '\\')) {
        return null;
    }
    $abs = ptr_photo_storage_dir() . '/' . basename($storedPath);
    return is_file($abs) ? $abs : null;
}

function cw_progress_test_is_trusted_school_network(PDO $pdo, int $userId, int $cohortId): bool
{
    $state = cw_progress_test_access_state($pdo, $userId, $cohortId);
    return !empty($state['ip_allowed']);
}

function ptr_status_label(string $status): string
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

function ptr_status_pill_class(string $status): string
{
    return match ($status) {
        'AUTHENTICATED', 'CODE_VERIFIED' => 'ok',
        'EMAIL_SENT', 'REQUESTED' => 'new',
        'USED' => 'ok',
        'FAILED', 'REVOKED' => 'bad',
        'EXPIRED' => 'warn',
        default => 'new',
    };
}

function ptr_app_base_url(): string
{
    $base = getenv('CW_APP_BASE_URL') ?: '';
    if ($base !== '') {
        return rtrim($base, '/');
    }
    $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
    if ($host !== '') {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        return $scheme . '://' . $host;
    }
    return 'https://ipca.training';
}

function ptr_support_email(): string
{
    $v = getenv('CW_SUPPORT_EMAIL') ?: getenv('SUPPORT_EMAIL') ?: 'support@ipca.training';
    return trim((string)$v);
}
