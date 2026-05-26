<?php
declare(strict_types=1);

require_once __DIR__ . '/mock_oral_bootstrap.php';
require_once __DIR__ . '/WeakAreaAggregationService.php';
require_once __DIR__ . '/SessionBlueprintService.php';

function mo_prep_fire_background_run(int $sessionId, string $cookieHeader = ''): void
{
    $host = getenv('CW_APP_BASE_URL') ?: 'https://ipca.training';
    $url = rtrim($host, '/') . '/student/api/mock_oral_blueprint_run.php?session_id=' . urlencode((string)$sessionId);
    $headers = [];
    if ($cookieHeader !== '') {
        $headers[] = 'Cookie: ' . $cookieHeader;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT_MS => 3000,
        CURLOPT_CONNECTTIMEOUT_MS => 5000,
        CURLOPT_FRESH_CONNECT => true,
        CURLOPT_FORBID_REUSE => true,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    @curl_exec($ch);
    @curl_close($ch);
}

function mo_prep_get_open_session(PDO $pdo, int $userId, int $cohortId, int $areaId): ?array
{
    mo_ensure_tables($pdo);
    $st = $pdo->prepare("
        SELECT * FROM mock_oral_sessions
        WHERE user_id = ? AND cohort_id = ? AND area_id = ?
          AND status IN ('blueprint_generating','ready','in_progress','turn_evaluating')
        ORDER BY id DESC
        LIMIT 1
    ");
    $st->execute([$userId, $cohortId, $areaId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function mo_prep_session_is_ready(array $session): bool
{
    if ((string)($session['status'] ?? '') !== 'ready') {
        return false;
    }
    $blueprint = mo_json_decode($session['blueprint_json'] ?? null);
    return $blueprint !== [];
}

function mo_prep_progress_label(array $session): array
{
    $status = (string)($session['status'] ?? '');
    if ($status === 'ready' && mo_prep_session_is_ready($session)) {
        return [
            'label' => 'Ready to start',
            'sub' => 'Mock Oral Exam',
            'class' => 'ok',
            'pct' => 100,
        ];
    }
    if ($status === 'failed') {
        return [
            'label' => 'Preparation failed',
            'sub' => 'Mock Oral Exam',
            'class' => 'warn',
            'pct' => 100,
        ];
    }

    $created = strtotime((string)($session['created_at'] ?? ''));
    $elapsed = $created > 0 ? max(0, time() - $created) : 0;
    $steps = [
        ['pct' => 18, 'label' => 'Analyzing your weak areas…'],
        ['pct' => 42, 'label' => 'Building your ACS oral exam blueprint…'],
        ['pct' => 68, 'label' => 'Preparing Maya…'],
        ['pct' => 88, 'label' => 'Almost ready…'],
    ];
    $idx = min(count($steps) - 1, (int)floor($elapsed / 8));
    $step = $steps[$idx];

    return [
        'label' => (string)$step['label'],
        'sub' => 'Preparing Mock Oral',
        'class' => 'info',
        'pct' => (int)$step['pct'],
    ];
}

function mo_prep_meta_from_button(array $buttonState, string $sessionUrl): array
{
    $empty = [
        'show_bar' => false,
        'show_button' => false,
        'button_href' => '',
        'button_label' => 'Start Mock Oral Exam',
        'prepared' => false,
        'preparing' => false,
        'label' => '',
        'sub' => 'Mock Oral Exam',
        'class' => 'neutral',
        'pct' => 0,
        'session_id' => null,
    ];

    $prep = (array)($buttonState['prep'] ?? []);
    if ($prep) {
        return array_merge($empty, $prep);
    }

    if (!empty($buttonState['href'])) {
        return array_merge($empty, [
            'show_button' => true,
            'button_href' => (string)$buttonState['href'],
            'button_label' => (string)($buttonState['label'] ?: 'Start Mock Oral Exam'),
            'prepared' => true,
            'session_id' => $buttonState['session_id'] ?? null,
        ]);
    }

    return $empty;
}

/**
 * Schedule async blueprint generation for a mock oral session shell.
 */
function mo_prep_schedule_mock_oral(
    PDO $pdo,
    int $userId,
    int $cohortId,
    int $areaId,
    int $sessionId,
    string $trigger = 'code_verified',
    string $cookieHeader = ''
): array {
    try {
        mo_ensure_tables($pdo);
        $st = $pdo->prepare('
            SELECT * FROM mock_oral_sessions
            WHERE id = ? AND user_id = ? AND cohort_id = ? AND area_id = ?
            LIMIT 1
        ');
        $st->execute([$sessionId, $userId, $cohortId, $areaId]);
        $session = $st->fetch(PDO::FETCH_ASSOC);
        if (!$session) {
            return ['ok' => false, 'error' => 'Session not found', 'trigger' => $trigger];
        }

        if (mo_prep_session_is_ready($session)) {
            return ['ok' => true, 'skipped' => true, 'reason' => 'already_ready', 'session_id' => $sessionId];
        }

        if ((string)$session['status'] !== 'blueprint_generating') {
            $pdo->prepare("
                UPDATE mock_oral_sessions
                SET status = 'blueprint_generating', updated_at = UTC_TIMESTAMP()
                WHERE id = ?
            ")->execute([$sessionId]);
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        mo_prep_fire_background_run($sessionId, $cookieHeader);

        return ['ok' => true, 'scheduled' => true, 'trigger' => $trigger, 'session_id' => $sessionId];
    } catch (Throwable $e) {
        error_log('mo_prep_schedule_mock_oral failed [' . $trigger . ']: ' . $e->getMessage());
        return ['ok' => false, 'error' => $e->getMessage(), 'trigger' => $trigger];
    }
}

function mo_prep_run_blueprint(PDO $pdo, int $sessionId): array
{
    mo_ensure_tables($pdo);
    $st = $pdo->prepare('SELECT * FROM mock_oral_sessions WHERE id = ? LIMIT 1');
    $st->execute([$sessionId]);
    $session = $st->fetch(PDO::FETCH_ASSOC);
    if (!$session) {
        throw new RuntimeException('Session not found.');
    }

    if (mo_prep_session_is_ready($session)) {
        return ['ok' => true, 'session_id' => $sessionId, 'status' => 'ready', 'skipped' => true];
    }

    if ((string)$session['status'] !== 'blueprint_generating') {
        throw new RuntimeException('Session is not awaiting blueprint generation.');
    }

    $userId = (int)$session['user_id'];
    $cohortId = (int)$session['cohort_id'];
    $areaId = (int)$session['area_id'];
    $catalogId = (int)$session['catalog_id'];

    $weakSvc = new WeakAreaAggregationService($pdo);
    $weakProfile = $weakSvc->buildProfile($userId, $cohortId, $catalogId, $areaId);
    $blueprintSvc = new SessionBlueprintService($pdo);
    $blueprint = $blueprintSvc->generate($userId, $cohortId, $catalogId, $areaId, $weakProfile);
    $scenario = [
        'cross_country_context' => (string)($blueprint['cross_country_context'] ?? ''),
        'opening' => (string)($blueprint['opening_scenario'] ?? ''),
    ];

    $pdo->prepare("
        UPDATE mock_oral_sessions
        SET status = 'ready',
            weak_area_snapshot_json = ?,
            blueprint_json = ?,
            scenario_json = ?,
            updated_at = UTC_TIMESTAMP()
        WHERE id = ?
    ")->execute([
        mo_json_encode($weakProfile),
        mo_json_encode($blueprint),
        mo_json_encode($scenario),
        $sessionId,
    ]);

    return ['ok' => true, 'session_id' => $sessionId, 'status' => 'ready'];
}
