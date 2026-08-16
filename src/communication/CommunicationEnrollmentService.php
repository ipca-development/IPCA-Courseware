<?php
declare(strict_types=1);

/**
 * Staff enrollment roster for the native IPCA app.
 * Read-only. Does not send messages or mutate devices.
 */
final class CommunicationEnrollmentService
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return array<string,mixed>
     */
    public function snapshot(): array
    {
        $people = $this->people();
        $iphones = 0;
        $ipads = 0;
        $pushReady = 0;
        foreach ($people as $person) {
            if (!empty($person['has_iphone'])) {
                $iphones++;
            }
            if (!empty($person['has_ipad'])) {
                $ipads++;
            }
            if (!empty($person['push_ready'])) {
                $pushReady++;
            }
        }
        return array(
            'people' => $people,
            'devices' => $this->devices(),
            'reports' => $this->reports(),
            'stats' => array(
                'enrolled_users' => count($people),
                'iphones' => $iphones,
                'ipads' => $ipads,
                'push_ready' => $pushReady,
                'open_acknowledgements' => $this->openAcknowledgements(),
                'community_reports' => $this->reportCount(),
                'failed_pushes' => $this->failedPushes(),
            ),
        );
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function people(): array
    {
        $sql = "
            SELECT u.id,
                   u.uuid,
                   u.name,
                   u.email,
                   u.role,
                   u.status,
                   MAX(CASE WHEN d.platform = 'iphone' THEN 1 ELSE 0 END) AS has_iphone,
                   MAX(CASE WHEN d.platform = 'ipad' THEN 1 ELSE 0 END) AS has_ipad,
                   MAX(CASE
                         WHEN d.push_authorized = 1
                          AND d.apns_token IS NOT NULL
                          AND TRIM(d.apns_token) != ''
                         THEN 1 ELSE 0
                       END) AS push_ready,
                   MAX(d.last_seen_at_utc) AS last_seen_at_utc,
                   MAX(d.last_sync_at_utc) AS last_sync_at_utc
            FROM ipca_communication_devices d
            INNER JOIN users u ON u.id = d.user_id
            WHERE d.revoked_at_utc IS NULL
            GROUP BY u.id, u.uuid, u.name, u.email, u.role, u.status
            ORDER BY MAX(d.last_seen_at_utc) DESC, u.name ASC
        ";
        try {
            $rows = $this->pdo->query($sql);
        } catch (Throwable) {
            return array();
        }
        if ($rows === false) {
            return array();
        }
        $out = array();
        foreach ($rows as $row) {
            $out[] = array(
                'user_id' => (int)$row['id'],
                'user_uuid' => (string)$row['uuid'],
                'name' => (string)$row['name'],
                'email' => (string)$row['email'],
                'role' => (string)$row['role'],
                'status' => (string)$row['status'],
                'has_iphone' => (int)$row['has_iphone'] === 1,
                'has_ipad' => (int)$row['has_ipad'] === 1,
                'push_ready' => (int)$row['push_ready'] === 1,
                'last_seen_at_utc' => $row['last_seen_at_utc'] !== null ? (string)$row['last_seen_at_utc'] : null,
                'last_sync_at_utc' => $row['last_sync_at_utc'] !== null ? (string)$row['last_sync_at_utc'] : null,
            );
        }
        return $out;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function devices(): array
    {
        $sql = "
            SELECT d.id,
                   d.device_uuid,
                   d.platform,
                   d.model,
                   d.app_version,
                   d.push_authorized,
                   d.apns_token,
                   d.last_seen_at_utc,
                   d.last_sync_at_utc,
                   u.id AS user_id,
                   u.name,
                   u.email
            FROM ipca_communication_devices d
            INNER JOIN users u ON u.id = d.user_id
            WHERE d.revoked_at_utc IS NULL
            ORDER BY d.last_seen_at_utc DESC, d.id DESC
        ";
        try {
            $rows = $this->pdo->query($sql);
        } catch (Throwable) {
            return array();
        }
        if ($rows === false) {
            return array();
        }
        $out = array();
        foreach ($rows as $row) {
            $token = trim((string)($row['apns_token'] ?? ''));
            $out[] = array(
                'device_id' => (int)$row['id'],
                'device_uuid' => (string)$row['device_uuid'],
                'platform' => (string)$row['platform'],
                'model' => (string)$row['model'],
                'app_version' => (string)$row['app_version'],
                'push_ready' => ((int)$row['push_authorized'] === 1 && $token !== ''),
                'user_id' => (int)$row['user_id'],
                'name' => (string)$row['name'],
                'email' => (string)$row['email'],
                'last_seen_at_utc' => $row['last_seen_at_utc'] !== null ? (string)$row['last_seen_at_utc'] : null,
                'last_sync_at_utc' => $row['last_sync_at_utc'] !== null ? (string)$row['last_sync_at_utc'] : null,
            );
        }
        return $out;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function reports(): array
    {
        $sql = "
            SELECT r.report_uuid,
                   r.reason,
                   r.details,
                   r.created_at_utc,
                   p.post_uuid,
                   p.caption,
                   reporter.name AS reporter_name,
                   author.name AS author_name
            FROM ipca_community_reports r
            INNER JOIN ipca_community_posts p ON p.id = r.post_id
            INNER JOIN users reporter ON reporter.id = r.reporter_user_id
            INNER JOIN users author ON author.id = p.author_user_id
            ORDER BY r.id DESC
            LIMIT 50
        ";
        try {
            $rows = $this->pdo->query($sql);
        } catch (Throwable) {
            return array();
        }
        if ($rows === false) {
            return array();
        }
        $out = array();
        foreach ($rows as $row) {
            $out[] = array(
                'report_uuid' => (string)$row['report_uuid'],
                'reason' => (string)$row['reason'],
                'details' => (string)$row['details'],
                'created_at_utc' => (string)$row['created_at_utc'],
                'post_uuid' => (string)$row['post_uuid'],
                'caption' => (string)$row['caption'],
                'reporter_name' => (string)$row['reporter_name'],
                'author_name' => (string)$row['author_name'],
            );
        }
        return $out;
    }

    public function openAcknowledgements(): int
    {
        try {
            $value = $this->pdo->query("
                SELECT COUNT(*)
                FROM ipca_communication_messages m
                INNER JOIN ipca_communication_conversation_members mem
                  ON mem.conversation_id = m.conversation_id AND mem.left_at_utc IS NULL
                LEFT JOIN ipca_communication_acknowledgements a
                  ON a.message_id = m.id AND a.user_id = mem.user_id
                WHERE m.requires_acknowledgement = 1
                  AND a.id IS NULL
            ")->fetchColumn();
            return (int)$value;
        } catch (Throwable) {
            return 0;
        }
    }

    private function reportCount(): int
    {
        try {
            return (int)$this->pdo->query('SELECT COUNT(*) FROM ipca_community_reports')->fetchColumn();
        } catch (Throwable) {
            return 0;
        }
    }

    private function failedPushes(): int
    {
        try {
            return (int)$this->pdo->query("
                SELECT COUNT(*)
                FROM ipca_communication_push_attempts
                WHERE accepted_at_utc IS NULL
                  AND failed_at_utc IS NOT NULL
            ")->fetchColumn();
        } catch (Throwable) {
            return 0;
        }
    }
}
