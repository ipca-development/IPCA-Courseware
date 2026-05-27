<?php
declare(strict_types=1);

require_once __DIR__ . '/mock_oral_bootstrap.php';
require_once __DIR__ . '/ConversationalOrchestrator.php';
require_once __DIR__ . '/MockOralDebriefService.php';
require_once __DIR__ . '/SessionQuotaService.php';
require_once __DIR__ . '/../remote_session_auth/remote_session_auth_constants.php';

final class MockOralSessionService
{
    public function __construct(private readonly PDO $pdo)
    {
        mo_ensure_tables($this->pdo);
    }

    public function loadSessionForUser(int $sessionId, int $userId): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM mock_oral_sessions WHERE id = ? AND user_id = ? LIMIT 1');
        $st->execute([$sessionId, $userId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function startSession(int $sessionId, int $userId): array
    {
        $session = $this->loadSessionForUser($sessionId, $userId);
        if (!$session) {
            throw new RuntimeException('Session not found.');
        }

        $status = (string)$session['status'];
        $orchestrator = new ConversationalOrchestrator($this->pdo);
        $blueprint = mo_json_decode($session['blueprint_json'] ?? null);

        if ($status === 'in_progress') {
            $transcript = $orchestrator->loadTranscript($sessionId);
            $nextTurn = $this->inferNextStudentTurnIndex($transcript);
            return [
                'session_id' => $sessionId,
                'status' => 'in_progress',
                'resumed' => true,
                'max_duration_sec' => (int)$session['max_duration_sec'],
                'transcript' => $transcript,
                'next_turn_index' => $nextTurn,
                'blueprint_summary' => [
                    'area_title' => (string)($blueprint['area_title'] ?? ''),
                    'cross_country_context' => (string)($blueprint['cross_country_context'] ?? ''),
                ],
            ];
        }

        if ($status !== 'ready') {
            throw new RuntimeException('Session is not ready to start.');
        }
        if ($blueprint === []) {
            throw new RuntimeException('Session blueprint is missing.');
        }

        $quotaSvc = new SessionQuotaService($this->pdo);
        $quotaCheck = $quotaSvc->canStartSession($userId, (int)$session['cohort_id']);
        if (empty($quotaCheck['allowed'])) {
            throw new RuntimeException((string)($quotaCheck['message'] ?? 'Cannot start session.'));
        }

        $this->pdo->prepare("
            UPDATE mock_oral_sessions
            SET status = 'in_progress', started_at = UTC_TIMESTAMP(), last_heartbeat_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP()
            WHERE id = ?
        ")->execute([$sessionId]);

        $quotaSvc->consumeSession($userId, (int)$session['cohort_id']);

        $opening = $orchestrator->nextMayaTurn($session, $blueprint, 0);
        $orchestrator->logTranscriptEvent($sessionId, 'maya', 0, (string)$opening['maya_text'], 'opening');

        return [
            'session_id' => $sessionId,
            'status' => 'in_progress',
            'max_duration_sec' => (int)$session['max_duration_sec'],
            'opening' => $opening,
            'next_turn_index' => 0,
            'transcript' => $orchestrator->loadTranscript($sessionId),
            'blueprint_summary' => [
                'area_title' => (string)($blueprint['area_title'] ?? ''),
                'cross_country_context' => (string)($blueprint['cross_country_context'] ?? ''),
            ],
        ];
    }

    /** @param list<array<string,mixed>> $transcript */
    private function inferNextStudentTurnIndex(array $transcript): int
    {
        $lastMayaTurn = 0;
        $lastStudentTurn = -1;
        foreach ($transcript as $row) {
            $turn = (int)($row['turn_index'] ?? 0);
            if (($row['role'] ?? '') === 'maya') {
                $lastMayaTurn = max($lastMayaTurn, $turn);
            }
            if (($row['role'] ?? '') === 'student') {
                $lastStudentTurn = max($lastStudentTurn, $turn);
            }
        }
        if ($lastStudentTurn < $lastMayaTurn) {
            return $lastMayaTurn;
        }
        return $lastMayaTurn + 1;
    }

    public function preflightSession(int $sessionId, int $userId): array
    {
        $session = $this->loadSessionForUser($sessionId, $userId);
        if (!$session) {
            throw new RuntimeException('Session not found.');
        }

        $status = (string)$session['status'];
        if (!in_array($status, ['ready', 'in_progress', 'turn_evaluating'], true)) {
            throw new RuntimeException('Session is not available.');
        }

        $resumed = in_array($status, ['in_progress', 'turn_evaluating'], true);
        $blueprint = mo_json_decode($session['blueprint_json'] ?? null);
        $result = [
            'session_id' => $sessionId,
            'status' => $status,
            'resumed' => $resumed,
            'max_duration_sec' => (int)$session['max_duration_sec'],
            'blueprint_summary' => [
                'area_title' => (string)($blueprint['area_title'] ?? ''),
                'cross_country_context' => (string)($blueprint['cross_country_context'] ?? ''),
            ],
        ];

        if ($resumed) {
            $orchestrator = new ConversationalOrchestrator($this->pdo);
            $transcript = $orchestrator->loadTranscript($sessionId);
            $result['transcript'] = $transcript;
            $result['next_turn_index'] = $this->inferNextStudentTurnIndex($transcript);
        } else {
            $quotaSvc = new SessionQuotaService($this->pdo);
            $quotaCheck = $quotaSvc->canStartSession($userId, (int)$session['cohort_id']);
            if (empty($quotaCheck['allowed'])) {
                throw new RuntimeException((string)($quotaCheck['message'] ?? 'Cannot start session.'));
            }
        }

        return $result;
    }

    public function heartbeat(int $sessionId, int $userId): array
    {
        $session = $this->loadSessionForUser($sessionId, $userId);
        if (!$session) {
            throw new RuntimeException('Session not found.');
        }
        if (!in_array((string)$session['status'], ['ready', 'in_progress', 'turn_evaluating'], true)) {
            return ['ok' => false, 'status' => (string)$session['status']];
        }

        $started = strtotime((string)($session['started_at'] ?? ''));
        $elapsed = $started > 0 ? time() - $started : 0;
        $max = (int)($session['max_duration_sec'] ?? RSA_SESSION_MAX_DURATION_SEC);

        if ($elapsed >= $max && (string)$session['status'] === 'in_progress') {
            return $this->completeSession($sessionId, $userId, true);
        }

        $this->pdo->prepare('UPDATE mock_oral_sessions SET last_heartbeat_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE id = ?')
            ->execute([$sessionId]);

        return [
            'ok' => true,
            'elapsed_sec' => $elapsed,
            'remaining_sec' => max(0, $max - $elapsed),
            'status' => (string)$session['status'],
        ];
    }

    public function submitStudentTurn(int $sessionId, int $userId, int $turnIndex, string $studentText): array
    {
        $session = $this->loadSessionForUser($sessionId, $userId);
        if (!$session) {
            throw new RuntimeException('Session not found.');
        }
        if (!in_array((string)$session['status'], ['in_progress', 'turn_evaluating'], true)) {
            throw new RuntimeException('Session is not active.');
        }

        $forcedComplete = $this->enforceServerTimeLimit($sessionId, $userId, $session);
        if ($forcedComplete !== null) {
            return ['forced_complete' => true] + $forcedComplete;
        }

        $studentText = trim($studentText);
        if ($studentText === '') {
            throw new RuntimeException('Empty answer.');
        }

        $this->pdo->prepare("UPDATE mock_oral_sessions SET status = 'turn_evaluating', updated_at = UTC_TIMESTAMP() WHERE id = ?")
            ->execute([$sessionId]);

        $blueprint = mo_json_decode($session['blueprint_json'] ?? null);
        $orchestrator = new ConversationalOrchestrator($this->pdo);
        $orchestrator->logTranscriptEvent($sessionId, 'student', $turnIndex, $studentText);
        $transcript = $orchestrator->loadTranscript($sessionId);
        $evaluation = $orchestrator->evaluateStudentTurn($session, $blueprint, $turnIndex, $studentText, $transcript);

        $followUp = (array)($evaluation['follow_up'] ?? []);
        $nextTurnIndex = $turnIndex;
        $mayaResponse = null;

        if (!empty($followUp['needed']) && trim((string)($followUp['maya_text'] ?? '')) !== '') {
            $mayaText = trim((string)$followUp['maya_text']);
            $orchestrator->logTranscriptEvent($sessionId, 'maya', $turnIndex, $mayaText, 'follow_up');
            $mayaResponse = ['turn_index' => $turnIndex, 'maya_text' => $mayaText, 'source' => 'follow_up'];
        } elseif (!empty($evaluation['advance_to_next_planned_turn'])) {
            $nextTurnIndex = $turnIndex + 1;
            $mayaResponse = $orchestrator->nextMayaTurn($session, $blueprint, $nextTurnIndex);
            $orchestrator->logTranscriptEvent($sessionId, 'maya', $nextTurnIndex, (string)$mayaResponse['maya_text'], 'planned');
        } else {
            $feedback = trim((string)($evaluation['feedback_for_student'] ?? ''));
            if ($feedback !== '') {
                $orchestrator->logTranscriptEvent($sessionId, 'maya', $turnIndex, $feedback, 'feedback');
                $mayaResponse = ['turn_index' => $turnIndex, 'maya_text' => $feedback, 'source' => 'feedback'];
            }
        }

        $this->pdo->prepare("UPDATE mock_oral_sessions SET status = 'in_progress', updated_at = UTC_TIMESTAMP() WHERE id = ?")
            ->execute([$sessionId]);

        return [
            'evaluation' => $evaluation,
            'maya_response' => $mayaResponse,
            'next_turn_index' => $nextTurnIndex,
        ];
    }

    public function completeSession(int $sessionId, int $userId, bool $timeExpired = false): array
    {
        $session = $this->loadSessionForUser($sessionId, $userId);
        if (!$session) {
            throw new RuntimeException('Session not found.');
        }
        if (in_array((string)$session['status'], ['completed', 'aborted', 'stale', 'failed'], true)) {
            return $this->getDebriefPayload($sessionId);
        }

        $scorePct = $this->computeSessionScore($sessionId);
        $this->pdo->prepare("
            UPDATE mock_oral_sessions
            SET status = 'debrief_generating', score_pct = ?, ended_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP()
            WHERE id = ?
        ")->execute([$scorePct, $sessionId]);

        $debriefSvc = new MockOralDebriefService($this->pdo);
        $debrief = $debriefSvc->generate($sessionId);

        $readiness = $scorePct >= 80 ? 'ready' : ($scorePct >= 65 ? 'in_progress' : 'needs_work');
        $this->pdo->prepare("
            UPDATE mock_oral_sessions SET status = 'completed', updated_at = UTC_TIMESTAMP() WHERE id = ?
        ")->execute([$sessionId]);

        $this->pdo->prepare('
            INSERT INTO student_mock_oral_module_progress
              (student_id, cohort_id, area_id, sessions_completed, best_score_pct, last_session_at, readiness_status, created_at, updated_at)
            VALUES (?, ?, ?, 1, ?, UTC_TIMESTAMP(), ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())
            ON DUPLICATE KEY UPDATE
              sessions_completed = sessions_completed + 1,
              best_score_pct = GREATEST(COALESCE(best_score_pct, 0), VALUES(best_score_pct)),
              last_session_at = UTC_TIMESTAMP(),
              readiness_status = VALUES(readiness_status),
              updated_at = UTC_TIMESTAMP()
        ')->execute([
            (int)$session['user_id'],
            (int)$session['cohort_id'],
            (int)$session['area_id'],
            $scorePct,
            $readiness,
        ]);

        return [
            'session_id' => $sessionId,
            'status' => 'completed',
            'score_pct' => $scorePct,
            'time_expired' => $timeExpired,
            'debrief' => $debrief,
        ];
    }

    public function abortSession(int $sessionId, int $userId): void
    {
        $session = $this->loadSessionForUser($sessionId, $userId);
        if (!$session) {
            throw new RuntimeException('Session not found.');
        }
        if (in_array((string)$session['status'], ['completed', 'aborted', 'stale'], true)) {
            return;
        }
        $this->pdo->prepare("
            UPDATE mock_oral_sessions SET status = 'aborted', ended_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE id = ?
        ")->execute([$sessionId]);
    }

    public function getDebriefPayload(int $sessionId): array
    {
        $st = $this->pdo->prepare('
            SELECT s.*, d.written_debrief_html, d.written_debrief_text, d.weak_areas_json, d.remediation_json
            FROM mock_oral_sessions s
            LEFT JOIN mock_oral_debriefs d ON d.session_id = s.id
            WHERE s.id = ?
            LIMIT 1
        ');
        $st->execute([$sessionId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException('Session not found.');
        }
        return [
            'session' => $row,
            'debrief' => [
                'written_debrief_text' => (string)($row['written_debrief_text'] ?? ''),
                'written_debrief_html' => (string)($row['written_debrief_html'] ?? ''),
                'weak_areas' => mo_json_decode((string)($row['weak_areas_json'] ?? '')),
                'remediation' => mo_json_decode((string)($row['remediation_json'] ?? '')),
            ],
        ];
    }

    private function computeSessionScore(int $sessionId): float
    {
        $st = $this->pdo->prepare('SELECT AVG(score_pct) FROM mock_oral_turn_evaluations WHERE session_id = ?');
        $st->execute([$sessionId]);
        $avg = (float)$st->fetchColumn();
        return round(max(0, min(100, $avg)), 2);
    }

    /**
     * Hard-stop enforcement: backend owns session time limits (client timer is display-only).
     *
     * @return array|null Complete-session payload when time expired, otherwise null.
     */
    private function enforceServerTimeLimit(int $sessionId, int $userId, array $session): ?array
    {
        $started = strtotime((string)($session['started_at'] ?? ''));
        if ($started <= 0) {
            return null;
        }
        $max = (int)($session['max_duration_sec'] ?? RSA_SESSION_MAX_DURATION_SEC);
        if ((time() - $started) < $max) {
            return null;
        }

        return $this->completeSession($sessionId, $userId, true);
    }
}
