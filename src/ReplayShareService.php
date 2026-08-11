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

This link is temporary and will expire automatically at the time shown on the access page.
NOTICE;
    private const ALLOWED_EXPIRY_HOURS = array(12, 24, 48);

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

    public function isEmailDeliveryReady(): bool
    {
        return $this->deliveryTableReady();
    }

    /** @return array<string,mixed> */
    public function create(
        int $debriefId,
        int $actorUserId,
        int $expiryHours = 12,
        string $recipientEmail = '',
        string $recipientName = '',
        string $recipientType = 'custom'
    ): array
    {
        if (!$this->isReady()) {
            throw new RuntimeException('Replay sharing database migration is not installed.');
        }
        $source = $this->sourceForDebrief($debriefId);
        if (!in_array($expiryHours, self::ALLOWED_EXPIRY_HOURS, true)) {
            throw new InvalidArgumentException('Replay access must expire after 12, 24, or 48 hours.');
        }
        $recipientEmail = strtolower(trim($recipientEmail));
        $recipientName = mb_substr(trim($recipientName), 0, 160);
        $recipientType = in_array($recipientType, array('student', 'custom'), true)
            ? $recipientType
            : 'custom';
        if ($recipientEmail !== '' && filter_var($recipientEmail, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Enter a valid replay recipient email address.');
        }
        $plainToken = $this->randomToken();
        $passcode = $this->randomPasscode();
        $expiresAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->modify('+' . $expiryHours . ' hours');
        $this->pdo->beginTransaction();
        try {
            $lock = $this->pdo->prepare('SELECT id FROM ipca_structured_debriefs WHERE id = ? FOR UPDATE');
            $lock->execute(array($debriefId));
            if (!$lock->fetchColumn()) {
                throw new RuntimeException('Debrief is unavailable.');
            }
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
            if ($recipientEmail !== '') {
                if (!$this->deliveryTableReady()) {
                    throw new RuntimeException('Replay email delivery migration is not installed.');
                }
                $this->pdo->prepare(
                    "INSERT INTO ipca_replay_debrief_share_deliveries
                     (delivery_uuid, share_id, recipient_type, recipient_name, recipient_email,
                      delivery_status, created_by)
                     VALUES (?, ?, ?, ?, ?, 'pending', ?)"
                )->execute(array(
                    AuditEventService::uuid(),
                    $shareId,
                    $recipientType,
                    $recipientName,
                    $recipientEmail,
                    $actorUserId,
                ));
            }
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        $share = $this->shareById($shareId);
        $share['token'] = $plainToken;
        $share['passcode'] = $passcode;
        $share['expiry_hours'] = $expiryHours;
        $share['recipient_email'] = $recipientEmail;
        $share['recipient_name'] = $recipientName;
        $share['recipient_type'] = $recipientType;
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

    public function revokeShare(int $shareId, int $actorUserId, int $debriefId = 0): void
    {
        if ($shareId <= 0) {
            throw new InvalidArgumentException('Replay share is required.');
        }
        $statement = $this->pdo->prepare(
            "UPDATE ipca_replay_debrief_shares
             SET status = 'revoked', revoked_at = CURRENT_TIMESTAMP(3), revoked_by = ?
             WHERE id = ? AND status = 'active'"
                . ($debriefId > 0 ? ' AND debrief_id = ?' : '')
        );
        $parameters = array($actorUserId, $shareId);
        if ($debriefId > 0) {
            $parameters[] = $debriefId;
        }
        $statement->execute($parameters);
    }

    /** @return list<array<string,mixed>> */
    public function listForDebrief(int $debriefId): array
    {
        $map = $this->listForDebriefs(array($debriefId));
        return $map[$debriefId] ?? array();
    }

    /**
     * @param list<int> $debriefIds
     * @return array<int,list<array<string,mixed>>>
     */
    public function listForDebriefs(array $debriefIds): array
    {
        $debriefIds = array_values(array_unique(array_filter(
            array_map('intval', $debriefIds),
            static fn(int $id): bool => $id > 0
        )));
        if (!$this->isReady() || $debriefIds === array()) {
            return array();
        }
        $deliveryReady = $this->deliveryTableReady();
        $deliveryJoin = $deliveryReady
            ? 'LEFT JOIN ipca_replay_debrief_share_deliveries delivery ON delivery.share_id = share_record.id'
            : '';
        $deliveryFields = $deliveryReady
            ? ', delivery.recipient_type, delivery.recipient_name, delivery.recipient_email,
                 delivery.delivery_status, delivery.sent_at, delivery.delivery_error'
            : ", 'custom' AS recipient_type, '' AS recipient_name, '' AS recipient_email,
                 '' AS delivery_status, NULL AS sent_at, NULL AS delivery_error";
        $placeholders = implode(',', array_fill(0, count($debriefIds), '?'));
        $statement = $this->pdo->prepare(
            'SELECT share_record.id, share_record.share_uuid, share_record.debrief_id,
                    share_record.status, share_record.expires_at, share_record.revoked_at,
                    share_record.last_viewed_at, share_record.view_count, share_record.created_at'
                    . $deliveryFields . '
             FROM ipca_replay_debrief_shares share_record
             ' . $deliveryJoin . '
             WHERE share_record.debrief_id IN (' . $placeholders . ')
             ORDER BY share_record.id DESC'
        );
        $statement->execute($debriefIds);
        $map = array();
        foreach (($statement->fetchAll(PDO::FETCH_ASSOC) ?: array()) as $row) {
            $debriefId = (int)($row['debrief_id'] ?? 0);
            if ($debriefId > 0) {
                $map[$debriefId][] = $row;
            }
        }
        return $map;
    }

    /** @return array{name:string,email:string,type:string} */
    public function suggestedStudentRecipient(int $debriefId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT dispatch_record.crew_json
             FROM ipca_structured_debriefs debrief
             INNER JOIN ipca_manual_intake_bundles bundle ON bundle.id = debrief.bundle_id
             INNER JOIN ipca_cvr_dispatches dispatch_record ON dispatch_record.id = bundle.dispatch_id
             WHERE debrief.id = ? LIMIT 1'
        );
        $statement->execute(array($debriefId));
        $crew = json_decode((string)($statement->fetchColumn() ?: '[]'), true);
        if (!is_array($crew)) {
            return array('name' => '', 'email' => '', 'type' => 'student');
        }
        $candidate = null;
        foreach ($crew as $member) {
            if (!is_array($member)) {
                continue;
            }
            $role = strtolower(trim((string)($member['role'] ?? '')));
            if (!empty($member['is_primary_customer']) || $role === 'student' || $role === 'customer') {
                $candidate = $member;
                if (!empty($member['is_primary_customer'])) {
                    break;
                }
            }
        }
        if (!is_array($candidate)) {
            return array('name' => '', 'email' => '', 'type' => 'student');
        }
        $name = trim((string)($candidate['person_name'] ?? $candidate['personName'] ?? ''));
        $personId = (int)($candidate['person_id'] ?? 0);
        $email = '';
        if ($personId > 0) {
            $user = $this->pdo->prepare('SELECT name, email FROM users WHERE id = ? LIMIT 1');
            $user->execute(array($personId));
            $row = $user->fetch(PDO::FETCH_ASSOC);
            if (is_array($row)) {
                $name = trim((string)($row['name'] ?? '')) ?: $name;
                $email = strtolower(trim((string)($row['email'] ?? '')));
            }
        }
        return array('name' => $name, 'email' => $email, 'type' => 'student');
    }

    /** @param array<string,mixed> $share @return array<string,mixed> */
    public function sendLinkEmail(array $share, string $publicUrl, int $actorUserId): array
    {
        $shareId = (int)($share['id'] ?? 0);
        $email = strtolower(trim((string)($share['recipient_email'] ?? '')));
        $name = trim((string)($share['recipient_name'] ?? ''));
        if ($shareId <= 0 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('A valid replay share recipient is required.');
        }
        if (!$this->deliveryTableReady()) {
            throw new RuntimeException('Replay email delivery migration is not installed.');
        }
        require_once __DIR__ . '/mailer.php';
        $expires = gmdate('M j, Y H:i \U\T\C', strtotime((string)($share['expires_at'] ?? '')));
        $safeName = htmlspecialchars($name !== '' ? $name : 'Student', ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $safeUrl = htmlspecialchars($publicUrl, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $html = '<p>Dear ' . $safeName . ',</p>'
            . '<p>Your instructor has shared a private IPCA flight debrief and replay with you.</p>'
            . '<p><a href="' . $safeUrl . '">Open your private flight debrief</a></p>'
            . '<p>This link expires ' . htmlspecialchars($expires, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '.</p>'
            . '<p>The required passcode is intentionally not included in this email. Obtain it separately from your instructor.</p>';
        $result = cw_send_mail(array(
            'to' => array(array('email' => $email, 'name' => $name)),
            'subject' => 'Your private IPCA flight debrief',
            'html' => $html,
        ));
        $ok = !empty($result['ok']);
        $this->pdo->prepare(
            "UPDATE ipca_replay_debrief_share_deliveries
             SET delivery_status = ?, provider = ?, provider_message_id = ?,
                 delivery_error = ?, sent_at = CASE WHEN ? = 1 THEN CURRENT_TIMESTAMP(3) ELSE sent_at END
             WHERE share_id = ?"
        )->execute(array(
            $ok ? 'sent' : 'failed',
            mb_substr((string)($result['provider'] ?? ''), 0, 48),
            mb_substr((string)($result['message_id'] ?? ''), 0, 255),
            $ok ? null : mb_substr((string)($result['error'] ?? 'Email delivery failed.'), 0, 1000),
            $ok ? 1 : 0,
            $shareId,
        ));
        (new AuditEventService($this->pdo))->record(
            'replay_share_email_' . ($ok ? 'sent' : 'failed'),
            'ipca_replay_debrief_shares',
            (string)($share['share_uuid'] ?? $shareId),
            null,
            array('share_id' => $shareId, 'recipient_email_hash' => hash('sha256', $email)),
            $ok ? 'Private replay link emailed without passcode.' : 'Private replay email delivery failed.',
            'user',
            $actorUserId
        );
        $result['recipient_email'] = $email;
        return $result;
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
            'replay_opened' => false,
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
        $_SESSION['replay_share_grant']['replay_opened'] = false;
    }

    public function openReplay(string $plainToken): void
    {
        $share = $this->shareForToken($plainToken);
        $grant = $this->sessionGrant();
        if ((int)($grant['share_id'] ?? 0) !== (int)$share['id']
            || empty($grant['privacy_accepted'])) {
            throw new RuntimeException('This replay link is invalid or unavailable.');
        }
        $_SESSION['replay_share_grant']['replay_opened'] = true;
    }

    /** @return array<string,mixed> */
    public function debriefForGrantedShare(string $plainToken): array
    {
        $share = $this->shareForToken($plainToken);
        $grant = $this->sessionGrant();
        if ((int)($grant['share_id'] ?? 0) !== (int)$share['id']
            || empty($grant['privacy_accepted'])) {
            throw new RuntimeException('This replay link is invalid or unavailable.');
        }
        require_once __DIR__ . '/FlightDebriefService.php';
        return (new FlightDebriefService($this->pdo))->structuredDebrief((int)$share['debrief_id']);
    }

    /** @return array<string,mixed> */
    public function mediaGrant(string $recordingIdentifier): array
    {
        $grant = $this->sessionGrant();
        if (empty($grant['privacy_accepted']) || empty($grant['replay_opened'])) {
            throw new RuntimeException('Debrief review and privacy acceptance are required.');
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

    private function deliveryTableReady(): bool
    {
        try {
            $this->pdo->query('SELECT id FROM ipca_replay_debrief_share_deliveries LIMIT 0');
            return true;
        } catch (Throwable) {
            return false;
        }
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
