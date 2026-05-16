<?php
declare(strict_types=1);

final class ComplianceApprovalTokenService
{
    public static function tablePresent(PDO $pdo): bool
    {
        try {
            $pdo->query('SELECT id FROM ipca_compliance_public_approval_tokens LIMIT 0');
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /** @param array<string,mixed> $data @return array{token:string,id:int} */
    public static function createToken(PDO $pdo, array $data, int $userId): array
    {
        if (!self::tablePresent($pdo)) {
            throw new RuntimeException('Public approval token table is not installed.');
        }
        $plain = bin2hex(random_bytes(32));
        $hash = self::hashToken($plain);
        $expiresAt = trim((string)($data['expires_at'] ?? ''));
        if ($expiresAt === '') {
            $expiresAt = date('Y-m-d H:i:s', strtotime('+21 days'));
        }
        $recipientEmail = trim((string)($data['recipient_email'] ?? ''));
        if (filter_var($recipientEmail, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('A valid recipient email is required.');
        }

        $st = $pdo->prepare(
            'INSERT INTO ipca_compliance_public_approval_tokens
                (token_hash, token_type, batch_id, audit_id, finding_id, corrective_action_id,
                 recipient_email, recipient_name, expires_at, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $st->execute(array(
            $hash,
            (string)($data['token_type'] ?? 'deadline_extension'),
            isset($data['batch_id']) && (int)$data['batch_id'] > 0 ? (int)$data['batch_id'] : null,
            isset($data['audit_id']) && (int)$data['audit_id'] > 0 ? (int)$data['audit_id'] : null,
            isset($data['finding_id']) && (int)$data['finding_id'] > 0 ? (int)$data['finding_id'] : null,
            isset($data['corrective_action_id']) && (int)$data['corrective_action_id'] > 0 ? (int)$data['corrective_action_id'] : null,
            $recipientEmail,
            trim((string)($data['recipient_name'] ?? '')) !== '' ? substr(trim((string)$data['recipient_name']), 0, 255) : null,
            $expiresAt,
            $userId > 0 ? $userId : null,
        ));

        return array('token' => $plain, 'id' => (int)$pdo->lastInsertId());
    }

    /** @return array<string,mixed> */
    public static function validateToken(PDO $pdo, string $token, string $type = 'deadline_extension'): array
    {
        if (!self::tablePresent($pdo)) {
            throw new RuntimeException('Public approval token table is not installed.');
        }
        $hash = self::hashToken(trim($token));
        if ($hash === self::hashToken('')) {
            throw new RuntimeException('Missing review token.');
        }
        $st = $pdo->prepare('SELECT * FROM ipca_compliance_public_approval_tokens WHERE token_hash = ? AND token_type = ? LIMIT 1');
        $st->execute(array($hash, $type));
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('Invalid review token.');
        }
        if (!empty($row['revoked_at'])) {
            throw new RuntimeException('This review token has been revoked.');
        }
        if (strtotime((string)$row['expires_at']) < time()) {
            throw new RuntimeException('This review token has expired.');
        }
        return $row;
    }

    public static function markViewed(PDO $pdo, int $tokenId): void
    {
        if (!self::tablePresent($pdo) || $tokenId <= 0) {
            return;
        }
        $pdo->prepare(
            'UPDATE ipca_compliance_public_approval_tokens
                SET last_viewed_at = NOW(), view_count = view_count + 1
              WHERE id = ?'
        )->execute(array($tokenId));
    }

    public static function markUsed(PDO $pdo, int $tokenId): void
    {
        if (!self::tablePresent($pdo) || $tokenId <= 0) {
            return;
        }
        $pdo->prepare('UPDATE ipca_compliance_public_approval_tokens SET used_at = COALESCE(used_at, NOW()) WHERE id = ?')
            ->execute(array($tokenId));
    }
}
