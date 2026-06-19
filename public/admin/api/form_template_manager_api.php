<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/flight_training/FormTemplateService.php';

header('Content-Type: application/json; charset=utf-8');

function ft_form_template_json(int $code, array $payload): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function ft_form_template_input(): array
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
$service = new FormTemplateService($pdo);

$action = (string)($_GET['action'] ?? $_POST['action'] ?? '');
if ($action === '' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = ft_form_template_input();
    $action = (string)($input['action'] ?? '');
}

try {
    switch ($action) {
        case 'list':
            ft_form_template_json(200, array(
                'ok' => true,
                'schema_ready' => $service->schemaReady(),
                'missing_tables' => $service->missingTables(),
                'templates' => $service->schemaReady() ? $service->listTemplates() : array(),
            ));

        case 'create':
            $input = ft_form_template_input();
            $templateId = $service->createTemplate($input, $actorUserId);
            ft_form_template_json(200, array(
                'ok' => true,
                'template_id' => $templateId,
            ));

        case 'import_pdf':
            $input = $_POST;
            $templateId = $service->importPdfTemplate(
                array(
                    'title' => (string)($input['title'] ?? ''),
                    'template_key' => (string)($input['template_key'] ?? ''),
                    'category' => (string)($input['category'] ?? 'Checkride'),
                    'description' => (string)($input['description'] ?? ''),
                    'version_label' => (string)($input['version_label'] ?? '1.0'),
                    'import_profile' => (string)($input['import_profile'] ?? 'private_sel_practical_test'),
                ),
                is_array($_FILES['source_pdf'] ?? null) ? $_FILES['source_pdf'] : array(),
                $actorUserId
            );
            ft_form_template_json(200, array(
                'ok' => true,
                'template_id' => $templateId,
            ));

        case 'archive':
            $input = ft_form_template_input();
            $service->archiveTemplate((int)($input['template_id'] ?? 0), $actorUserId);
            ft_form_template_json(200, array('ok' => true));

        case 'activate_version':
            $input = ft_form_template_input();
            $service->activateTemplateVersion((int)($input['template_version_id'] ?? 0), $actorUserId);
            ft_form_template_json(200, array('ok' => true));

        default:
            ft_form_template_json(400, array('ok' => false, 'error' => 'Unknown action.'));
    }
} catch (Throwable $e) {
    ft_form_template_json(400, array('ok' => false, 'error' => $e->getMessage()));
}
