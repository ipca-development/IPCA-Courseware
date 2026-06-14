<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceAccess.php';
require_once __DIR__ . '/../../../src/publishing/ControlledPublishingMccfPreviewService.php';

header('Content-Type: application/json; charset=utf-8');

function mccf_api_json(int $code, array $payload): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$user = compliance_require_access($pdo);
$preview = new ControlledPublishingMccfPreviewService($pdo);

$raw = file_get_contents('php://input');
$input = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
if (!is_array($input)) {
    $input = $_GET;
}

$action = trim((string)($input['action'] ?? ''));
$requirementId = (int)($input['requirement_id'] ?? 0);

try {
    switch ($action) {
        case 'regulation_preview':
            $result = $preview->regulationPreview(
                $requirementId,
                trim((string)($input['rule_token'] ?? ''))
            );
            mccf_api_json(!empty($result['ok']) ? 200 : 404, $result);
            break;

        case 'manual_preview':
            $result = $preview->manualPreview(
                $requirementId,
                trim((string)($input['excerpt_key'] ?? ''))
            );
            mccf_api_json(!empty($result['ok']) ? 200 : 404, $result);
            break;

        case 'coverage_pair':
            if ($requirementId <= 0) {
                mccf_api_json(400, array('ok' => false, 'error' => 'requirement_id is required.'));
            }
            mccf_api_json(200, $preview->coveragePair($requirementId));
            break;

        default:
            mccf_api_json(400, array('ok' => false, 'error' => 'Unknown action.'));
    }
} catch (Throwable $e) {
    mccf_api_json(500, array('ok' => false, 'error' => $e->getMessage()));
}
