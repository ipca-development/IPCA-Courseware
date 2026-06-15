<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceAccess.php';
require_once __DIR__ . '/../../../src/publishing/ControlledPublishingMccfPreviewService.php';
require_once __DIR__ . '/../../../src/publishing/ControlledPublishingMccfManualLinkService.php';
require_once __DIR__ . '/../../../src/publishing/ControlledPublishingMccfBcaaViewService.php';

header('Content-Type: application/json; charset=utf-8');

function mccf_api_json(int $code, array $payload): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$user = compliance_require_access($pdo);
$preview = new ControlledPublishingMccfPreviewService($pdo);
$manualLinks = new ControlledPublishingMccfManualLinkService($pdo);
$bcaaView = new ControlledPublishingMccfBcaaViewService($pdo);

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

        case 'integrity_scores':
            $rawIds = $input['requirement_ids'] ?? array();
            if (!is_array($rawIds)) {
                mccf_api_json(400, array('ok' => false, 'error' => 'requirement_ids must be an array.'));
            }
            $ids = array_values(array_unique(array_filter(array_map('intval', $rawIds), static fn(int $id): bool => $id > 0)));
            if ($ids === array()) {
                mccf_api_json(400, array('ok' => false, 'error' => 'At least one requirement_id is required.'));
            }
            if (count($ids) > 60) {
                mccf_api_json(400, array('ok' => false, 'error' => 'Maximum 60 requirement_ids per request.'));
            }
            mccf_api_json(200, array(
                'ok' => true,
                'scores' => $bcaaView->integrityScoresForRequirements($ids),
            ));
            break;

        case 'manual_link_context':
            if ($requirementId <= 0) {
                mccf_api_json(400, array('ok' => false, 'error' => 'requirement_id is required.'));
            }
            $context = $manualLinks->getRequirementEditorContext($requirementId);
            if ($context === null) {
                mccf_api_json(404, array('ok' => false, 'error' => 'Requirement not found.'));
            }
            mccf_api_json(200, array('ok' => true) + $context);
            break;

        case 'manual_link_parts':
            mccf_api_json(200, array(
                'ok' => true,
                'parts' => $manualLinks->listParts(trim((string)($input['manual_code'] ?? 'OM'))),
            ));
            break;

        case 'manual_link_chapters':
            mccf_api_json(200, array(
                'ok' => true,
                'chapters' => $manualLinks->listChapters(
                    trim((string)($input['manual_code'] ?? 'OM')),
                    trim((string)($input['part'] ?? ''))
                ),
            ));
            break;

        case 'manual_link_sections':
            mccf_api_json(200, array(
                'ok' => true,
                'sections' => $manualLinks->listSections(
                    trim((string)($input['manual_code'] ?? 'OM')),
                    trim((string)($input['part'] ?? '')),
                    trim((string)($input['chapter'] ?? '')),
                    trim((string)($input['parent_section_ref'] ?? '')) ?: null
                ),
            ));
            break;

        case 'manual_link_add':
            if ($requirementId <= 0) {
                mccf_api_json(400, array('ok' => false, 'error' => 'requirement_id is required.'));
            }
            $manualCode = trim((string)($input['manual_code'] ?? 'OM'));
            $part = trim((string)($input['part'] ?? ''));
            $sectionRef = trim((string)($input['section_ref'] ?? ''));
            if ($sectionRef !== '') {
                mccf_api_json(200, $manualLinks->addLinkBySection(
                    $requirementId,
                    $manualCode,
                    $part,
                    $sectionRef,
                    trim((string)($input['link_type'] ?? 'PRIMARY')),
                    isset($input['notes']) ? (string)$input['notes'] : null
                ));
            }
            mccf_api_json(200, $manualLinks->addLink(
                $requirementId,
                (int)($input['excerpt_id'] ?? 0),
                trim((string)($input['link_type'] ?? 'PRIMARY')),
                isset($input['notes']) ? (string)$input['notes'] : null
            ));
            break;

        case 'manual_link_update':
            $linkId = (int)($input['link_id'] ?? 0);
            if ($linkId <= 0) {
                mccf_api_json(400, array('ok' => false, 'error' => 'link_id is required.'));
            }
            $fields = array();
            if (isset($input['manual_code'])) {
                $fields['manual_code'] = (string)$input['manual_code'];
            }
            if (isset($input['part'])) {
                $fields['part'] = (string)$input['part'];
            }
            if (isset($input['section_ref'])) {
                $fields['section_ref'] = (string)$input['section_ref'];
            }
            if (isset($input['section_picker_id'])) {
                $fields['section_picker_id'] = (string)$input['section_picker_id'];
            }
            if (isset($input['link_type'])) {
                $fields['link_type'] = (string)$input['link_type'];
            }
            if (array_key_exists('notes', $input)) {
                $fields['notes'] = (string)$input['notes'];
            }
            mccf_api_json(200, $manualLinks->updateLink($linkId, $fields));
            break;

        case 'manual_link_delete':
            $linkId = (int)($input['link_id'] ?? 0);
            if ($linkId <= 0) {
                mccf_api_json(400, array('ok' => false, 'error' => 'link_id is required.'));
            }
            mccf_api_json(200, $manualLinks->deleteLink($linkId));
            break;

        case 'manual_section_ref_update':
            if ($requirementId <= 0) {
                mccf_api_json(400, array('ok' => false, 'error' => 'requirement_id is required.'));
            }
            mccf_api_json(200, $manualLinks->updateManualSectionRef(
                $requirementId,
                (string)($input['manual_section_ref'] ?? '')
            ));
            break;

        default:
            mccf_api_json(400, array('ok' => false, 'error' => 'Unknown action.'));
    }
} catch (Throwable $e) {
    mccf_api_json(500, array('ok' => false, 'error' => $e->getMessage()));
}
