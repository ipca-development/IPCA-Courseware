<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceAccess.php';
require_once __DIR__ . '/../../../src/publishing/ControlledPublishingReaderService.php';
require_once __DIR__ . '/../../../src/publishing/ControlledPublishingReaderLayoutProfile.php';
require_once __DIR__ . '/../../../src/publishing/ControlledPublishingReaderPageMapStore.php';

header('Content-Type: application/json; charset=utf-8');

function cp_pm_json(int $code, array $payload): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function cp_pm_input(): array
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

function cp_pm_validate_book_key(string $bookKey): string
{
    $bookKey = strtoupper(trim($bookKey));
    if (!in_array($bookKey, array('OM', 'OMM'), true)) {
        throw new RuntimeException('Invalid book key.');
    }

    return $bookKey;
}

try {
    $user = compliance_require_access($pdo);
    $uid = (int)($user['id'] ?? 0);

    $reader = new ControlledPublishingReaderService($pdo);
    $store = $reader->pageMapStore();
    $in = cp_pm_input();
    $action = strtolower(trim((string)($in['action'] ?? $_GET['action'] ?? '')));

    switch ($action) {
        case 'generate':
            $bookKey = cp_pm_validate_book_key((string)($in['book_key'] ?? $in['book'] ?? ''));
            $versionId = (int)($in['book_version_id'] ?? 0);
            if ($versionId > 0) {
                $version = $reader->requireReleasedVersionById($versionId);
                $bookKey = strtoupper((string)($version['book_key'] ?? $bookKey));
            }
            $result = $reader->generateFrozenPageMapDraft($bookKey, $uid);
            cp_pm_json(200, array('ok' => true, 'result' => $result));

        case 'preview':
            $bookKey = cp_pm_validate_book_key((string)($in['book_key'] ?? $in['book'] ?? ''));
            $versionId = (int)($in['book_version_id'] ?? 0);
            if ($versionId > 0) {
                $version = $reader->requireReleasedVersionById($versionId);
                $bookKey = strtoupper((string)($version['book_key'] ?? $bookKey));
            }
            $result = $reader->previewFrozenPageMap($bookKey);
            cp_pm_json(200, array('ok' => true, 'result' => $result));

        case 'approve':
            $bookKey = cp_pm_validate_book_key((string)($in['book_key'] ?? $in['book'] ?? ''));
            $versionId = (int)($in['book_version_id'] ?? 0);
            if ($versionId > 0) {
                $version = $reader->requireReleasedVersionById($versionId);
            } else {
                $version = $reader->requireReleasedVersion($bookKey);
            }
            $versionId = (int)$version['id'];
            $profile = ControlledPublishingReaderLayoutProfile::profileKey();
            $approval = $store->approve($versionId, $uid, $profile);
            cp_pm_json(200, array(
                'ok' => true,
                'book_key' => strtoupper((string)($version['book_key'] ?? $bookKey)),
                'version_id' => $versionId,
                'approval' => $approval,
            ));

        case 'invalidate':
            $bookKey = cp_pm_validate_book_key((string)($in['book_key'] ?? $in['book'] ?? ''));
            $versionId = (int)($in['book_version_id'] ?? 0);
            if ($versionId > 0) {
                $version = $reader->requireReleasedVersionById($versionId);
            } else {
                $version = $reader->requireReleasedVersion($bookKey);
            }
            $versionId = (int)$version['id'];
            $store->invalidate($versionId, ControlledPublishingReaderLayoutProfile::profileKey());
            cp_pm_json(200, array(
                'ok' => true,
                'book_key' => strtoupper((string)($version['book_key'] ?? $bookKey)),
                'version_id' => $versionId,
                'status' => 'invalidated',
            ));

        case 'status':
            $bookKey = cp_pm_validate_book_key((string)($in['book_key'] ?? $in['book'] ?? ''));
            $versionId = (int)($in['book_version_id'] ?? 0);
            if ($versionId > 0) {
                $version = $reader->requireReleasedVersionById($versionId);
            } else {
                $version = $reader->requireReleasedVersion($bookKey);
            }
            $versionId = (int)$version['id'];
            $profile = ControlledPublishingReaderLayoutProfile::profileKey();
            cp_pm_json(200, array(
                'ok' => true,
                'book_key' => strtoupper((string)($version['book_key'] ?? $bookKey)),
                'version_id' => $versionId,
                'layout_profile' => $profile,
                'layout_hash' => ControlledPublishingReaderLayoutProfile::layoutHash(),
                'approval' => $store->approvalMeta($versionId),
                'page_count' => $store->pageCount($versionId, $profile),
                'is_approved' => $store->isApproved($versionId, $profile),
            ));

        default:
            cp_pm_json(400, array('ok' => false, 'error' => 'Unknown action'));
    }
} catch (RuntimeException $e) {
    cp_pm_json(400, array('ok' => false, 'error' => $e->getMessage()));
} catch (Throwable $e) {
    cp_pm_json(500, array('ok' => false, 'error' => 'Server error'));
}
