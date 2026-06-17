<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/flight_training/FormTemplateEditorService.php';

header('Content-Type: application/json; charset=utf-8');

function ft_form_editor_json(int $code, array $payload): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function ft_form_editor_input(): array
{
    $raw = file_get_contents('php://input');
    if (is_string($raw) && $raw !== '') {
        $json = json_decode($raw, true);
        if (is_array($json)) {
            return $json;
        }
    }
    return $_POST;
}

cw_require_admin();

$user = cw_current_user($pdo);
$actorUserId = (int)($user['id'] ?? 0);
$service = new FormTemplateEditorService($pdo);

$action = (string)($_GET['action'] ?? $_POST['action'] ?? '');
if ($action === '' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = ft_form_editor_input();
    $action = (string)($input['action'] ?? '');
}

try {
    switch ($action) {
        case 'load':
            $templateId = (int)($_GET['template_id'] ?? $_POST['template_id'] ?? 0);
            ft_form_editor_json(200, array('ok' => true, 'data' => $service->loadEditor($templateId, $actorUserId)));

        case 'save_content':
            $input = ft_form_editor_input();
            $templateId = (int)($input['template_id'] ?? 0);
            $versionId = (int)($input['template_version_id'] ?? 0);
            $document = is_array($input['document'] ?? null) ? $input['document'] : array();
            ft_form_editor_json(200, array(
                'ok' => true,
                'data' => $service->saveContent($templateId, $versionId, $document, $actorUserId),
            ));

        case 'sync_fields':
            $input = ft_form_editor_input();
            $templateId = (int)($input['template_id'] ?? 0);
            $versionId = (int)($input['template_version_id'] ?? 0);
            $document = is_array($input['document'] ?? null) ? $input['document'] : array();
            $saved = $service->saveContent($templateId, $versionId, $document, $actorUserId);
            ft_form_editor_json(200, array('ok' => true, 'fields' => $saved['fields']));

        case 'variables':
            ft_form_editor_json(200, array('ok' => true, 'variables' => FormTemplateEditorService::variableCatalog()));

        default:
            ft_form_editor_json(400, array('ok' => false, 'error' => 'Unknown action.'));
    }
} catch (Throwable $e) {
    ft_form_editor_json(400, array('ok' => false, 'error' => $e->getMessage()));
}
