<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/courseware_progression_v2.php';
require_once __DIR__ . '/../../../src/mock_oral/mock_oral_bootstrap.php';
require_once __DIR__ . '/../../../src/mock_oral/MockOralSessionService.php';
require_once __DIR__ . '/../../../src/mock_oral/ConversationalOrchestrator.php';
require_once __DIR__ . '/../../../src/mock_oral/HeyGenLiveAvatarService.php';
require_once __DIR__ . '/../../../src/mock_oral/SessionQuotaService.php';

cw_require_login();
header('Content-Type: application/json; charset=utf-8');

function mo_api_out(array $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function mo_api_body(): array
{
    $raw = file_get_contents('php://input');
    $decoded = json_decode((string)$raw, true);
    return is_array($decoded) ? $decoded : [];
}

$u = cw_current_user($pdo);
$role = (string)($u['role'] ?? '');
if ($role !== 'student' && $role !== 'admin') {
    mo_api_out(['ok' => false, 'error' => 'Forbidden'], 403);
}

$userId = (int)cw_student_view_user_id($pdo, $u);
$body = mo_api_body();
$action = trim((string)($_GET['action'] ?? $body['action'] ?? ''));

try {
    $engine = new CoursewareProgressionV2($pdo);
    $sessionSvc = new MockOralSessionService($pdo);
    $quotaSvc = new SessionQuotaService($pdo);

    switch ($action) {
        case 'module_states':
            $cohortId = (int)($_GET['cohort_id'] ?? $body['cohort_id'] ?? 0);
            if ($cohortId <= 0) {
                throw new RuntimeException('Missing cohort_id.');
            }
            $catalogId = mo_default_catalog_id($pdo);
            $areas = $engine->listMockOralAreas($catalogId);
            $modules = [];
            foreach ($areas as $area) {
                $state = $engine->getMockOralModuleButtonState($userId, $cohortId, (int)$area['id']);
                $progress = null;
                $pst = $pdo->prepare('SELECT * FROM student_mock_oral_module_progress WHERE student_id = ? AND cohort_id = ? AND area_id = ? LIMIT 1');
                $pst->execute([$userId, $cohortId, (int)$area['id']]);
                $progress = $pst->fetch(PDO::FETCH_ASSOC) ?: null;
                $modules[] = [
                    'area' => $area,
                    'button' => $state,
                    'progress' => $progress,
                ];
            }
            mo_api_out([
                'ok' => true,
                'theory_complete' => $engine->isTheoryCompleteForMockOral($userId, $cohortId),
                'mock_oral_enabled' => $engine->hasMockOralPermission($userId, $cohortId, $catalogId),
                'modules' => $modules,
            ]);
            break;

        case 'get_session':
            $sessionId = (int)($_GET['session_id'] ?? $body['session_id'] ?? 0);
            $session = $sessionSvc->loadSessionForUser($sessionId, $userId);
            if (!$session) {
                throw new RuntimeException('Session not found.');
            }
            $area = mo_area_by_id($pdo, (int)$session['area_id']);
            mo_api_out([
                'ok' => true,
                'session' => $session,
                'area' => $area,
                'blueprint' => mo_json_decode((string)($session['blueprint_json'] ?? '')),
            ]);
            break;

        case 'get_transcript':
            $sessionId = (int)($_GET['session_id'] ?? $body['session_id'] ?? 0);
            $session = $sessionSvc->loadSessionForUser($sessionId, $userId);
            if (!$session) {
                throw new RuntimeException('Session not found.');
            }
            $orchestrator = new ConversationalOrchestrator($pdo);
            mo_api_out([
                'ok' => true,
                'transcript' => $orchestrator->loadTranscript($sessionId),
                'status' => (string)$session['status'],
            ]);
            break;

        case 'start_session':
            $sessionId = (int)($body['session_id'] ?? 0);
            $result = $sessionSvc->startSession($sessionId, $userId);
            try {
                $heygen = new HeyGenLiveAvatarService();
                $token = $heygen->mintSessionToken($sessionId, $userId);
                if (($token['presentation_mode'] ?? '') === 'heygen' && !empty($token['token'])) {
                    $pdo->prepare('UPDATE mock_oral_sessions SET heygen_token_issued_at = UTC_TIMESTAMP(), heygen_session_id = ? WHERE id = ?')
                        ->execute([(string)($token['liveavatar_session_id'] ?? $token['token']), $sessionId]);
                }
                $result['heygen'] = $token;
            } catch (Throwable $heygenError) {
                $result['heygen'] = [
                    'ok' => true,
                    'presentation_mode' => 'fallback',
                    'message' => 'LiveAvatar unavailable; AI voice fallback active.',
                ];
            }
            mo_api_out(['ok' => true] + $result);
            break;

        case 'heartbeat':
            $sessionId = (int)($body['session_id'] ?? 0);
            $result = $sessionSvc->heartbeat($sessionId, $userId);
            mo_api_out(['ok' => true] + $result);
            break;

        case 'submit_turn':
            $sessionId = (int)($body['session_id'] ?? 0);
            $turnIndex = (int)($body['turn_index'] ?? 0);
            $text = trim((string)($body['transcript'] ?? $body['student_text'] ?? ''));
            $result = $sessionSvc->submitStudentTurn($sessionId, $userId, $turnIndex, $text);
            mo_api_out(['ok' => true] + $result);
            break;

        case 'complete_session':
            $sessionId = (int)($body['session_id'] ?? 0);
            $result = $sessionSvc->completeSession($sessionId, $userId, false);
            mo_api_out(['ok' => true] + $result);
            break;

        case 'abort_session':
            $sessionId = (int)($body['session_id'] ?? 0);
            $sessionSvc->abortSession($sessionId, $userId);
            mo_api_out(['ok' => true]);
            break;

        case 'get_debrief':
            $sessionId = (int)($_GET['session_id'] ?? $body['session_id'] ?? 0);
            $payload = $sessionSvc->getDebriefPayload($sessionId);
            if ((int)$payload['session']['user_id'] !== $userId) {
                throw new RuntimeException('Forbidden.');
            }
            mo_api_out(['ok' => true] + $payload);
            break;

        case 'start_on_site':
            throw new RuntimeException('Use Start Mock Oral Exam to begin the standard authentication flow.');

        default:
            throw new RuntimeException('Unknown action.');
    }
} catch (Throwable $e) {
    mo_api_out(['ok' => false, 'error' => $e->getMessage()], 400);
}
