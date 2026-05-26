<?php
declare(strict_types=1);

require_once __DIR__ . '/mock_oral_bootstrap.php';
require_once __DIR__ . '/../remote_session_auth/remote_session_auth_constants.php';

final class SessionQuotaService
{
    public function __construct(private readonly PDO $pdo)
    {
        mo_ensure_tables($this->pdo);
    }

    public function sessionsAllowedPerWeek(int $userId, int $cohortId): int
    {
        try {
            $st = $this->pdo->prepare("
                SELECT value_int FROM system_policy_values
                WHERE policy_key = 'mock_oral_sessions_per_week'
                  AND (cohort_id = ? OR cohort_id IS NULL)
                ORDER BY cohort_id DESC
                LIMIT 1
            ");
            $st->execute([$cohortId]);
            $v = (int)$st->fetchColumn();
            return $v > 0 ? $v : 3;
        } catch (Throwable $e) {
            return 3;
        }
    }

    public function getOrCreateCurrentPeriod(int $userId, int $cohortId): array
    {
        $periodStart = gmdate('Y-m-d', strtotime('monday this week UTC'));
        $periodEnd = gmdate('Y-m-d', strtotime('sunday this week UTC'));

        $st = $this->pdo->prepare('
            SELECT * FROM mock_oral_usage_quotas
            WHERE user_id = ? AND cohort_id = ? AND period_start = ?
            LIMIT 1
        ');
        $st->execute([$userId, $cohortId, $periodStart]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return $row;
        }

        $allowed = $this->sessionsAllowedPerWeek($userId, $cohortId);
        $ins = $this->pdo->prepare('
            INSERT INTO mock_oral_usage_quotas
              (user_id, cohort_id, period_start, period_end, sessions_allowed, sessions_used, heygen_minutes_used, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, 0, 0, UTC_TIMESTAMP(), UTC_TIMESTAMP())
        ');
        $ins->execute([$userId, $cohortId, $periodStart, $periodEnd, $allowed]);

        $st->execute([$userId, $cohortId, $periodStart]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    public function canStartSession(int $userId, int $cohortId): array
    {
        $quota = $this->getOrCreateCurrentPeriod($userId, $cohortId);
        $allowed = (int)($quota['sessions_allowed'] ?? 3);
        $used = (int)($quota['sessions_used'] ?? 0);
        if ($used >= $allowed) {
            return [
                'allowed' => false,
                'reason' => 'weekly_quota',
                'message' => 'You have used all mock oral sessions for this week.',
                'sessions_allowed' => $allowed,
                'sessions_used' => $used,
            ];
        }

        $active = $this->getActiveSession($userId, $cohortId);
        if ($active) {
            return [
                'allowed' => false,
                'reason' => 'active_session',
                'message' => 'You already have an active mock oral session.',
                'session_id' => (int)$active['id'],
            ];
        }

        return ['allowed' => true, 'sessions_allowed' => $allowed, 'sessions_used' => $used];
    }

    public function consumeSession(int $userId, int $cohortId): void
    {
        $quota = $this->getOrCreateCurrentPeriod($userId, $cohortId);
        $this->pdo->prepare('
            UPDATE mock_oral_usage_quotas
            SET sessions_used = sessions_used + 1, updated_at = UTC_TIMESTAMP()
            WHERE id = ?
        ')->execute([(int)$quota['id']]);
    }

    public function recordHeygenMinutes(int $userId, int $cohortId, float $minutes): void
    {
        $quota = $this->getOrCreateCurrentPeriod($userId, $cohortId);
        $this->pdo->prepare('
            UPDATE mock_oral_usage_quotas
            SET heygen_minutes_used = heygen_minutes_used + ?, updated_at = UTC_TIMESTAMP()
            WHERE id = ?
        ')->execute([max(0, $minutes), (int)$quota['id']]);
    }

    public function getActiveSession(int $userId, int $cohortId): ?array
    {
        $st = $this->pdo->prepare("
            SELECT * FROM mock_oral_sessions
            WHERE user_id = ? AND cohort_id = ?
              AND status IN ('ready','in_progress','turn_evaluating','blueprint_generating')
            ORDER BY id DESC
            LIMIT 1
        ");
        $st->execute([$userId, $cohortId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        if (in_array((string)$row['status'], ['in_progress', 'turn_evaluating'], true)) {
            $started = strtotime((string)($row['started_at'] ?? ''));
            if ($started > 0 && (time() - $started) > RSA_SESSION_MAX_DURATION_SEC + 60) {
                $this->markStale((int)$row['id']);
                return null;
            }
        }
        return $row;
    }

    public function markStale(int $sessionId): void
    {
        $this->pdo->prepare("
            UPDATE mock_oral_sessions
            SET status = 'stale', stale_at = UTC_TIMESTAMP(), ended_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP()
            WHERE id = ? AND status IN ('ready','in_progress','turn_evaluating','blueprint_generating')
        ")->execute([$sessionId]);
    }

    public function expireStaleSessions(int $userId, int $cohortId): int
    {
        $st = $this->pdo->prepare("
            SELECT id, status, started_at, last_heartbeat_at, updated_at
            FROM mock_oral_sessions
            WHERE user_id = ? AND cohort_id = ?
              AND status IN ('in_progress','turn_evaluating','ready')
        ");
        $st->execute([$userId, $cohortId]);
        $count = 0;
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $ref = (string)($row['last_heartbeat_at'] ?? $row['started_at'] ?? $row['updated_at'] ?? '');
            $ts = strtotime($ref);
            if ($ts <= 0) {
                continue;
            }
            $limit = in_array((string)$row['status'], ['in_progress', 'turn_evaluating'], true)
                ? RSA_SESSION_MAX_DURATION_SEC
                : (RSA_ACTIVE_SESSION_MINUTES * 60);
            if ((time() - $ts) > $limit) {
                $this->markStale((int)$row['id']);
                $count++;
            }
        }
        return $count;
    }
}
