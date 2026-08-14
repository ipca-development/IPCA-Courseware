<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/publishing/ControlledPublishingReaderAccessService.php';

header('Content-Type: application/json; charset=utf-8');

function mr_auth_json(int $code, array $payload): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function mr_auth_input(): array
{
    $raw = file_get_contents('php://input');
    if (is_string($raw) && $raw !== '') {
        $json = json_decode($raw, true);
        if (is_array($json)) {
            return $json;
        }
    }

    return array_merge($_GET, $_POST);
}

function mr_auth_user_payload(?array $user): ?array
{
    if ($user === null) {
        return null;
    }

    return array(
        'id' => (int)($user['id'] ?? 0),
        'email' => (string)($user['email'] ?? ''),
        'name' => (string)($user['name'] ?? ''),
        'role' => (string)($user['role'] ?? ''),
    );
}

/**
 * @param array<string,mixed>|null $user
 */
function mr_auth_can_preview_drafts(
    ?array $user,
    ControlledPublishingReaderAccessService $access
): bool
{
    return $access->canPreviewDraftManuals($user);
}

try {
    $access = new ControlledPublishingReaderAccessService();

    $action = strtolower(trim((string)($_GET['action'] ?? '')));
    if ($action === '' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $in = mr_auth_input();
        $action = strtolower(trim((string)($in['action'] ?? 'login')));
    }

    switch ($action) {
        case 'session':
            $user = cw_current_user($pdo);
            if ($user === null) {
                mr_auth_json(200, array(
                    'ok' => true,
                    'logged_in' => false,
                    'user' => null,
                    'can_preview_draft_manuals' => false,
                ));
            }
            mr_auth_json(200, array(
                'ok' => true,
                'logged_in' => true,
                'user' => mr_auth_user_payload($user),
                'can_read_manuals' => $access->canReadManuals($user),
                'can_preview_draft_manuals' => mr_auth_can_preview_drafts($user, $access),
            ));

        case 'login':
            $in = mr_auth_input();
            $email = trim((string)($in['email'] ?? ''));
            $password = (string)($in['password'] ?? '');
            if ($email === '' || $password === '') {
                throw new RuntimeException('Email and password are required.');
            }
            if (!cw_login($pdo, $email, $password)) {
                mr_auth_json(401, array('ok' => false, 'error' => 'Invalid email or password.'));
            }
            $user = cw_current_user($pdo);
            if (!$access->canReadManuals($user)) {
                cw_logout();
                mr_auth_json(403, array('ok' => false, 'error' => 'Your account cannot access manuals.'));
            }
            mr_auth_json(200, array(
                'ok' => true,
                'logged_in' => true,
                'user' => mr_auth_user_payload($user),
                'can_read_manuals' => true,
                'can_preview_draft_manuals' => mr_auth_can_preview_drafts($user, $access),
            ));

        case 'logout':
            cw_logout();
            mr_auth_json(200, array('ok' => true, 'logged_in' => false));

        default:
            mr_auth_json(400, array('ok' => false, 'error' => 'Unknown action'));
    }
} catch (RuntimeException $e) {
    mr_auth_json(400, array('ok' => false, 'error' => $e->getMessage()));
} catch (Throwable $e) {
    mr_auth_json(500, array('ok' => false, 'error' => 'Server error'));
}
