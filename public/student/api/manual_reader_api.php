<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/publishing/ControlledPublishingReaderService.php';
require_once __DIR__ . '/../../../src/publishing/ControlledPublishingReaderAccessService.php';

cw_require_login();

header('Content-Type: application/json; charset=utf-8');

function mr_json(int $code, array $payload): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function mr_input(): array
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

function mr_validate_book_key(string $bookKey): string
{
    $bookKey = strtoupper(trim($bookKey));
    if (!in_array($bookKey, array('OM', 'OMM'), true)) {
        throw new RuntimeException('Invalid book key.');
    }

    return $bookKey;
}

/**
 * @param array<string,mixed>|null $user
 */
function mr_can_preview_drafts(?array $user, ControlledPublishingReaderAccessService $access): bool
{
    if (method_exists($access, 'canPreviewDraftManuals')) {
        return $access->canPreviewDraftManuals($user);
    }
    if (!is_array($user)) {
        return false;
    }
    $role = strtolower(trim((string)($user['role'] ?? '')));

    return in_array($role, array('instructor', 'chief_instructor', 'admin'), true);
}

/**
 * @return list<array<string,mixed>>
 */
function mr_list_library_books(
    ControlledPublishingReaderService $reader,
    ?int $userId,
    bool $includeDraftPreview
): array {
    if (method_exists($reader, 'listActiveLibrary')) {
        return $reader->listActiveLibrary($userId, $includeDraftPreview);
    }

    return $reader->listActiveReleasedLibrary($userId);
}

/**
 * @param array<string,mixed>|null $user
 * @return array{version: array<string,mixed>, is_preview: bool, can_preview: bool, book_key: string}
 */
function mr_reader_context(
    ControlledPublishingReaderService $reader,
    ControlledPublishingReaderAccessService $access,
    ?array $user,
    string $bookKey,
    array $input = array()
): array {
    $canPreview = mr_can_preview_drafts($user, $access);
    $versionId = (int)($input['version_id'] ?? $_GET['version_id'] ?? 0);
    if (method_exists($reader, 'resolveReaderVersion')) {
        $version = $reader->resolveReaderVersion(
            $bookKey,
            $versionId > 0 ? $versionId : null,
            $canPreview
        );
    } else {
        $version = $reader->requireReleasedVersion($bookKey);
    }
    $lifecycle = (string)($version['lifecycle_status'] ?? '');

    return array(
        'version' => $version,
        'is_preview' => $lifecycle !== 'released',
        'can_preview' => $canPreview,
        'book_key' => $bookKey,
    );
}

