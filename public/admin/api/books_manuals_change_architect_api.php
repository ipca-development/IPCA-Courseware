<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceAccess.php';
require_once __DIR__ . '/../../../src/publishing/BooksManualsChangePlanService.php';
require_once __DIR__ . '/../../../src/publishing/BooksManualsChangeArchitectService.php';
require_once __DIR__ . '/../../../src/publishing/BooksManualsChangeStructureService.php';
require_once __DIR__ . '/../../../src/publishing/BooksManualsChangeAuthorService.php';
require_once __DIR__ . '/../../../src/publishing/BooksManualsChangeReviewerService.php';
require_once __DIR__ . '/../../../src/publishing/BooksManualsChangeApplyService.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

/** @param array<string,mixed> $payload */
function architect_api_json(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** @param array<string,mixed> $payload */
function architect_api_finish_response(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
        return;
    }
    @ob_flush();
    flush();
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

function architect_api_uuid(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex($bytes);
    return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
        . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
}

function architect_api_prepare_independent_review(
    PDO $pdo,
    BooksManualsChangePlanService $plans,
    BooksManualsChangeReviewerService $reviewer,
    BooksManualsChangeApplyService $apply,
    int $planId,
    int $actorUserId
): int {
    $stmt = $pdo->prepare(
        "SELECT * FROM ipca_manual_ai_architect_drafts
         WHERE plan_id=? AND status='generated' ORDER BY id DESC LIMIT 1"
    );
    $stmt->execute(array($planId));
    $draft = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($draft)) {
        throw new RuntimeException('Accepted amendment wording is not available for independent review.');
    }
    $proposal = json_decode((string)($draft['draft_payload_json'] ?? ''), true);
    if (!is_array($proposal) || (string)($proposal['wizard_status'] ?? '') !== 'accepted') {
        throw new RuntimeException('The amendment wording must be accepted before independent review.');
    }
    $wording = $reviewer->verifyReadableAmendmentProposal($proposal);
    $governance = $reviewer->evaluateGovernanceGate($planId);
    $operationPackage = $apply->buildPreflightPackage($planId);
    $issues = array_merge(
        array_values((array)($wording['issues'] ?? array())),
        array_values((array)($governance['blockers'] ?? array()))
    );
    $prepared = array_replace($wording, array(
        'status' => $issues === array() ? 'READY' : 'REQUIRES_REVIEW',
        'issues' => $issues,
        'governance_gate' => $governance,
        'operation_package' => $operationPackage,
        'checks' => array(
            'Accepted wording passes independent consistency review' => $issues === array(),
            'Requested change remains within the approved impact scope' =>
                (string)($governance['status'] ?? '') === 'READY',
            'No unsupported implementation claims were introduced' =>
                (array)($wording['unsupported_capability_claims'] ?? array()) === array(),
            'Preservation and legacy-reference decisions remain accounted for' =>
                (array)($wording['issues'] ?? array()) === array(),
            'In-place operation package is source-fingerprinted and creates no revision' =>
                !empty($operationPackage['package_fingerprint'])
                && empty($operationPackage['revision_creation_allowed'])
                && empty($operationPackage['revision_number_change_allowed'])
                && empty($operationPackage['lifecycle_transition_allowed']),
        ),
    ));
    return $plans->save('reviews', $planId, array(
        'review_uuid' => architect_api_uuid(),
        'draft_id' => (int)$draft['id'],
        'review_type' => 'independent_consistency',
        'status' => 'requested',
        'review_payload_json' => json_encode(array(
            'status' => 'REVIEW_PENDING',
            'summary' => $issues === array()
                ? 'The accepted wording passed independent preparation and is ready for human approval.'
                : 'Independent review identified issues requiring correction.',
            'prepared_result' => $prepared,
        ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        'requested_by' => $actorUserId,
        'requested_at' => gmdate('Y-m-d H:i:s.v'),
    ));
}

/** @param array<string,mixed> $input */
function architect_api_require_csrf(array $input): void
{
    $provided = (string)($input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if ($provided === '' || !hash_equals(architect_api_csrf(), $provided)) {
        architect_api_json(403, array('ok' => false, 'error' => 'Invalid CSRF token.'));
    }
}

function architect_api_progress_path(int $planId): string
{
    $directory = dirname(__DIR__, 3) . '/storage/manual_change_assistant/architect_progress';
    if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
        throw new RuntimeException('Analysis progress storage is unavailable.');
    }
    return $directory . '/plan-' . $planId . '.json';
}

/** @param array<string,mixed> $progress */
function architect_api_write_progress(int $planId, array $progress): void
{
    $path = architect_api_progress_path($planId);
    $existing = array();
    if (is_file($path)) {
        $decoded = json_decode((string)file_get_contents($path), true);
        $existing = is_array($decoded) ? $decoded : array();
    }
    $payload = array_merge($existing, $progress, array(
        'plan_id' => $planId,
        'updated_at' => gmdate(DATE_ATOM),
    ));
    $temporary = $path . '.tmp-' . bin2hex(random_bytes(4));
    if (file_put_contents(
        $temporary,
        json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    ) === false || !rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException('Analysis progress could not be recorded.');
    }
}

/** @return array<string,mixed> */
function architect_api_read_progress(int $planId): array
{
    $path = architect_api_progress_path($planId);
    if (!is_file($path)) {
        return array();
    }
    $decoded = json_decode((string)file_get_contents($path), true);
    return is_array($decoded) ? $decoded : array();
}

function architect_api_progress_callback(int $planId): Closure
{
    return static function (array $progress) use ($planId): void {
        architect_api_write_progress($planId, $progress);
    };
}

/** @param array<string,mixed> $draft */
function architect_api_draft_stale(array $draft): bool
{
    if ((string)($draft['status'] ?? '') !== 'generating') {
        return false;
    }
    $payload = is_array($draft['draft_payload_json'] ?? null)
        ? $draft['draft_payload_json']
        : array();
    $started = strtotime((string)($payload['started_at'] ?? $draft['created_at'] ?? ''));
    return $started !== false && $started < time() - 600;
}

function architect_api_ensure_structure(
    int $planId,
    int $actorUserId,
    BooksManualsChangePlanService $plans,
    BooksManualsChangeArchitectService $architect,
    BooksManualsChangeStructureService $structures
): int {
    $report = $architect->getCompleteCheckpointReport($planId);
    $proposals = array_values(array_filter(
        (array)($report['structure_proposals'] ?? array()),
        'is_array'
    ));
    if ($proposals !== array()) {
        $latest = $proposals[array_key_last($proposals)];
        $proposalId = (int)($latest['id'] ?? 0);
        foreach ((array)($report['structure_nodes'] ?? array()) as $node) {
            if (is_array($node) && (int)($node['structure_proposal_id'] ?? 0) === $proposalId) {
                return $proposalId;
            }
        }
    }
    $plan = $plans->getPlan($planId);
    $sourceFingerprint = trim((string)($plan['source_fingerprint'] ?? ''));
    if (preg_match('/^[a-f0-9]{64}$/', $sourceFingerprint) !== 1) {
        $sourceFingerprint = hash('sha256', json_encode(array(
            'plan_id' => $planId,
            'primary_version_id' => $plans->primaryVersionId($plan),
            'source_fingerprint' => $sourceFingerprint,
        ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
    $areas = array_values(array_filter(
        (array)($report['impact_presentation']['areas'] ?? array()),
        'is_array'
    ));
    $proposal = $structures->buildProposalFromImpactPresentation(
        'Proposed manual structure for ' . (string)($plan['title'] ?? 'accepted impacts'),
        'Translate the accepted amendment architecture into one controlled future hierarchy without drafting manual wording.',
        $sourceFingerprint,
        $areas
    );
    return $structures->persistProposal($planId, $proposal, $actorUserId);
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
$structures = new BooksManualsChangeStructureService($pdo, $plans);
$author = new BooksManualsChangeAuthorService($pdo, $plans);
$reviewer = new BooksManualsChangeReviewerService($pdo, $plans);
$apply = new BooksManualsChangeApplyService($pdo, $plans);

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
            $options = array(
                'title' => $title,
                'objective' => $request,
                'change_request' => $request,
                'use_openai' => true,
            );
            $plan = $plans->createPlan(
                $versionId,
                array(
                    'title' => $title,
                    'objective' => $request,
                    'change_request' => $request,
                ),
                $userId
            );
            $planId = (int)($plan['id'] ?? 0);
            architect_api_write_progress($planId, array(
                'percent' => 2,
                'stage_key' => 'queued',
                'label' => 'Preparing the controlled analysis',
                'started_at' => gmdate(DATE_ATOM),
            ));
            $options['progress_callback'] = architect_api_progress_callback($planId);
            architect_api_finish_response(202, array(
                'ok' => true,
                'plan_id' => $planId,
                'status' => 'analyzing',
                'csrf_token' => architect_api_csrf(),
            ));
            ignore_user_abort(true);
            @set_time_limit(300);
            try {
                $architect->runCheckpoint($planId, $evidence, $options, $userId);
            } catch (Throwable $analysisError) {
                error_log(
                    'Manual Change Architect background analysis failed for plan '
                    . $planId . ': ' . $analysisError->getMessage()
                );
            }
            exit;

        case 'analysis_status':
            $planId = (int)($input['plan_id'] ?? 0);
            $plan = $plans->getPlan($planId);
            if ((int)($plan['owner_id'] ?? 0) !== $userId) {
                throw new RuntimeException('Only the Change Plan owner can view analysis progress.');
            }
            $status = (string)($plan['status'] ?? 'analyzing');
            architect_api_json(200, array(
                'ok' => true,
                'plan_id' => $planId,
                'status' => $status,
                'stage' => (string)($plan['stage'] ?? 'intent'),
                'complete' => in_array($status, array('ready_for_review', 'blocked'), true),
                'failed' => $status === 'failed',
                'message' => $status === 'failed'
                    ? (string)($plan['failure_message'] ?? 'Analysis failed.')
                    : '',
                'progress' => architect_api_read_progress($planId),
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

        case 'request_impact_changes':
            $planId = (int)($input['plan_id'] ?? 0);
            $impactId = max(0, (int)($input['impact_id'] ?? 0));
            $correction = trim((string)($input['correction'] ?? ''));
            if (mb_strlen($correction) < 10 || mb_strlen($correction) > 10000) {
                throw new InvalidArgumentException('Describe the requested correction in 10 to 10,000 characters.');
            }
            $plan = $plans->getPlan($planId);
            if ((int)($plan['owner_id'] ?? 0) !== $userId) {
                throw new RuntimeException('Only the Change Plan owner can request re-analysis.');
            }
            $report = $architect->getCompleteCheckpointReport($planId);
            $affectedArea = 'General / overall analysis';
            if ($impactId > 0) {
                $matchedImpact = null;
                foreach ((array)($report['impacts'] ?? array()) as $impact) {
                    if (is_array($impact) && (int)($impact['id'] ?? 0) === $impactId) {
                        $matchedImpact = $impact;
                        break;
                    }
                }
                if (!is_array($matchedImpact)) {
                    throw new InvalidArgumentException('The selected amendment area does not belong to this Change Plan.');
                }
                $affectedArea = trim(
                    (string)($matchedImpact['section_number'] ?? '') . ' '
                    . (string)($matchedImpact['section_title'] ?? 'Manual section')
                );
            }
            $evidence = array();
            foreach ((array)($report['evidence'] ?? array()) as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $text = trim((string)($item['content_text'] ?? $item['evidence_text'] ?? $item['excerpt'] ?? ''));
                if ($text === '') {
                    continue;
                }
                $evidence[] = array(
                    'title' => (string)($item['title'] ?? 'Supporting evidence'),
                    'reference_code' => (string)($item['reference_code'] ?? ''),
                    'text' => $text,
                );
            }
            $evidence[] = array(
                'title' => 'Governed human impact-review feedback',
                'reference_code' => 'IMPACT-REVIEW-FEEDBACK-' . gmdate('YmdHis'),
                'text' => "Affected area: {$affectedArea}\n\nCorrection requested by the accountable reviewer:\n{$correction}",
            );
            $plans->appendEvent($planId, 'IMPACT_CHANGES_REQUESTED', 10, array(
                'impact_id' => $impactId > 0 ? $impactId : null,
                'affected_area' => $affectedArea,
                'correction' => $correction,
                'correction_sha256' => hash('sha256', $correction),
            ), $userId);
            $plans->clearPostImpactData($planId);
            $plans->updatePlan($planId, array(
                'status' => 'analyzing',
                'stage' => 'impact',
                'checkpoint_stage' => 2,
                'failure_message' => null,
                'completed_at' => null,
                'updated_by' => $userId,
            ));
            architect_api_write_progress($planId, array(
                'percent' => 2,
                'stage_key' => 'queued',
                'label' => 'Preparing the revised controlled analysis',
                'started_at' => gmdate(DATE_ATOM),
            ));
            $reanalysisOptions = array(
                'title' => (string)($plan['title'] ?? 'Manual Change Architect checkpoint'),
                'objective' => (string)($plan['objective'] ?? $plan['change_request'] ?? ''),
                'change_request' => (string)($plan['change_request'] ?? ''),
                'review_feedback' => array(
                    'affected_area' => $affectedArea,
                    'correction' => $correction,
                ),
                'use_openai' => true,
                'progress_callback' => architect_api_progress_callback($planId),
            );
            architect_api_finish_response(202, array(
                'ok' => true,
                'plan_id' => $planId,
                'status' => 'analyzing',
                'csrf_token' => architect_api_csrf(),
            ));
            ignore_user_abort(true);
            @set_time_limit(300);
            try {
                $architect->runCheckpoint($planId, $evidence, $reanalysisOptions, $userId);
            } catch (Throwable $analysisError) {
                error_log(
                    'Manual Change Architect impact re-analysis failed for plan '
                    . $planId . ': ' . $analysisError->getMessage()
                );
            }
            exit;

        case 'accept_impact_analysis':
            $planId = (int)($input['plan_id'] ?? 0);
            $impactReport = $architect->getCompleteCheckpointReport($planId);
            $qualityGate = (array)($impactReport['impact_presentation']['quality_gate'] ?? array());
            if (empty($qualityGate['reviewable'])) {
                $messages = array_values(array_filter(array_map(
                    static fn(mixed $failure): string => is_array($failure)
                        ? trim((string)($failure['section'] ?? '') . ' ' . (string)($failure['message'] ?? ''))
                        : '',
                    (array)($qualityGate['failures'] ?? array())
                )));
                throw new RuntimeException(
                    'Impact analysis is not reviewable yet'
                    . ($messages === array() ? '.' : ': ' . implode('; ', $messages))
                );
            }
            $approvedImpactIds = array_values(array_unique(array_filter(array_map(
                static fn(mixed $area): int => is_array($area) ? (int)($area['impact_id'] ?? 0) : 0,
                (array)($impactReport['impact_presentation']['areas'] ?? array())
            ))));
            architect_api_json(200, array(
                'ok' => true,
                'result' => $plans->acceptImpactAnalysis(
                    $planId,
                    $userId,
                    static function () use (
                        $planId,
                        $userId,
                        $plans,
                        $architect,
                        $structures
                    ): array {
                        return array(
                            'structure_proposal_id' => architect_api_ensure_structure(
                                $planId,
                                $userId,
                                $plans,
                                $architect,
                                $structures
                            ),
                        );
                    },
                    $approvedImpactIds
                ),
                'csrf_token' => architect_api_csrf(),
            ));

        case 'resolve_review_blocker':
            $planId = (int)($input['plan_id'] ?? 0);
            $blockerId = trim((string)($input['blocker_id'] ?? ''));
            $report = $architect->getCompleteCheckpointReport($planId);
            $presentation = (array)($report['impact_presentation'] ?? array());
            $blocker = null;
            foreach ((array)($presentation['quality_gate']['failures'] ?? array()) as $candidate) {
                if (is_array($candidate)
                    && hash_equals((string)($candidate['blocker_id'] ?? ''), $blockerId)) {
                    $blocker = $candidate;
                    break;
                }
            }
            if (!is_array($blocker)) {
                throw new RuntimeException('This review blocker is no longer active. Refresh the analysis.');
            }
            $area = array();
            foreach ((array)($presentation['areas'] ?? array()) as $candidate) {
                if (is_array($candidate)
                    && (string)($candidate['section_number'] ?? '') === (string)$blocker['section']) {
                    $area = $candidate;
                    break;
                }
            }
            $result = $plans->recordReviewBlockerResolution(
                $planId,
                $blocker,
                $area,
                (string)($input['resolution_type'] ?? ''),
                (string)($input['disposition'] ?? ''),
                (string)($input['rationale'] ?? ''),
                (string)($input['residual_risk'] ?? ''),
                (string)($input['merge_target_section'] ?? ''),
                $userId
            );
            $updatedReport = $architect->getCompleteCheckpointReport($planId);
            architect_api_json(200, array(
                'ok' => true,
                'result' => $result + array(
                    'quality_gate' => $updatedReport['impact_presentation']['quality_gate'] ?? array(),
                ),
                'csrf_token' => architect_api_csrf(),
            ));

        case 'accept_structure':
            $planId = (int)($input['plan_id'] ?? 0);
            architect_api_ensure_structure(
                $planId,
                $userId,
                $plans,
                $architect,
                $structures
            );
            $structureResult = $plans->acceptStructure(
                $planId,
                $userId
            );
            architect_api_write_progress($planId, array(
                'percent' => 3,
                'stage_key' => 'drafting',
                'label' => 'Preparing authorized manual amendments',
                'started_at' => gmdate(DATE_ATOM),
            ));
            architect_api_finish_response(202, array(
                'ok' => true,
                'result' => $structureResult + array('draft_status' => 'generating'),
                'csrf_token' => architect_api_csrf(),
            ));
            ignore_user_abort(true);
            @set_time_limit(360);
            try {
                $author->generateAndPersist($planId, $userId, array(
                    'progress_callback' => architect_api_progress_callback($planId),
                ));
            } catch (Throwable $draftError) {
                error_log(
                    'Manual Change Author generation failed for plan '
                    . $planId . ': ' . $draftError->getMessage()
                );
            }
            exit;

        case 'generate_drafts':
            $planId = (int)($input['plan_id'] ?? 0);
            $plan = $plans->getPlan($planId);
            if ((int)($plan['owner_id'] ?? 0) !== $userId) {
                throw new RuntimeException('Only the Change Plan owner can generate amendment wording.');
            }
            $report = $plans->loadPlan($planId);
            $draftRows = array_values(array_filter((array)($report['drafts'] ?? array()), 'is_array'));
            $latestDraft = $draftRows === array() ? array() : $draftRows[array_key_last($draftRows)];
            $latestStatus = (string)($latestDraft['status'] ?? '');
            if ($latestStatus === 'generated') {
                architect_api_json(200, array(
                    'ok' => true,
                    'result' => array('draft_status' => 'generated'),
                    'csrf_token' => architect_api_csrf(),
                ));
            }
            if ($latestStatus === 'generating' && !architect_api_draft_stale($latestDraft)) {
                architect_api_json(202, array(
                    'ok' => true,
                    'result' => array('draft_status' => 'generating'),
                    'csrf_token' => architect_api_csrf(),
                ));
            }
            if ($latestStatus === 'generating') {
                $pdo->prepare(
                    "UPDATE ipca_manual_ai_architect_drafts SET status='abandoned' WHERE id=? AND plan_id=?"
                )->execute(array((int)$latestDraft['id'], $planId));
                $plans->appendEvent($planId, 'AMENDMENT_DRAFTING_TIMED_OUT', 12, array(
                    'draft_id' => (int)$latestDraft['id'],
                    'timeout_seconds' => 600,
                ), $userId);
            }
            architect_api_write_progress($planId, array(
                'percent' => 3,
                'stage_key' => 'drafting',
                'label' => 'Preparing authorized manual amendments',
                'started_at' => gmdate(DATE_ATOM),
            ));
            architect_api_finish_response(202, array(
                'ok' => true,
                'result' => array('draft_status' => 'generating'),
                'csrf_token' => architect_api_csrf(),
            ));
            ignore_user_abort(true);
            @set_time_limit(360);
            try {
                $author->generateAndPersist($planId, $userId, array(
                    'progress_callback' => architect_api_progress_callback($planId),
                ));
            } catch (Throwable $draftError) {
                error_log(
                    'Manual Change Author generation failed for plan '
                    . $planId . ': ' . $draftError->getMessage()
                );
            }
            exit;

        case 'draft_status':
            $planId = (int)($input['plan_id'] ?? 0);
            $plan = $plans->getPlan($planId);
            if ((int)($plan['owner_id'] ?? 0) !== $userId) {
                throw new RuntimeException('Only the Change Plan owner can view drafting progress.');
            }
            $report = $plans->loadPlan($planId);
            $draftRows = array_values(array_filter((array)($report['drafts'] ?? array()), 'is_array'));
            $latestDraft = $draftRows === array() ? array() : $draftRows[array_key_last($draftRows)];
            $draftPayload = is_array($latestDraft['draft_payload_json'] ?? null)
                ? $latestDraft['draft_payload_json']
                : array();
            $latestStatus = (string)($latestDraft['status'] ?? 'not_started');
            if (architect_api_draft_stale($latestDraft)) {
                $latestStatus = 'abandoned';
                $draftPayload['failure_message'] = 'Draft generation stopped before completing. Retry the governed drafting job.';
                $draftPayload['generation_status'] = 'failed';
                $draftPayload['failed_at'] = gmdate(DATE_ATOM);
                $encodedDraftPayload = json_encode(
                    $draftPayload,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                );
                $pdo->prepare(
                    "UPDATE ipca_manual_ai_architect_drafts
                        SET status='abandoned',draft_payload_json=?,content_fingerprint=?
                      WHERE id=? AND plan_id=?"
                )->execute(array(
                    $encodedDraftPayload,
                    hash('sha256', $encodedDraftPayload),
                    (int)$latestDraft['id'],
                    $planId,
                ));
                $plans->updatePlan($planId, array(
                    'stage' => 'drafting',
                    'status' => 'blocked',
                    'failure_message' => $draftPayload['failure_message'],
                    'updated_by' => $userId,
                ));
                $plans->appendEvent($planId, 'AMENDMENT_DRAFTING_TIMED_OUT', 12, array(
                    'draft_id' => (int)$latestDraft['id'],
                    'timeout_seconds' => 600,
                ), $userId);
            }
            architect_api_json(200, array(
                'ok' => true,
                'result' => array(
                    'draft_status' => $latestStatus,
                    'message' => (string)(
                        $draftPayload['failure_message']
                        ?? $plan['failure_message']
                        ?? ''
                    ),
                    'progress' => architect_api_read_progress($planId),
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
            $planId = (int)($input['plan_id'] ?? 0);
            $result = $plans->acceptDrafts($planId, $userId);
            $reviewId = architect_api_prepare_independent_review(
                $pdo,
                $plans,
                $reviewer,
                $apply,
                $planId,
                $userId
            );
            $plans->appendEvent($planId, 'INDEPENDENT_REVIEW_PREPARED', 12, array(
                'review_id' => $reviewId,
            ), $userId);
            architect_api_json(200, array(
                'ok' => true,
                'result' => $result,
                'review_id' => $reviewId,
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

        case 'apply_working_revision': // Backward-compatible action name for open stale pages.
        case 'apply_accepted_wizard_changes':
            $planId = (int)($input['plan_id'] ?? 0);
            $result = $apply->apply($planId, $userId);
            architect_api_json(200, array(
                'ok' => true,
                'result' => $result,
                'redirect' => '/admin/compliance/controlled_book_editor.php?version_id='
                    . (int)$result['book_version_id'],
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
