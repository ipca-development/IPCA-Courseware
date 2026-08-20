<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceAccess.php';
require_once __DIR__ . '/../../../src/publishing/ControlledPublishingReaderService.php';
require_once __DIR__ . '/../../../src/publishing/ControlledPublishingReaderLayoutProfile.php';
require_once __DIR__ . '/../../../src/publishing/ControlledPublishingReaderPageMapStore.php';
require_once __DIR__ . '/../../../src/publishing/ControlledPublishingAuthoritativePaginationService.php';
require_once __DIR__ . '/../../../src/publishing/ControlledPublishingLivePageMapService.php';

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

/** @return array<string,mixed> */
function cp_pm_mutation_hint(array $input): array
{
    $impact = strtolower(trim((string)($input['layout_impact'] ?? '')));
    if (!in_array($impact, array('suffix', 'global'), true)) {
        $impact = 'global';
    }
    return array(
        'client_mutation_revision' => max(0, (int)($input['client_mutation_revision'] ?? 0)),
        'section_id' => max(0, (int)($input['section_id'] ?? 0)),
        'block_id' => max(0, (int)($input['block_id'] ?? 0)),
        'stable_anchor' => mb_substr(trim((string)($input['stable_anchor'] ?? '')), 0, 255),
        'mutation_kind' => mb_substr(trim((string)($input['mutation_kind'] ?? '')), 0, 64),
        'layout_impact' => $impact,
    );
}