try {
    $user = cw_current_user($pdo);
    $access = new ControlledPublishingReaderAccessService();
    if (!$access->canReadManuals($user)) {
        mr_json(403, array('ok' => false, 'error' => 'Forbidden'));
    }

    $userId = (int)($user['id'] ?? 0);
    if ($userId <= 0) {
        mr_json(401, array('ok' => false, 'error' => 'Login required'));
    }

    $reader = new ControlledPublishingReaderService($pdo);
    $canPreviewDrafts = mr_can_preview_drafts($user, $access);
    $action = strtolower(trim((string)($_GET['action'] ?? '')));

    switch ($action) {
        case 'library':
            mr_json(200, array(
                'ok' => true,
                'can_preview_draft_manuals' => $canPreviewDrafts,
                'books' => mr_list_library_books($reader, $userId, $canPreviewDrafts),
            ));

        case 'nav':
            $bookKey = mr_validate_book_key((string)($_GET['book'] ?? ''));
            $ctx = mr_reader_context($reader, $access, $user, $bookKey);
            $version = $ctx['version'];
            mr_json(200, array(
                'ok' => true,
                'book_key' => $bookKey,
                'version_id' => (int)$version['id'],
                'version_label' => (string)($version['version_label'] ?? ''),
                'book_title' => (string)($version['book_title'] ?? ''),
                'lifecycle_status' => (string)($version['lifecycle_status'] ?? ''),
                'is_preview' => $ctx['is_preview'],
                'nav' => $reader->buildReaderNavTree($bookKey, $version),
                'has_page_map' => $ctx['is_preview']
                    || (method_exists($reader, 'hasApprovedFrozenPageMapForVersion')
                        ? $reader->hasApprovedFrozenPageMapForVersion($version)
                        : $reader->hasApprovedFrozenPageMap($bookKey)),
            ));

        case 'page_map':
            $bookKey = mr_validate_book_key((string)($_GET['book'] ?? ''));
            $ctx = mr_reader_context($reader, $access, $user, $bookKey);
            if (method_exists($reader, 'loadReaderPageMap')) {
                mr_json(200, $reader->loadReaderPageMap($ctx['version'], $ctx['can_preview']));
            }
            mr_json(200, $reader->loadFrozenPageMap($bookKey));

        case 'paginate_source':
            $bookKey = mr_validate_book_key((string)($_GET['book'] ?? ''));
            $ctx = mr_reader_context($reader, $access, $user, $bookKey);
            mr_json(200, array(
                'ok' => true,
                'book_key' => $bookKey,
                'version_id' => (int)$ctx['version']['id'],
                'source' => $reader->loadReaderPaginateSource($ctx['version']),
            ));

        case 'download_pages':
            $bookKey = mr_validate_book_key((string)($_GET['book'] ?? ''));
            $ctx = mr_reader_context($reader, $access, $user, $bookKey);
            if (!method_exists($reader, 'loadReaderPage')) {
                throw new RuntimeException('Offline manual downloads require an updated reader service.');
            }
            $rawPageNumbers = explode(',', (string)($_GET['page_numbers'] ?? ''));
            $pageNumbers = array();
            foreach ($rawPageNumbers as $rawPageNumber) {
                $pageNumber = (int)trim($rawPageNumber);
                if ($pageNumber > 0 && !in_array($pageNumber, $pageNumbers, true)) {
                    $pageNumbers[] = $pageNumber;
                }
            }
            if ($pageNumbers === array()) {
                throw new RuntimeException('page_numbers required.');
            }
            if (count($pageNumbers) > 12) {
                throw new RuntimeException('A maximum of 12 pages may be downloaded per request.');
            }
            $pages = array();
            foreach ($pageNumbers as $pageNumber) {
                $pages[] = $reader->loadReaderPage(
                    $ctx['version'],
                    $pageNumber,
                    $ctx['can_preview']
                );
            }
            mr_json(200, array(
                'ok' => true,
                'book_key' => $bookKey,
                'version_id' => (int)$ctx['version']['id'],
                'pages' => $pages,
            ));

        case 'page':
            $bookKey = mr_validate_book_key((string)($_GET['book'] ?? ''));
            $ctx = mr_reader_context($reader, $access, $user, $bookKey);
            $pageNumber = (int)($_GET['page_number'] ?? $_GET['page'] ?? 0);
            if (method_exists($reader, 'loadReaderPage')) {
                mr_json(200, $reader->loadReaderPage($ctx['version'], $pageNumber, $ctx['can_preview']));
            }
            mr_json(200, $reader->loadFrozenPage($bookKey, $pageNumber));

        case 'toc_with_pages':
            $bookKey = mr_validate_book_key((string)($_GET['book'] ?? ''));
            $ctx = mr_reader_context($reader, $access, $user, $bookKey);
            if (method_exists($reader, 'loadReaderTocWithPages')) {
                mr_json(200, $reader->loadReaderTocWithPages($ctx['version'], $ctx['can_preview']));
            }
            mr_json(200, $reader->loadTocWithPages($bookKey));

        case 'section':
            $bookKey = mr_validate_book_key((string)($_GET['book'] ?? ''));
            $ctx = mr_reader_context($reader, $access, $user, $bookKey);
            $sectionId = (int)($_GET['section_id'] ?? 0);
            $stableAnchor = trim((string)($_GET['stable_anchor'] ?? ''));
            if ($sectionId <= 0 && $stableAnchor === '') {
                throw new RuntimeException('section_id or stable_anchor required.');
            }
            $payload = $reader->loadSection(
                $bookKey,
                $sectionId > 0 ? $sectionId : null,
                $stableAnchor !== '' ? $stableAnchor : null,
                $ctx['version']
            );
            if ($payload === null) {
                mr_json(404, array('ok' => false, 'error' => 'Section not found'));
            }
            mr_json(200, array_merge(array(
                'ok' => true,
                'is_preview' => $ctx['is_preview'],
                'lifecycle_status' => (string)($ctx['version']['lifecycle_status'] ?? ''),
            ), $payload));

        case 'progress_get':
            $bookKey = mr_validate_book_key((string)($_GET['book'] ?? ''));
            $ctx = mr_reader_context($reader, $access, $user, $bookKey);
            if ($ctx['is_preview']) {
                mr_json(200, array(
                    'ok' => true,
                    'book_key' => $bookKey,
                    'version_id' => (int)$ctx['version']['id'],
                    'is_preview' => true,
                    'progress' => null,
                    'default_section_id' => $reader->defaultSectionId($bookKey, $ctx['version']),
                ));
            }
            $version = $ctx['version'];
            $progress = $reader->getReadingProgress($userId, $bookKey);
            mr_json(200, array(
                'ok' => true,
                'book_key' => $bookKey,
                'version_id' => (int)$version['id'],
                'progress' => $progress,
                'default_section_id' => $reader->defaultSectionId($bookKey, $version),
            ));

        case 'progress_save':
            $in = mr_input();
            $bookKey = mr_validate_book_key((string)($in['book_key'] ?? $_GET['book'] ?? ''));
            $ctx = mr_reader_context($reader, $access, $user, $bookKey, $in);
            if ($ctx['is_preview']) {
                mr_json(200, array('ok' => true, 'skipped' => true, 'reason' => 'preview'));
            }
            $sectionId = (int)($in['section_id'] ?? 0);
            if ($sectionId <= 0) {
                throw new RuntimeException('section_id required.');
            }
            $stableAnchor = trim((string)($in['stable_anchor'] ?? ''));
            $scrollPct = (int)($in['scroll_pct'] ?? 0);
            $pageNumber = (int)($in['page_number'] ?? 0);
            if ($pageNumber > 0) {
                $scrollPct = $pageNumber;
            }
            $reader->saveReadingProgress($userId, $bookKey, $sectionId, $stableAnchor, $scrollPct);
            mr_json(200, array('ok' => true));

        case 'search_titles':
            $bookKey = mr_validate_book_key((string)($_GET['book'] ?? ''));
            $ctx = mr_reader_context($reader, $access, $user, $bookKey);
            $query = trim((string)($_GET['q'] ?? ''));
            if ($query === '') {
                mr_json(200, array('ok' => true, 'results' => array()));
            }
            mr_json(200, array(
                'ok' => true,
                'results' => $reader->searchSectionTitles($bookKey, $query, 40, $ctx['version']),
            ));

        default:
            mr_json(400, array('ok' => false, 'error' => 'Unknown action'));
    }
} catch (RuntimeException $e) {
    mr_json(400, array('ok' => false, 'error' => $e->getMessage()));
} catch (Throwable $e) {
    error_log('manual_reader_api: ' . $e->getMessage());
    mr_json(500, array('ok' => false, 'error' => $e->getMessage()));
}
