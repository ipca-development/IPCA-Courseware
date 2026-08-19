<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/communication/CommunicationSupport.php';

final class RemoteSessionAppCodeService
{
    public function __construct(private PDO $pdo)
    {
        $this->ensureTable();
    }

    /**
     * @param array<string,mixed> $context
     * @return array{code_uuid:string,kind:string,expires_at_utc:string}|null
     */
    public function persist(string $code, array $context): ?array
    {
        $code = preg_replace('/\D+/', '', $code) ?? '';
        if (strlen($code) !== 6) {
            return null;
        }
        $userId = (int)($context['student_id'] ?? $context['user_id'] ?? 0);
        if ($userId < 1) {
            return null;
        }
        $kind = strtolower(trim((string)($context['kind'] ?? 'progress_test')));
        if (!in_array($kind, array('progress_test', 'mock_oral'), true)) {
            $kind = 'progress_test';
        }
        $authorizationId = (int)($context['authorization_id'] ?? 0);
        $uuid = CommunicationSupport::uuid();
        $now = CommunicationSupport::nowUtc();
        $expiresAt = $this->resolveExpiry($context['expires_at'] ?? null, $now);

        if ($authorizationId > 0) {
            $this->pdo->prepare(
                'UPDATE ipca_remote_session_codes
                 SET code_plaintext = NULL, viewed_at_utc = COALESCE(viewed_at_utc, ?)
                 WHERE user_id = ? AND kind = ? AND authorization_id = ? AND code_plaintext IS NOT NULL'
            )->execute(array($now, $userId, $kind, $authorizationId));
        }

        $this->pdo->prepare(
            'INSERT INTO ipca_remote_session_codes
             (code_uuid, user_id, kind, authorization_id, code_plaintext, expires_at_utc, created_at_utc)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute(array(
            $uuid,
            $userId,
            $kind,
            $authorizationId > 0 ? $authorizationId : null,
            $code,
            $expiresAt,
            $now,
        ));

        return array(
            'code_uuid' => $uuid,
            'kind' => $kind,
            'expires_at_utc' => $expiresAt,
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function publicEnvelope(int $userId, string $codeUuid): array
    {
        $row = $this->rowForUser($userId, $codeUuid);
        if ($row === null) {
            throw new CommunicationException('not_found', 'That code is no longer available.', 404);
        }
        $expired = $this->isExpired($row);
        $viewed = trim((string)($row['viewed_at_utc'] ?? '')) !== '';
        $digits = (string)($row['code_plaintext'] ?? '');
        $reveal = !$expired && !$viewed && preg_match('/^\d{6}$/', $digits) === 1;
        $kind = (string)$row['kind'];
        $title = $kind === 'mock_oral' ? 'Mock Oral Code' : 'Progress Test Code';
        return array(
            'ok' => true,
            'kind' => $kind,
            'title' => $title,
            'subtitle' => $reveal
                ? 'Write this code down, then enter it on the website.'
                : '',
            'code' => $reveal ? $digits : '',
            'expires_at' => $this->toIsoUtc((string)$row['expires_at_utc']),
            'viewed' => $viewed || $expired || !$reveal,
        );
    }

    public function markViewed(int $userId, string $codeUuid): void
    {
        $row = $this->rowForUser($userId, $codeUuid);
        if ($row === null) {
            throw new CommunicationException('not_found', 'That code is no longer available.', 404);
        }
        $this->wipe((int)$row['id']);
    }

    public function consumeForAuthorization(int $userId, string $kind, int $authorizationId): void
    {
        if ($userId < 1 || $authorizationId < 1) {
            return;
        }
        $kind = strtolower(trim($kind));
        $now = CommunicationSupport::nowUtc();
        $this->pdo->prepare(
            'UPDATE ipca_remote_session_codes
             SET code_plaintext = NULL, viewed_at_utc = COALESCE(viewed_at_utc, ?)
             WHERE user_id = ? AND kind = ? AND authorization_id = ?'
        )->execute(array($now, $userId, $kind, $authorizationId));
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function pendingTrainingActions(int $userId): array
    {
        if ($userId < 1) {
            return array();
        }
        $stmt = $this->pdo->prepare(
            'SELECT code_uuid, kind, expires_at_utc
             FROM ipca_remote_session_codes
             WHERE user_id = ?
               AND viewed_at_utc IS NULL
               AND code_plaintext IS NOT NULL
               AND expires_at_utc > ?
             ORDER BY id DESC
             LIMIT 10'
        );
        $stmt->execute(array($userId, CommunicationSupport::nowUtc()));
        $items = array();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $kind = (string)$row['kind'];
            $uuid = (string)$row['code_uuid'];
            $items[] = array(
                'id' => $uuid,
                'source' => 'remote_session_code',
                'title' => $kind === 'mock_oral' ? 'Mock Oral Code' : 'Progress Test Code',
                'subtitle' => 'Open the IPCA app to view your one-time code.',
                'status' => 'ready',
                'due_at' => $this->toIsoUtc((string)$row['expires_at_utc']),
                'code_id' => $uuid,
            );
        }
        return $items;
    }

    private function ensureTable(): void
    {
        $driver = (string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $this->pdo->exec(
                'CREATE TABLE IF NOT EXISTS ipca_remote_session_codes (
                  id INTEGER PRIMARY KEY AUTOINCREMENT,
                  code_uuid TEXT NOT NULL UNIQUE,
                  user_id INTEGER NOT NULL,
                  kind TEXT NOT NULL,
                  authorization_id INTEGER NULL,
                  code_plaintext TEXT NULL,
                  expires_at_utc TEXT NOT NULL,
                  viewed_at_utc TEXT NULL,
                  created_at_utc TEXT NOT NULL
                )'
            );
            return;
        }
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS ipca_remote_session_codes (
              id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
              code_uuid CHAR(36) NOT NULL,
              user_id BIGINT UNSIGNED NOT NULL,
              kind VARCHAR(32) NOT NULL,
              authorization_id BIGINT UNSIGNED NULL,
              code_plaintext CHAR(6) NULL,
              expires_at_utc DATETIME(3) NOT NULL,
              viewed_at_utc DATETIME(3) NULL,
              created_at_utc DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
              UNIQUE KEY uk_remote_session_code_uuid (code_uuid),
              KEY idx_remote_session_code_user (user_id, viewed_at_utc, expires_at_utc)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    /** @return array<string,mixed>|null */
    private function rowForUser(int $userId, string $codeUuid): ?array
    {
        $codeUuid = strtolower(trim($codeUuid));
        if ($userId < 1 || !CommunicationSupport::isUuid($codeUuid)) {
            return null;
        }
        $stmt = $this->pdo->prepare(
            'SELECT * FROM ipca_remote_session_codes WHERE code_uuid = ? AND user_id = ? LIMIT 1'
        );
        $stmt->execute(array($codeUuid, $userId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function wipe(int $id): void
    {
        $this->pdo->prepare(
            'UPDATE ipca_remote_session_codes
             SET code_plaintext = NULL, viewed_at_utc = COALESCE(viewed_at_utc, ?)
             WHERE id = ?'
        )->execute(array(CommunicationSupport::nowUtc(), $id));
    }

    /** @param array<string,mixed> $row */
    private function isExpired(array $row): bool
    {
        $expires = trim((string)($row['expires_at_utc'] ?? ''));
        if ($expires === '') {
            return true;
        }
        try {
            $date = new DateTimeImmutable($expires, new DateTimeZone('UTC'));
        } catch (Throwable) {
            return true;
        }
        return $date->getTimestamp() <= time();
    }

    private function resolveExpiry(mixed $provided, string $now): string
    {
        $fallback = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->modify('+30 minutes')
            ->format('Y-m-d H:i:s.000');
        $value = trim((string)$provided);
        if ($value === '') {
            return $fallback;
        }
        try {
            $date = new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (Throwable) {
            return $fallback;
        }
        $formatted = $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.000');
        return $formatted < $now ? $fallback : $formatted;
    }

    private function toIsoUtc(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        try {
            $date = new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (Throwable) {
            return '';
        }
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
