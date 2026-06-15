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
    $action = strtolower(trim((string)($_GET['action'] ?? '')));

    switch ($action) {
        case 'library':
            mr_json(200, array(
                'ok' => true,
                'books' => $reader->listActiveReleasedLibrary(),
            ));

        case 'nav':
            $bookKey = mr_validate_book_key((string)($_GET['book'] ?? ''));
            $version = $reader->resolveLatestReleasedVersion($bookKey);
            if ($version === null) {
                mr_json(404, array('ok' => false, 'error' => 'No released manual available'));
            }
            mr_json(200, array(
                'ok' => true,
                'book_key' => $bookKey,
                'version_id' => (int)$version['id'],
                'version_label' => (string)($version['version_label'] ?? ''),
                'book_title' => (string)($version['book_title'] ?? ''),
                'nav' => $reader->buildReaderNavTree($bookKey),
            ));

        case 'section':
            $bookKey = mr_validate_book_key((string)($_GET['book'] ?? ''));
            $sectionId = (int)($_GET['section_id'] ?? 0);
            $stableAnchor = trim((string)($_GET['stable_anchor'] ?? ''));
            if ($sectionId <= 0 && $stableAnchor === '') {
                throw new RuntimeException('section_id or stable_anchor required.');
            }
            $payload = $reader->loadSection(
                $bookKey,
                $sectionId > 0 ? $sectionId : null,
                $stableAnchor !== '' ? $stableAnchor : null
            );
            if ($payload === null) {
                mr_json(404, array('ok' => false, 'error' => 'Section not found'));
            }
            mr_json(200, array_merge(array('ok' => true), $payload));

        case 'progress_get':
            $bookKey = mr_validate_book_key((string)($_GET['book'] ?? ''));
            $version = $reader->resolveLatestReleasedVersion($bookKey);
            if ($version === null) {
                mr_json(404, array('ok' => false, 'error' => 'No released manual available'));
            }
            $progress = $reader->getReadingProgress($userId, $bookKey);
            mr_json(200, array(
                'ok' => true,
                'book_key' => $bookKey,
                'version_id' => (int)$version['id'],
                'progress' => $progress,
                'default_section_id' => $reader->defaultSectionId($bookKey),
            ));

        case 'progress_save':
            $in = mr_input();
            $bookKey = mr_validate_book_key((string)($in['book_key'] ?? $_GET['book'] ?? ''));
            $sectionId = (int)($in['section_id'] ?? 0);
            if ($sectionId <= 0) {
                throw new RuntimeException('section_id required.');
            }
            $stableAnchor = trim((string)($in['stable_anchor'] ?? ''));
            $scrollPct = (int)($in['scroll_pct'] ?? 0);
            $reader->saveReadingProgress($userId, $bookKey, $sectionId, $stableAnchor, $scrollPct);
            mr_json(200, array('ok' => true));

        case 'search_titles':
            $bookKey = mr_validate_book_key((string)($_GET['book'] ?? ''));
            $query = trim((string)($_GET['q'] ?? ''));
            if ($query === '') {
                mr_json(200, array('ok' => true, 'results' => array()));
            }
            mr_json(200, array(
                'ok' => true,
                'results' => $reader->searchSectionTitles($bookKey, $query),
            ));

        default:
            mr_json(400, array('ok' => false, 'error' => 'Unknown action'));
    }
} catch (RuntimeException $e) {
    mr_json(400, array('ok' => false, 'error' => $e->getMessage()));
} catch (Throwable $e) {
    mr_json(500, array('ok' => false, 'error' => 'Server error'));
}
