<?php
declare(strict_types=1);

require_once __DIR__ . '/remote_session_auth_constants.php';

/**
 * Shared remote session authentication helpers (interface-first layer).
 * Product-specific repositories (progress test, mock oral) call these primitives.
 */
final class RemoteSessionAuthService
{
    public static function verifyPassword(PDO $pdo, int $userId, string $password): bool
    {
        $st = $pdo->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
        $st->execute([$userId]);
        $hash = (string)$st->fetchColumn();
        if ($hash === '') {
            return false;
        }
        return password_verify($password, $hash);
    }

    public static function authenticateWithPhoto(
        PDO $pdo,
        array $authRow,
        int $studentId,
        string $password,
        string $photoBinary,
        string $photoMime,
        string $photoStorageDir,
        string $photoPrefix,
        callable $updateAuthAuthenticated
    ): array {
        if ((int)($authRow['student_id'] ?? 0) !== $studentId) {
            throw new RuntimeException('This authentication link belongs to another account.');
        }
        if (!in_array((string)($authRow['status'] ?? ''), ['REQUESTED', 'EMAIL_SENT', 'AUTHENTICATED'], true)) {
            throw new RuntimeException('This authorization is no longer valid.');
        }
        if (strtotime((string)($authRow['expires_at'] ?? '')) <= time()) {
            throw new RuntimeException('This authentication link has expired.');
        }
        if (!self::verifyPassword($pdo, $studentId, $password)) {
            throw new RuntimeException('Incorrect password.');
        }

        $authId = (int)$authRow['id'];
        $code = rsa_generate_code();
        $codeHash = rsa_hash($code);
        $photo = rsa_store_auth_photo($photoStorageDir, $authId, $photoBinary, $photoMime, $photoPrefix);

        $updateAuthAuthenticated($authId, $codeHash, $photo['path'], $photo['hash']);

        return [
            'ok' => true,
            'verification_code' => $code,
            'authorization_id' => $authId,
        ];
    }

    public static function verifyCode(
        array $authRow,
        int $studentId,
        string $submittedCode,
        callable $markFailedAttempt,
        callable $markUsed
    ): array {
        if ((int)($authRow['student_id'] ?? 0) !== $studentId) {
            throw new RuntimeException('Invalid authorization.');
        }
        if ((string)($authRow['status'] ?? '') !== 'AUTHENTICATED') {
            throw new RuntimeException('Complete photo authentication first.');
        }
        if (strtotime((string)($authRow['expires_at'] ?? '')) <= time()) {
            throw new RuntimeException('Authorization expired.');
        }
        if ((int)($authRow['failed_code_attempts'] ?? 0) >= RSA_MAX_CODE_FAILURES) {
            throw new RuntimeException('Too many failed code attempts.');
        }

        $submittedHash = rsa_hash(trim($submittedCode));
        if (!hash_equals((string)($authRow['verification_code_hash'] ?? ''), $submittedHash)) {
            $failures = $markFailedAttempt((int)$authRow['id']);
            $remaining = max(0, RSA_MAX_CODE_FAILURES - $failures);
            throw new RuntimeException('Incorrect code. ' . $remaining . ' attempt(s) remaining.');
        }

        return $markUsed((int)$authRow['id']);
    }
}
