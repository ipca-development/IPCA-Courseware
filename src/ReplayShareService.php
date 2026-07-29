<?php
declare(strict_types=1);

require_once __DIR__ . '/AuditEventService.php';

final class ReplayShareService
{
    public const NOTICE_VERSION = 'private-flight-recording-v1';
    public const NOTICE_TITLE = 'Private Flight Recording Notice';
    public const NOTICE_TEXT = <<<'NOTICE'
This flight recording contains confidential operational information and is provided exclusively to the intended recipient for personal training and debriefing purposes.

This recording, including any video, audio, transcript, flight data, screenshots, or excerpts, is strictly private and may not be copied, downloaded, distributed, published, shared, or shown to any third party without the prior written consent of the pilots involved in the flight and, where applicable, the operating organization.

By accessing this recording, you acknowledge that it may contain personal information, voice communications, and operational flight data. You agree to treat all content as confidential and to use it solely for the purpose for which it was provided.

Unauthorized use or disclosure may violate privacy rights and applicable laws and may result in access to this service being revoked.

This link is temporary and will expire automatically after 12 hours.
NOTICE;

    public function __construct(private PDO $pdo)
    {
    }

    public function isReady(): bool
    {
        try {
            $this->pdo->query('SELECT id FROM ipca_replay_debrief_shares LIMIT 0');
            $this->pdo->query('SELECT id FROM ipca_replay_debrief_share_access LIMIT 0');
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array<string,mixed> */
    public function create(int $debriefId, int $actorUserId): array
    {
        if (!$this->isReady()) {
            throw new RuntimeException('Replay sharing database migration is not installed.');
        }
        $source = $this->sourceForDebrief($debriefId);
        $plainToken = $this->randomToken();
        $passcode = $this->randomPasscode();
        $expiresAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+12 hours');
        $this->pdo->beginTransaction();
        try {
            $lock = $this->pdo->prepare('SELECT id FROM ipca_structured_debriefs WHERE id = ? FOR UPDATE');
            $lock->execute(array($debriefId));
            if (!$lock->fetchColumn()) {
                throw new RuntimeException('Debrief is unavailable.');
            }
            $this->pdo->prepare(
                "UPDATE ipca_replay_debrief_shares
                 SET status = 'revoked', revoked_at = CURRENT_TIMESTAMP(3), revoked_by = ?
                 WHERE debrief_id = ? AND status = 'active'"
            )->execute(array($actorUserId, $debriefId));
            $insert = $this->pdo->prepare(
                "INSERT INTO ipca_replay_debrief_shares
                 (share_uuid, debrief_id, bundle_id, recording_id, token_hash, passcode_hash,
                  status, expires_at, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, 'active', ?, ?)"
            );
            $insert->execute(array(
                AuditEventService::uuid(),
                $debriefId,
                (int)$source['bundle_id'],
                (int)$source['recording_id'],
                self::hashToken($plainToken),
                password_hash($passcode, PASSWORD_DEFAULT),
                $expiresAt->format('Y-m-d H:i:s.u'),
                $actorUserId,
            ));
            $shareId = (int)$this->pdo->lastInsertId();
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        $share = $this->shareById($shareId);
        $share['token'] = $plainToken;
        $share['passcode'] = $passcode;
        return $share;
    }

    public function revoke(int $debriefId, int $actorUserId): void
    {
        if (!$this->isReady()) {
            throw new RuntimeException('Replay sharing database migration is not installed.');
        }
        $this->pdo->beginTransaction();
        try {
            $lock = $this->pdo->prepare('SELECT id FROM ipca_structured_debriefs WHERE id = ? FOR UPDATE');
            $lock->execute(array($debriefId));
            $this->pdo->prepare(
                "UPDATE ipca_replay_debrief_shares
                 SET status = 'revoked', revoked_at = CURRENT_TIMESTAMP(3), revoked_by = ?
                 WHERE debrief_id = ? AND status = 'active'"
            )->execute(array($actorUserId, $debriefId));
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /** @return array<string,mixed> */
    public function currentForDebrief(int $debriefId): array
    {
        if (!$this->isReady()) {
            return array();
        }
        $statement = $this->pdo->prepare(
            'SELECT id, share_uuid, debrief_id, bundle_id, recording_id, status, expires_at,
                    revoked_at, failed_attempt_count, locked_until, last_viewed_at, view_count, created_at
             FROM ipca_replay_debrief_shares WHERE debrief_id = ? ORDER BY id DESC LIMIT 1'
        );
        $statement->execute(array($debriefId));
        return $statement->fetch(PDO::FETCH_ASSOC) ?: array();
    }

    /** @return array<string,mixed> */
    public function shareForToken(string $plainToken): array
    {
        if (!$this->isReady() || trim($plainToken) === '') {
            throw new RuntimeException('This replay link is invalid or unavailable.');
        }
        $statement = $this->pdo->prepare(
            'SELECT * FROM ipca_replay_debrief_shares WHERE token_hash = ? LIMIT 1'
        );
        $statement->execute(array(self::hashToken($plainToken)));
        $share = $statement->fetch(PDO::FETCH_ASSOC);
        $this->assertActive(is_array($share) ? $share : array());
        return $share;
    }

    /** @return array<string,mixed> */
    public function unlock(string $plainToken, string $passcode): array
    {
        $share = $this->shareForToken($plainToken);
        if (!empty($share['locked_until']) && strtotime((string)$share['locked_until']) > time()) {
            throw new RuntimeException('This replay link is temporarily unavailable. Please try again later.');
        }
        if (!password_verify(trim($passcode), (string)$share['passcode_hash'])) {
            $this->pdo->prepare(
                'UPDATE ipca_replay_debrief_shares
                 SET failed_attempt_count = failed_attempt_count + 1,
                     locked_until = IF(failed_attempt_count + 1 >= 5,
                         DATE_ADD(CURRENT_TIMESTAMP(3), INTERVAL 15 MINUTE), locked_until)
                 WHERE id = ?'
            )->execute(array((int)$share['id']));
            throw new RuntimeException('This replay link is invalid or unavailable.');
        }
        session_regenerate_id(true);
        $accessUuid = AuditEventService::uuid();
        $insert = $this->pdo->prepare(
            "INSERT INTO ipca_replay_debrief_share_access
             (access_uuid, share_id, status, ip_hash, user_agent_hash)
             VALUES (?, ?, 'unlocked', ?, ?)"
        );
        $insert->execute(array(
            $accessUuid,
            (int)$share['id'],
            $this->requestHash((string)($_SERVER['REMOTE_ADDR'] ?? '')),
            $this->requestHash((string)($_SERVER['HTTP_USER_AGENT'] ?? '')),
        ));
        $accessId = (int)$this->pdo->lastInsertId();
        $_SESSION['replay_share_grant'] = array(
            'share_id' => (int)$share['id'],
            'access_id' => $accessId,
            'access_uuid' => $accessUuid,
            'expires_at' => strtotime((string)$share['expires_at']),
            'privacy_accepted' => false,
        );
        $this->pdo->prepare(
            'UPDATE ipca_replay_debrief_shares
             SET failed_attempt_count = 0, locked_until = NULL WHERE id = ?'
        )->execute(array((int)$share['id']));
        return $share;
    }

    public function acceptPrivacy(string $plainToken): void
    {
        $share = $this->shareForToken($plainToken);
        $grant = $this->sessionGrant();
        if ((int)($grant['share_id'] ?? 0) !== (int)$share['id']) {
            throw new RuntimeException('This replay link is invalid or unavailable.');
        }
        $this->pdo->prepare(
            "UPDATE ipca_replay_debrief_share_access
             SET status = 'accepted', privacy_notice_version = ?,
                 privacy_accepted_at = CURRENT_TIMESTAMP(3), last_accessed_at = CURRENT_TIMESTAMP(3)
             WHERE id = ? AND share_id = ?"
        )->execute(array(self::NOTICE_VERSION, (int)$grant['access_id'], (int)$share['id']));
        $_SESSION['replay_share_grant']['privacy_accepted'] = true;
    }

    /** @return array<string,mixed> */
    public function mediaGrant(string $recordingIdentifier): array
    {
        $grant = $this->sessionGrant();
        if (empty($grant['privacy_accepted'])) {
            throw new RuntimeException('Privacy acceptance is required.');
        }
        $statement = $this->pdo->prepare(
            'SELECT s.*, a.privacy_accepted_at, a.status AS access_status
             FROM ipca_replay_debrief_shares s
             INNER JOIN ipca_replay_debrief_share_access a ON a.id = ? AND a.share_id = s.id
             WHERE s.id = ? LIMIT 1'
        );
        $statement->execute(array((int)$grant['access_id'], (int)$grant['share_id']));
        $share = $statement->fetch(PDO::FETCH_ASSOC);
        $this->assertActive(is_array($share) ? $share : array());
        if ((string)($share['access_status'] ?? '') !== 'accepted'
            || empty($share['privacy_accepted_at'])
            || !$this->recordingBelongsToShare($recordingIdentifier, $share)) {
            throw new RuntimeException('This replay link is invalid or unavailable.');
        }
        $this->pdo->prepare(
            'UPDATE ipca_replay_debrief_share_access SET last_accessed_at = CURRENT_TIMESTAMP(3) WHERE id = ?'
        )->execute(array((int)$grant['access_id']));
        $this->pdo->prepare(
            'UPDATE ipca_replay_debrief_shares
             SET last_viewed_at = CURRENT_TIMESTAMP(3), view_count = view_count + 1 WHERE id = ?'
        )->execute(array((int)$share['id']));
        return $share;
    }

    public static function hashToken(string $plainToken): string
    {
        return hash('sha256', trim($plainToken));
    }

    /** @return array<string,mixed> */
    private function sourceForDebrief(int $debriefId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT d.id, d.bundle_id, b.cockpit_recording_id AS recording_id
             FROM ipca_structured_debriefs d
             INNER JOIN ipca_manual_intake_bundles b ON b.id = d.bundle_id
             WHERE d.id = ? LIMIT 1'
        );
        $statement->execute(array($debriefId));
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || (int)$row['recording_id'] <= 0) {
            throw new RuntimeException('Debrief replay source is unavailable.');
        }
        return $row;
    }

    /** @return array<string,mixed> */
    private function shareById(int $shareId): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM ipca_replay_debrief_shares WHERE id = ? LIMIT 1');
        $statement->execute(array($shareId));
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('Replay share was not created.');
        }
        return $row;
    }

    /** @param array<string,mixed> $share */
    private function assertActive(array $share): void
    {
        if ($share === array()
            || (string)($share['status'] ?? '') !== 'active'
            || !empty($share['revoked_at'])
            || strtotime((string)($share['expires_at'] ?? '')) <= time()) {
            throw new RuntimeException('This replay link is invalid or unavailable.');
        }
    }

    /** @return array<string,mixed> */
    private function sessionGrant(): array
    {
        $grant = $_SESSION['replay_share_grant'] ?? null;
        if (!is_array($grant) || (int)($grant['expires_at'] ?? 0) <= time()) {
            unset($_SESSION['replay_share_grant']);
            throw new RuntimeException('This replay link is invalid or unavailable.');
        }
        return $grant;
    }

    /** @param array<string,mixed> $share */
    private function recordingBelongsToShare(string $identifier, array $share): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT id, flight_session_uid FROM ipca_cockpit_recordings
             WHERE id = ? OR recording_uid = ? LIMIT 1'
        );
        $statement->execute(array((int)$identifier, $identifier));
        $requested = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($requested)) {
            return false;
        }
        if ((int)$requested['id'] === (int)$share['recording_id']) {
            return true;
        }
        $primary = $this->pdo->prepare(
            'SELECT flight_session_uid FROM ipca_cockpit_recordings WHERE id = ? LIMIT 1'
        );
        $primary->execute(array((int)$share['recording_id']));
        $sessionUid = trim((string)$primary->fetchColumn());
        return $sessionUid !== '' && hash_equals($sessionUid, trim((string)$requested['flight_session_uid']));
    }

    private function randomToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private function randomPasscode(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $passcode = '';
        for ($i = 0; $i < 8; $i++) {
            $passcode .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return $passcode;
    }

    private function requestHash(string $value): string
    {
        $key = trim((string)(getenv('CW_APP_KEY') ?: getenv('APP_KEY') ?: 'ipca-replay-share-audit-v1'));
        return hash_hmac('sha256', $value, $key);
    }
}
