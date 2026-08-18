<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceAccess.php';
require_once __DIR__ . '/../../../src/publishing/BooksManualsChangeAssistantService.php';
require_once __DIR__ . '/../../../src/publishing/BooksManualsChangeAssistantJobService.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

/** @param array<string,mixed> $payload */
function manual_ai_json(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** @return array<string,mixed> */
function manual_ai_input(): array
{
    $input = array_merge($_GET, $_POST);
    $contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
    if (str_contains($contentType, 'application/json')) {
        $raw = file_get_contents('php://input');
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($decoded)) {
            throw new InvalidArgumentException('Request body must be valid JSON.');
        }
        $input = array_merge($input, $decoded);
    }
    return $input;
}

function manual_ai_csrf_token(): string
{
    if (!isset($_SESSION['books_manuals_ai_csrf'])
        || !is_string($_SESSION['books_manuals_ai_csrf'])
        || strlen($_SESSION['books_manuals_ai_csrf']) < 32) {
        $_SESSION['books_manuals_ai_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['books_manuals_ai_csrf'];
}

/** @param array<string,mixed> $input */
function manual_ai_require_csrf(array $input): void
{
    $provided = (string)($input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if ($provided === '' || !hash_equals(manual_ai_csrf_token(), $provided)) {
        manual_ai_json(403, array('ok' => false, 'error' => 'Invalid CSRF token.'));
    }
}

/**
 * @param array<string,mixed> $file
 * @return array{text:string,metadata:array<string,mixed>,storage_path:string}
 */
function manual_ai_store_upload(array $file, int $projectId): array
{
    $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException('Upload failed with code ' . $error . '.');
    }
    $size = (int)($file['size'] ?? 0);
    if ($size <= 0 || $size > 20 * 1024 * 1024) {
        throw new InvalidArgumentException('Source file must be between 1 byte and 20 MB.');
    }
    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        throw new InvalidArgumentException('Invalid uploaded file.');
    }
    $name = basename((string)($file['name'] ?? 'source'));
    $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = strtolower((string)$finfo->file($tmp));
    $allowed = array(
        'txt' => array('text/plain', 'text/csv', 'application/octet-stream'),
        'pdf' => array('application/pdf'),
        'docx' => array('application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'),
    );
    if (!isset($allowed[$extension]) || !in_array($mime, $allowed[$extension], true)) {
        throw new InvalidArgumentException('Only TXT, PDF, or DOCX files with a matching MIME type are accepted.');
    }

    $storageRoot = dirname(__DIR__, 3) . '/storage/manual_change_assistant';
    if (!is_dir($storageRoot) && !mkdir($storageRoot, 0770, true) && !is_dir($storageRoot)) {
        throw new RuntimeException('Private source storage cannot be created.');
    }
    $projectDir = $storageRoot . '/project_' . $projectId;
    if (!is_dir($projectDir) && !mkdir($projectDir, 0770, true) && !is_dir($projectDir)) {
        throw new RuntimeException('Private project storage cannot be created.');
    }
    $base = bin2hex(random_bytes(16));
    $originalPath = $projectDir . '/' . $base . '.' . $extension;
    if (!move_uploaded_file($tmp, $originalPath)) {
        throw new RuntimeException('Uploaded source could not be moved to private storage.');
    }
    @chmod($originalPath, 0660);

    try {
        $text = match ($extension) {
            'txt' => (string)file_get_contents($originalPath),
            'docx' => manual_ai_extract_docx($originalPath),
            'pdf' => manual_ai_extract_pdf($originalPath),
        };
        $text = trim(str_replace("\0", '', $text));
        if ($text === '') {
            throw new RuntimeException('No extractable text was found in the source.');
        }
        if (strlen($text) > 20 * 1024 * 1024) {
            throw new RuntimeException('Extracted source text exceeds the 20 MB limit.');
        }
        $textPath = $projectDir . '/' . $base . '.extracted.txt';
        if (file_put_contents($textPath, $text, LOCK_EX) === false) {
            throw new RuntimeException('Extracted source text could not be stored.');
        }
        @chmod($textPath, 0660);
    } catch (Throwable $e) {
        @unlink($originalPath);
        throw $e;
    }

    $repositoryRoot = dirname(__DIR__, 3);
    return array(
        'text' => $text,
        'storage_path' => ltrim(substr($textPath, strlen($repositoryRoot)), '/'),
        'metadata' => array(
            'source_type' => $extension,
            'original_name' => $name,
            'mime_type' => $mime,
            'original_private_path' => ltrim(substr($originalPath, strlen($repositoryRoot)), '/'),
        ),
    );
}

function manual_ai_extract_docx(string $path): string
{
    if (!class_exists(ZipArchive::class)) {
        throw new RuntimeException('DOCX extraction is unavailable because ZipArchive is not installed.');
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('DOCX archive cannot be opened.');
    }
    try {
        $xml = $zip->getFromName('word/document.xml');
    } finally {
        $zip->close();
    }
    if (!is_string($xml) || $xml === '') {
        throw new RuntimeException('DOCX document.xml is missing.');
    }
    $xml = preg_replace('/<\/w:(p|tr)>/i', "\n", $xml) ?? $xml;
    $xml = preg_replace('/<w:tab\b[^>]*\/>/i', "\t", $xml) ?? $xml;
    return html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
}

function manual_ai_extract_pdf(string $path): string
{
    $binary = trim((string)shell_exec('command -v pdftotext 2>/dev/null'));
    if ($binary === '') {
        throw new RuntimeException('PDF text extraction is unavailable on this server; upload TXT instead.');
    }
    $outputPath = tempnam(sys_get_temp_dir(), 'manual_ai_pdf_');
    if ($outputPath === false) {
        throw new RuntimeException('PDF extraction temporary file cannot be created.');
    }
    try {
        $command = escapeshellarg($binary) . ' -enc UTF-8 -nopgbrk '
            . escapeshellarg($path) . ' ' . escapeshellarg($outputPath) . ' 2>&1';
        exec($command, $output, $status);
        if ($status !== 0) {
            throw new RuntimeException('PDF text extraction failed cleanly.');
        }
        $text = file_get_contents($outputPath);
        if (!is_string($text)) {
            throw new RuntimeException('Extracted PDF text cannot be read.');
        }
        return $text;
    } finally {
        @unlink($outputPath);
    }
}

$user = compliance_require_access($pdo);
$uid = (int)($user['id'] ?? 0);
$assistant = new BooksManualsChangeAssistantService($pdo);
$jobs = new BooksManualsChangeAssistantJobService($pdo);

try {
    $input = manual_ai_input();
    $action = trim((string)($input['action'] ?? 'project'));
    $mutations = array(
        'create_project',
        'upload_source',
        'set_scope',
        'create_revision',
        'start_analysis',
        'start_compose',
        'decision',
        'assign_reviewer',
        'apply',
    );
    if (in_array($action, $mutations, true)) {
        manual_ai_require_csrf($input);
    }

    switch ($action) {
        case 'create_project':
            manual_ai_json(201, array(
                'ok' => true,
                'project' => $assistant->createProject(
                    (string)($input['name'] ?? ''),
                    isset($input['description']) ? (string)$input['description'] : null,
                    $uid
                ),
                'csrf_token' => manual_ai_csrf_token(),
            ));

        case 'upload_source':
            $projectId = (int)($input['project_id'] ?? 0);
            if ($projectId <= 0) {
                throw new InvalidArgumentException('project_id is required.');
            }
            $assistant->assertProjectOwner($projectId, $uid);
            $metadata = array(
                'title' => (string)($input['title'] ?? ''),
                'issuer' => (string)($input['issuer'] ?? ''),
                'reference_code' => (string)($input['reference_code'] ?? ''),
                'effective_date' => ($input['effective_date'] ?? '') !== '' ? (string)$input['effective_date'] : null,
            );
            $storagePath = null;
            if (isset($_FILES['source']) && is_array($_FILES['source'])) {
                $upload = manual_ai_store_upload($_FILES['source'], $projectId);
                $text = $upload['text'];
                $storagePath = $upload['storage_path'];
                $metadata = array_merge($metadata, $upload['metadata']);
                if (trim((string)$metadata['title']) === '') {
                    $metadata['title'] = (string)$metadata['original_name'];
                }
            } else {
                $text = (string)($input['source_text'] ?? '');
                $metadata['source_type'] = 'text';
                $metadata['mime_type'] = 'text/plain';
                if (trim((string)$metadata['title']) === '') {
                    $metadata['title'] = 'Pasted source';
                }
            }
            manual_ai_json(201, array(
                'ok' => true,
                'source' => $assistant->addSourceText($projectId, $text, $metadata, $uid, $storagePath),
            ));

        case 'set_scope':
            $assistant->assertProjectOwner((int)($input['project_id'] ?? 0), $uid);
            $versionIds = $input['version_ids'] ?? array();
            if (is_string($versionIds)) {
                $versionIds = preg_split('/[\s,]+/', $versionIds) ?: array();
            }
            if (!is_array($versionIds)) {
                throw new InvalidArgumentException('version_ids must be an array.');
            }
            $result = $assistant->replaceVersionScopes((int)($input['project_id'] ?? 0), $versionIds, $uid);
            manual_ai_json(($result['requires_revision'] ?? false) ? 409 : 200, $result);

        case 'start_analysis':
            $assistant->assertProjectOwner((int)($input['project_id'] ?? 0), $uid);
            manual_ai_json(202, array(
                'ok' => true,
                'job' => $jobs->enqueueAnalysis((int)($input['project_id'] ?? 0), $uid),
            ));

        case 'start_compose':
            $projectId = (int)($input['project_id'] ?? 0);
            $assistant->assertProjectOwner($projectId, $uid);
            $impactAreaIds = $input['impact_area_ids'] ?? array();
            if (is_string($impactAreaIds)) {
                $impactAreaIds = preg_split('/[\s,]+/', $impactAreaIds) ?: array();
            }
            if (!is_array($impactAreaIds)) {
                throw new InvalidArgumentException('impact_area_ids must be an array.');
            }
            manual_ai_json(202, array(
                'ok' => true,
                'job' => $jobs->enqueueComposition($projectId, $impactAreaIds, $uid),
            ));

        case 'create_revision':
            $assistant->assertProjectOwner((int)($input['project_id'] ?? 0), $uid);
            manual_ai_json(201, $assistant->createDraftRevisionForScope(
                (int)($input['project_id'] ?? 0),
                (int)($input['version_id'] ?? 0),
                $uid
            ));

        case 'project':
            $projectId = (int)($input['project_id'] ?? 0);
            if ($projectId > 0) {
                $assistant->assertProjectAccess($projectId, $uid);
            }
            manual_ai_json(200, array(
                'ok' => true,
                'enabled' => BooksManualsChangeAssistantService::enabled(),
                'tables_present' => $assistant->tablesPresent(),
                'csrf_token' => manual_ai_csrf_token(),
                'project' => $projectId > 0 ? $assistant->getProject($projectId) : null,
                'projects' => $projectId > 0 ? null : $assistant->listProjects(100, $uid),
                'job' => $projectId > 0 ? $jobs->status($projectId) : null,
            ));

        case 'decision':
            if ((string)($input['target_type'] ?? 'finding') === 'finding') {
                $assistant->assertFindingDecisionAccess(
                    (int)($input['project_id'] ?? 0),
                    (int)($input['target_id'] ?? 0),
                    $uid
                );
            } else {
                $assistant->assertProjectOwner((int)($input['project_id'] ?? 0), $uid);
            }
            $assistant->saveDecision(
                (int)($input['project_id'] ?? 0),
                (string)($input['target_type'] ?? 'finding'),
                (int)($input['target_id'] ?? 0),
                (string)($input['decision'] ?? ''),
                isset($input['note']) ? (string)$input['note'] : null,
                $uid,
                isset($input['proposed_text']) ? (string)$input['proposed_text'] : null
            );
            manual_ai_json(200, array('ok' => true));

        case 'assign_reviewer':
            $projectId = (int)($input['project_id'] ?? 0);
            $assistant->assertProjectOwner($projectId, $uid);
            $reviewerId = (int)($input['reviewer_user_id'] ?? 0);
            $assistant->assignReviewer(
                $projectId,
                (int)($input['finding_id'] ?? 0),
                $reviewerId > 0 ? $reviewerId : null,
                $uid
            );
            manual_ai_json(200, array('ok' => true));

        case 'export_manifest':
            $assistant->assertProjectOwner((int)($input['project_id'] ?? 0), $uid);
            manual_ai_json(200, array(
                'ok' => true,
                'manifest' => $assistant->exportImpactManifest((int)($input['project_id'] ?? 0)),
            ));

        case 'export_report':
            $assistant->assertProjectAccess((int)($input['project_id'] ?? 0), $uid);
            manual_ai_json(200, array(
                'ok' => true,
                'report' => $assistant->exportAuthorityImpactReport((int)($input['project_id'] ?? 0)),
            ));

        case 'apply':
            $assistant->assertProjectOwner((int)($input['project_id'] ?? 0), $uid);
            $findingIds = $input['finding_ids'] ?? array();
            if (is_string($findingIds)) {
                $findingIds = preg_split('/[\s,]+/', $findingIds) ?: array();
            }
            if (!is_array($findingIds)) {
                throw new InvalidArgumentException('finding_ids must be an array.');
            }
            $result = $assistant->applyApproved(
                (int)($input['project_id'] ?? 0),
                $findingIds,
                $uid,
                (string)($input['rationale'] ?? '')
            );
            manual_ai_json(($result['requires_revision'] ?? false) ? 409 : 200, $result);

        default:
            throw new InvalidArgumentException('Unknown action.');
    }
} catch (InvalidArgumentException $e) {
    manual_ai_json(400, array('ok' => false, 'error' => $e->getMessage()));
} catch (RuntimeException $e) {
    manual_ai_json(409, array('ok' => false, 'error' => $e->getMessage()));
} catch (Throwable $e) {
    error_log('Manual Change Assistant API: ' . $e->getMessage());
    manual_ai_json(500, array('ok' => false, 'error' => 'Internal server error.'));
}
