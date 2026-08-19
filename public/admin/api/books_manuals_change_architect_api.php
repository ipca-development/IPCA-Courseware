<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceAccess.php';
require_once __DIR__ . '/../../../src/publishing/BooksManualsChangePlanService.php';
require_once __DIR__ . '/../../../src/publishing/BooksManualsChangeArchitectService.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

/** @param array<string,mixed> $payload */
function architect_api_json(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** @return array<string,mixed> */
function architect_api_input(): array
{
    $input = array_merge($_GET, $_POST);
    if (str_contains(strtolower((string)($_SERVER['CONTENT_TYPE'] ?? '')), 'application/json')) {
        $decoded = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($decoded)) {
            throw new InvalidArgumentException('Request body must be valid JSON.');
        }
        $input = array_merge($input, $decoded);
    }
    return $input;
}

function architect_api_csrf(): string
{
    if (!isset($_SESSION['books_manuals_ai_csrf'])
        || !is_string($_SESSION['books_manuals_ai_csrf'])
        || strlen($_SESSION['books_manuals_ai_csrf']) < 32) {
        $_SESSION['books_manuals_ai_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['books_manuals_ai_csrf'];
}

/** @param array<string,mixed> $input */
function architect_api_require_csrf(array $input): void
{
    $provided = (string)($input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if ($provided === '' || !hash_equals(architect_api_csrf(), $provided)) {
        architect_api_json(403, array('ok' => false, 'error' => 'Invalid CSRF token.'));
    }
}

function architect_api_extract_upload(string $path, string $name): string
{
    $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!in_array($extension, array('txt', 'pdf', 'docx'), true)) {
        throw new InvalidArgumentException('Supporting evidence must be TXT, PDF or DOCX.');
    }
    if ($extension === 'txt') {
        return trim((string)file_get_contents($path));
    }
    if ($extension === 'pdf') {
        $binary = trim((string)shell_exec('command -v pdftotext 2>/dev/null'));
        if ($binary === '') {
            throw new RuntimeException('PDF text extraction is unavailable.');
        }
        $output = tempnam(sys_get_temp_dir(), 'mcw_pdf_');
        if ($output === false) {
            throw new RuntimeException('PDF evidence could not be prepared.');
        }
        try {
            exec(
                escapeshellarg($binary) . ' -enc UTF-8 -nopgbrk '
                . escapeshellarg($path) . ' ' . escapeshellarg($output) . ' 2>&1',
                $messages,
                $status
            );
            if ($status !== 0) {
                throw new RuntimeException('PDF evidence could not be read.');
            }
            return trim((string)file_get_contents($output));
        } finally {
            @unlink($output);
        }
    }
    if (!class_exists(ZipArchive::class)) {
        throw new RuntimeException('DOCX text extraction is unavailable.');
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('DOCX evidence could not be opened.');
    }
    try {
        $xml = $zip->getFromName('word/document.xml');
    } finally {
        $zip->close();
    }
    if (!is_string($xml) || $xml === '') {
        throw new RuntimeException('DOCX evidence contains no readable document.');
    }
    $xml = preg_replace('/<\/w:(p|tr)>/i', "\n", $xml) ?? $xml;
    return trim(html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8'));
}

/** @return list<array{title:string,text:string,reference_code:string}> */
function architect_api_uploaded_evidence(): array
{
    $files = $_FILES['evidence_files'] ?? null;
    if (!is_array($files) || !is_array($files['name'] ?? null)) {
        return array();
    }
    $evidence = array();
    $totalSize = 0;
    foreach ($files['name'] as $index => $name) {
        $error = (int)($files['error'][$index] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        if ($error !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException('A supporting document upload failed.');
        }
        $size = (int)($files['size'][$index] ?? 0);
        $totalSize += $size;
        if ($size <= 0 || $size > 20 * 1024 * 1024 || $totalSize > 30 * 1024 * 1024) {
            throw new InvalidArgumentException('Supporting evidence exceeds the upload limit.');
        }
        $tmp = (string)($files['tmp_name'][$index] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new InvalidArgumentException('A supporting document upload is invalid.');
        }
        $text = architect_api_extract_upload($tmp, basename((string)$name));
        if ($text === '') {
            throw new InvalidArgumentException('A supporting document contains no readable text.');
        }
        $evidence[] = array(
            'title' => basename((string)$name),
            'text' => $text,
            'reference_code' => 'UPLOADED-EVIDENCE-' . ($index + 1),
        );
    }
    return $evidence;
}

$user = compliance_require_access($pdo);
$userId = (int)($user['id'] ?? 0);
$plans = new BooksManualsChangePlanService($pdo);
$architect = new BooksManualsChangeArchitectService($pdo, $plans);

try {
    if (!$plans->tablesPresent()) {
        throw new RuntimeException('The Manual Change Architect tables are unavailable.');
    }
    $input = architect_api_input();
    $action = trim((string)($input['action'] ?? 'plan'));
    if ($action !== 'plan') {
        architect_api_require_csrf($input);
    }
    switch ($action) {
        case 'plan':
            $planId = (int)($input['plan_id'] ?? 0);
            architect_api_json(200, array(
                'ok' => true,
                'plan' => $architect->getCompleteCheckpointReport($planId),
                'csrf_token' => architect_api_csrf(),
            ));

        case 'analyze_change':
            $request = trim((string)($input['change_request'] ?? ''));
            $versionId = (int)($input['primary_version_id'] ?? 0);
            if (mb_strlen($request) < 20 || $versionId <= 0) {
                throw new InvalidArgumentException('A detailed change request and primary manual are required.');
            }
            $evidence = array(array(
                'title' => 'Change request',
                'reference_code' => 'CHANGE-REQUEST',
                'text' => $request,
            ));
            $textEvidence = trim((string)($input['evidence_text'] ?? ''));
            if ($textEvidence !== '') {
                $evidence[] = array(
                    'title' => 'Supporting text evidence',
                    'reference_code' => 'SUPPORTING-TEXT',
                    'text' => $textEvidence,
                );
            }
            $evidence = array_merge($evidence, architect_api_uploaded_evidence());
            $title = mb_strimwidth(preg_replace('/\s+/u', ' ', $request) ?? $request, 0, 110, '…');
            $result = $architect->createAndRunCheckpoint(
                $versionId,
                $evidence,
                array(
                    'title' => $title,
                    'objective' => $request,
                    'change_request' => $request,
                    'use_openai' => true,
                ),
                $userId
            );
            architect_api_json(201, array(
                'ok' => true,
                'plan_id' => (int)($result['id'] ?? 0),
                'csrf_token' => architect_api_csrf(),
            ));

        case 'impact_decision':
            architect_api_json(200, array(
                'ok' => true,
                'result' => $plans->recordImpactDecision(
                    (int)($input['plan_id'] ?? 0),
                    (int)($input['impact_id'] ?? 0),
                    (string)($input['decision'] ?? ''),
                    (string)($input['note'] ?? ''),
                    $userId
                ),
                'csrf_token' => architect_api_csrf(),
            ));

        case 'accept_impact_analysis':
            architect_api_json(200, array(
                'ok' => true,
                'result' => $plans->acceptImpactAnalysis(
                    (int)($input['plan_id'] ?? 0),
                    $userId
                ),
                'csrf_token' => architect_api_csrf(),
            ));

        case 'accept_structure':
            architect_api_json(200, array(
                'ok' => true,
                'result' => $plans->acceptStructure(
                    (int)($input['plan_id'] ?? 0),
                    $userId
                ),
                'csrf_token' => architect_api_csrf(),
            ));

        case 'draft_decision':
            architect_api_json(200, array(
                'ok' => true,
                'result' => $plans->recordDraftDecision(
                    (int)($input['plan_id'] ?? 0),
                    (string)($input['section_number'] ?? ''),
                    (string)($input['decision'] ?? ''),
                    $userId
                ),
                'csrf_token' => architect_api_csrf(),
            ));

        case 'accept_drafts':
            architect_api_json(200, array(
                'ok' => true,
                'result' => $plans->acceptDrafts(
                    (int)($input['plan_id'] ?? 0),
                    $userId
                ),
                'csrf_token' => architect_api_csrf(),
            ));

        case 'run_independent_review':
            architect_api_json(200, array(
                'ok' => true,
                'result' => $plans->runIndependentReview(
                    (int)($input['plan_id'] ?? 0),
                    $userId
                ),
                'csrf_token' => architect_api_csrf(),
            ));

        case 'continue_to_apply':
            architect_api_json(200, array(
                'ok' => true,
                'result' => $plans->continueToApply(
                    (int)($input['plan_id'] ?? 0),
                    $userId
                ),
                'csrf_token' => architect_api_csrf(),
            ));

        case 'apply_working_revision':
            $planId = (int)($input['plan_id'] ?? 0);
            $plan = $plans->getPlan($planId);
            if ((int)($plan['owner_id'] ?? 0) !== $userId) {
                throw new RuntimeException('Only the Change Plan owner can create the working revision.');
            }
            $stmt = $pdo->prepare(
                "SELECT result_json FROM ipca_manual_ai_architect_operations
                 WHERE plan_id=? AND status='succeeded' ORDER BY id DESC LIMIT 1"
            );
            $stmt->execute(array($planId));
            $result = json_decode((string)$stmt->fetchColumn(), true);
            if (!is_array($result) || (int)($result['working_revision_id'] ?? 0) <= 0) {
                throw new RuntimeException(
                    'The independently reviewed canonical operation package is not ready for controlled application.'
                );
            }
            architect_api_json(200, array(
                'ok' => true,
                'redirect' => '/admin/compliance/controlled_book_editor.php?version_id='
                    . (int)$result['working_revision_id'],
                'csrf_token' => architect_api_csrf(),
            ));

        default:
            throw new InvalidArgumentException('Unknown Manual Change Architect action.');
    }
} catch (InvalidArgumentException $e) {
    architect_api_json(400, array('ok' => false, 'error' => $e->getMessage()));
} catch (RuntimeException $e) {
    architect_api_json(409, array('ok' => false, 'error' => $e->getMessage()));
} catch (Throwable $e) {
    error_log('Manual Change Architect API: ' . $e->getMessage());
    architect_api_json(500, array('ok' => false, 'error' => 'Internal server error.'));
}