try {
    $user = compliance_require_access($pdo);
    $uid = (int)($user['id'] ?? 0);

    $reader = new ControlledPublishingReaderService($pdo);
    $store = $reader->pageMapStore();
    $in = cp_pm_input();
    $action = strtolower(trim((string)($in['action'] ?? $_GET['action'] ?? '')));

    switch ($action) {
        case 'live_ensure':
            $versionId = (int)($in['book_version_id'] ?? 0);
            if ($versionId <= 0) {
                throw new RuntimeException('book_version_id required.');
            }
            cp_pm_json(202, array(
                'ok' => true,
                'result' => $reader->ensureLivePageMap($versionId, $uid, cp_pm_mutation_hint($in)),
            ));

        case 'live_status':
            $versionId = (int)($in['book_version_id'] ?? 0);
            if ($versionId <= 0) {
                throw new RuntimeException('book_version_id required.');
            }
            cp_pm_json(200, array(
                'ok' => true,
                'result' => $reader->livePageMapStatus($versionId),
            ));

        case 'live_retry':
            $versionId = (int)($in['book_version_id'] ?? 0);
            if ($versionId <= 0) {
                throw new RuntimeException('book_version_id required.');
            }
            cp_pm_json(202, array(
                'ok' => true,
                'result' => $reader->retryLivePageMap($versionId, $uid, cp_pm_mutation_hint($in)),
            ));

        case 'generate':
            $versionId = (int)($in['book_version_id'] ?? 0);
            $bookKey = strtoupper(trim((string)($in['book_key'] ?? $in['book'] ?? '')));
            $version = null;
            if ($versionId > 0) {
                $version = $reader->resolveVersionById($versionId);
                if ($version === null) {
                    throw new RuntimeException('Manual version not found.');
                }
                $bookKey = strtoupper((string)($version['book_key'] ?? $bookKey));
            } else {
                $bookKey = cp_pm_validate_book_key($bookKey);
            }
            $result = $reader->generateFrozenPageMapDraft($bookKey, $uid, $version);
            cp_pm_json(200, array('ok' => true, 'result' => $result));

        case 'preview':
            $versionId = (int)($in['book_version_id'] ?? 0);
            $bookKey = strtoupper(trim((string)($in['book_key'] ?? $in['book'] ?? '')));
            $version = null;
            if ($versionId > 0) {
                $version = $reader->resolveVersionById($versionId);
                if ($version === null) {
                    throw new RuntimeException('Manual version not found.');
                }
                $bookKey = strtoupper((string)($version['book_key'] ?? $bookKey));
            } else {
                $bookKey = cp_pm_validate_book_key($bookKey);
            }
            $result = $reader->previewFrozenPageMap($bookKey, $version);
            cp_pm_json(200, array('ok' => true, 'result' => $result));

        case 'stored_preview':
            $versionId = (int)($in['book_version_id'] ?? 0);
            $sectionId = (int)($in['section_id'] ?? 0);
            $version = $reader->resolveVersionById($versionId);
            if ($version === null) {
                throw new RuntimeException('Manual version not found.');
            }
            $layoutProfile = ControlledPublishingReaderLayoutProfile::profileKey();
            $includeStyle = !isset($in['include_style']) || (int)$in['include_style'] === 1;
            $checkFreshness = !isset($in['check_freshness']) || (int)$in['check_freshness'] === 1;
            $approval = $store->getApprovalState($versionId, $layoutProfile);
            $pages = $store->loadStoredPages(
                $versionId,
                $layoutProfile,
                $sectionId > 0 ? $sectionId : null
            );
            $paginateSource = ($includeStyle || $checkFreshness)
                ? $reader->loadReaderPaginateSource($version)
                : null;
            $freshness = $checkFreshness
                ? $reader->authoritativePageMapFreshness($version, $paginateSource)
                : array('is_current' => true, 'mismatches' => array());
            $generation = is_array($approval['generation'] ?? null)
                ? $approval['generation']
                : array();
            $frozenPackage = is_array($generation['publication_package'] ?? null)
                ? $generation['publication_package']
                : array();
            $frozenPackageValid = $frozenPackage !== array()
                && (string)($generation['style_hash'] ?? '') !== ''
                && (string)($generation['manifest_hash'] ?? '') !== ''
                && hash_equals(
                    (string)$generation['style_hash'],
                    (string)($frozenPackage['css']['hash'] ?? '')
                )
                && hash_equals(
                    (string)$generation['manifest_hash'],
                    (string)($frozenPackage['manifest_hash'] ?? '')
                );
            $publicationPackage = null;
            if ($includeStyle && is_array($paginateSource)) {
                $publicationPackage = $frozenPackageValid
                    ? $frozenPackage
                    : $reader->readerPublicationPackage($version, $paginateSource);
            }
            $artifactCompatible = $pages === array() || !$includeStyle || (
                is_array($publicationPackage)
                && (string)($generation['style_hash'] ?? '') !== ''
                && (string)($generation['manifest_hash'] ?? '') !== ''
                && hash_equals(
                    (string)$generation['style_hash'],
                    (string)($publicationPackage['css']['hash'] ?? '')
                )
                && hash_equals(
                    (string)$generation['manifest_hash'],
                    (string)($publicationPackage['manifest_hash'] ?? '')
                )
            );
            $responsePages = $artifactCompatible ? $pages : array();
            $approvalResponse = $approval;
            if (is_array($approvalResponse['generation'] ?? null)) {
                unset($approvalResponse['generation']['publication_package']);
            }
            cp_pm_json(200, array(
                'ok' => true,
                'result' => array(
                    'book_version_id' => $versionId,
                    'book_key' => (string)($version['book_key'] ?? ''),
                    'version_label' => (string)($version['version_label'] ?? ''),
                    'lifecycle_status' => (string)($version['lifecycle_status'] ?? ''),
                    'pagination' => $approvalResponse,
                    'freshness' => $freshness,
                    'book_style_css' => is_array($publicationPackage)
                        ? (string)($publicationPackage['css']['content'] ?? '')
                        : null,
                    'book_style_css_hash' => is_array($publicationPackage)
                        ? (string)($publicationPackage['css']['hash'] ?? '')
                        : null,
                    'artifact_compatible' => $artifactCompatible,
                    'compatibility_error' => $artifactCompatible
                        ? null
                        : 'Stored page HTML and its frozen publication style package do not match. Regenerate pages.',
                    'page_count' => $store->pageCount($versionId, $layoutProfile),
                    'returned_page_count' => count($responsePages),
                    'section_id' => $sectionId > 0 ? $sectionId : null,
                    'pages' => array_map(static fn(array $page): array => array(
                        'page_number' => (int)$page['page_number'],
                        'section_id' => isset($page['section_id']) ? (int)$page['section_id'] : null,
                        'stable_anchor' => $page['stable_anchor'],
                        'page_type' => (string)$page['page_type'],
                        'is_cover' => !empty($page['is_cover']),
                        'is_section_start' => !empty($page['is_section_start']),
                        'page_html' => (string)$page['page_html'],
                        'metadata' => $page['metadata'] ?? array(),
                    ), $responsePages),
                ),
            ));

        case 'section_index':
            $versionId = (int)($in['book_version_id'] ?? 0);
            $version = $reader->resolveVersionById($versionId);
            if ($version === null) {
                throw new RuntimeException('Manual version not found.');
            }
            $layoutProfile = ControlledPublishingReaderLayoutProfile::profileKey();
            cp_pm_json(200, array(
                'ok' => true,
                'book_version_id' => $versionId,
                'section_page_index' => $store->sectionPageIndex($versionId, $layoutProfile),
                'page_count' => $store->pageCount($versionId, $layoutProfile),
            ));

        case 'approve':
            $versionId = (int)($in['book_version_id'] ?? 0);
            $bookKey = strtoupper(trim((string)($in['book_key'] ?? $in['book'] ?? '')));
            if ($versionId > 0) {
                $version = $reader->resolveVersionById($versionId);
                if ($version === null) {
                    throw new RuntimeException('Manual version not found.');
                }
            } else {
                $bookKey = cp_pm_validate_book_key($bookKey);
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
            $versionId = (int)($in['book_version_id'] ?? 0);
            $bookKey = strtoupper(trim((string)($in['book_key'] ?? $in['book'] ?? '')));
            if ($versionId > 0) {
                $version = $reader->resolveVersionById($versionId);
                if ($version === null) {
                    throw new RuntimeException('Manual version not found.');
                }
            } else {
                $bookKey = cp_pm_validate_book_key($bookKey);
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
            $versionId = (int)($in['book_version_id'] ?? 0);
            $bookKey = strtoupper(trim((string)($in['book_key'] ?? $in['book'] ?? '')));
            if ($versionId > 0) {
                $version = $reader->resolveVersionById($versionId);
                if ($version === null) {
                    throw new RuntimeException('Manual version not found.');
                }
            } else {
                $bookKey = cp_pm_validate_book_key($bookKey);
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
} catch (ControlledPublishingPaginationValidationException $e) {
    cp_pm_json(409, array('ok' => false, 'error' => $e->payload()));
} catch (RuntimeException $e) {
    cp_pm_json(400, array('ok' => false, 'error' => $e->getMessage()));
} catch (Throwable $e) {
    cp_pm_json(500, array('ok' => false, 'error' => 'Server error'));
}
