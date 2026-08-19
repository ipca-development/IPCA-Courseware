<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceAccess.php';
require_once __DIR__ . '/../../../src/publishing/ControlledPublishingManualPageBreakService.php';
require_once __DIR__ . '/../../../src/publishing/ControlledPublishingReaderService.php';
require_once __DIR__ . '/../../../src/publishing/BooksManualsAnnexBookService.php';

header('Content-Type: application/json; charset=utf-8');

function cp_pb_json(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function cp_pb_input(): array
{
    $raw = file_get_contents('php://input');
    $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
    return is_array($decoded) ? $decoded : array_merge($_GET, $_POST);
}

function cp_pb_refresh_published_annex(
    ControlledPublishingReaderService $reader,
    int $versionId,
    int $uid,
    string $mutationKind
): void {
    $version = $reader->resolveVersionById($versionId);
    if (!is_array($version)
        || (string)($version['lifecycle_status'] ?? '') !== 'released'
        || !BooksManualsAnnexBookService::allowsReleasedEdits($version)) {
        return;
    }
    try {
        $reader->ensureLivePageMap(
            $versionId,
            $uid,
            array(
                'mutation_kind' => $mutationKind,
                'layout_impact' => 'suffix',
            )
        );
    } catch (Throwable $e) {
        error_log('Published Annex page-break refresh could not be queued: ' . $e->getMessage());
    }
}

try {
    $user = compliance_require_access($pdo);
    $uid = (int)($user['id'] ?? 0);
    $input = cp_pb_input();
    $versionId = (int)($input['book_version_id'] ?? 0);
    $sectionId = (int)($input['section_id'] ?? 0);
    if ($versionId <= 0) {
        throw new RuntimeException('Manual version is required.');
    }
    $action = strtolower(trim((string)($input['action'] ?? 'list')));
    $service = new ControlledPublishingManualPageBreakService($pdo);
    $reader = new ControlledPublishingReaderService($pdo);

    switch ($action) {
        case 'list':
            cp_pb_json(200, array(
                'ok' => true,
                'breaks' => $service->listForVersion($versionId),
                'candidates' => $service->listBlockCandidates(
                    $versionId,
                    $sectionId > 0 ? $sectionId : null
                ),
                'identity' => $service->identity($versionId),
            ));

        case 'insert':
            $row = $service->insertBefore(
                $versionId,
                (string)($input['before_block_anchor'] ?? ''),
                $uid
            );
            cp_pb_refresh_published_annex($reader, $versionId, $uid, 'manual_page_break_insert');
            cp_pb_json(200, array('ok' => true, 'break' => $row));

        case 'remove':
            $service->remove($versionId, (int)($input['break_id'] ?? 0));
            cp_pb_refresh_published_annex($reader, $versionId, $uid, 'manual_page_break_remove');
            cp_pb_json(200, array('ok' => true));

        case 'move':
            $row = $service->move(
                $versionId,
                (int)($input['break_id'] ?? 0),
                (string)($input['before_block_anchor'] ?? ''),
                $uid
            );
            cp_pb_refresh_published_annex($reader, $versionId, $uid, 'manual_page_break_move');
            cp_pb_json(200, array('ok' => true, 'break' => $row));

        default:
            cp_pb_json(400, array('ok' => false, 'error' => 'Unsupported action.'));
    }
} catch (Throwable $e) {
    cp_pb_json(400, array('ok' => false, 'error' => $e->getMessage()));
}
