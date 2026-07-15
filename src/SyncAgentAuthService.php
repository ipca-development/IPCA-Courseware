<?php
declare(strict_types=1);

final class SyncAgentAuthService
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return array<string,mixed>
     */
    public function requireToken(string $requiredScope): array
    {
        $header = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
        if ($header === '' && function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            $header = (string)($headers['Authorization'] ?? $headers['authorization'] ?? '');
        }
        if (!preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
            throw new RuntimeException('Missing sync-agent bearer token.');
        }
        $token = trim($matches[1]);
        if ($token === '') {
            throw new RuntimeException('Missing sync-agent bearer token.');
        }
        $hash = hash('sha256', $token);
        $stmt = $this->pdo->prepare('SELECT * FROM ipca_sync_agent_tokens WHERE token_hash = ? AND is_active = 1 AND revoked_at IS NULL LIMIT 1');
        $stmt->execute(array($hash));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('Invalid or revoked sync-agent token.');
        }
        $scopes = json_decode((string)$row['scope_json'], true);
        $scopes = is_array($scopes) ? $scopes : array();
        if (!in_array($requiredScope, $scopes, true)) {
            throw new RuntimeException('Sync-agent token is not scoped for this operation.');
        }
        $this->pdo->prepare('UPDATE ipca_sync_agent_tokens SET last_seen_at = CURRENT_TIMESTAMP(3), updated_at = CURRENT_TIMESTAMP(3) WHERE id = ?')
            ->execute(array((int)$row['id']));
        return $row;
    }
}
