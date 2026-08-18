<?php
declare(strict_types=1);

$root = dirname(__DIR__);

/** @return never */
function bmca_fail(string $message): void
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function bmca_assert(bool $condition, string $message): void
{
    if (!$condition) {
        bmca_fail($message);
    }
}

function bmca_source(string $path): string
{
    $value = file_get_contents($path);
    if (!is_string($value)) {
        bmca_fail("Unable to read {$path}");
    }
    return $value;
}

$servicePath = $root . '/src/publishing/BooksManualsChangeAssistantService.php';
$apiPath = $root . '/public/admin/api/books_manuals_change_assistant_api.php';
$workerPath = $root . '/scripts/books_manuals_change_assistant_worker.php';
$migrationPath = $root . '/scripts/sql/2026_08_18_ai_manual_change_assistant.sql';
$listPath = $root . '/public/admin/books_manuals/change_projects.php';
$detailPath = $root . '/public/admin/books_manuals/change_project.php';
$indexPath = $root . '/public/admin/books_manuals/index.php';
$navPath = $root . '/src/nav/admin.php';
$jsPath = $root . '/public/assets/books-manual-change-assistant.js';

foreach (array($servicePath, $apiPath, $workerPath, $migrationPath, $listPath, $detailPath, $jsPath) as $path) {
    bmca_assert(is_file($path), 'Required assistant file is missing: ' . basename($path));
}

$service = bmca_source($servicePath);
$jobService = bmca_source($root . '/src/publishing/BooksManualsChangeAssistantJobService.php');
$api = bmca_source($apiPath);
$migration = bmca_source($migrationPath);
$detail = bmca_source($detailPath);
$index = bmca_source($indexPath);
$nav = bmca_source($navPath);
$js = bmca_source($jsPath);

bmca_assert(str_contains($index, 'AI Change Assistant'), 'Library hero entry is missing.');
bmca_assert(str_contains($nav, 'Change Projects'), 'Books & Manuals navigation entry is missing.');
bmca_assert(str_contains($detail, 'Impact Finder') && str_contains($detail, 'Consistency &amp; Conflicts'), 'Full review stages are missing.');
bmca_assert(str_contains($detail, 'data-bmca-create-revision'), 'Released-scope draft revision action is missing.');

foreach (array(
    'ipca_manual_ai_projects',
    'ipca_manual_ai_sources',
    'ipca_manual_ai_version_scopes',
    'ipca_manual_ai_requirements',
    'ipca_manual_ai_content_chunks',
    'ipca_manual_ai_findings',
    'ipca_manual_ai_proposals',
    'ipca_manual_ai_decisions',
    'ipca_manual_ai_jobs',
) as $table) {
    bmca_assert(str_contains($migration, 'CREATE TABLE IF NOT EXISTS ' . $table), "Migration table {$table} is missing.");
}
bmca_assert(str_contains($migration, 'embedding_content_hash') && str_contains($migration, 'embedding_json'), 'Content-hash-aware embedding cache is missing.');

bmca_assert(str_contains($api, 'compliance_require_access($pdo)'), 'Compliance authorization gate is missing.');
bmca_assert(str_contains($api, 'manual_ai_require_csrf'), 'CSRF mutation gate is missing.');
bmca_assert(str_contains($api, 'assertProjectAccess'), 'Project ownership checks are missing.');
bmca_assert(str_contains($api, 'is_uploaded_file') && str_contains($api, 'finfo'), 'Upload validation is missing.');
bmca_assert(str_contains($api, '/storage/manual_change_assistant'), 'Private upload storage is missing.');

bmca_assert(str_contains($service, "'released'") || str_contains($service, '"released"'), 'Released versions are not available for read-only analysis.');
bmca_assert(str_contains($service, 'Only Draft versions can receive an AI change set'), 'Draft-only apply gate is missing.');
bmca_assert(str_contains($service, 'expected_block_hash') && str_contains($service, 'source_fingerprint'), 'Stale-content gates are missing.');
bmca_assert(str_contains($service, 'CW_MANUAL_AI_APPLY_ENABLED') && str_contains($service, 'hash_hmac'), 'Separate signed-manifest apply gate is missing.');
bmca_assert(str_contains($service, "'type' => 'json_schema'") && str_contains($service, "'strict' => true"), 'Strict JSON Schema output is missing.');
foreach (array('add', 'replace', 'cross_reference', 'investigate', 'no_change') as $classification) {
    bmca_assert(str_contains($service, "'{$classification}'"), "Action classification {$classification} is missing.");
}
bmca_assert(str_contains($service, 'untrusted evidence'), 'Prompt-injection boundary is missing.');
bmca_assert(str_contains($service, 'openAiEmbeddings') && str_contains($service, 'cosineSimilarity'), 'Embedding reranking is missing.');
bmca_assert(str_contains($service, 'ComplianceAiRunLogger::insert'), 'Central AI provenance logging is missing.');
bmca_assert(str_contains($service, 'AuditEventService') && str_contains($service, 'manual_change_assistant.apply'), 'Immutable audit event coverage is missing.');
bmca_assert(str_contains($service, 'startIntegrityRefresh') && str_contains($service, 'ControlledPublishingLivePageMapService'), 'Post-apply governed refreshes are missing.');
bmca_assert(str_contains($js, "request('decision'") && str_contains($js, 'proposed_text'), 'Human-edited decision persistence is missing.');
bmca_assert(str_contains($js, "request('create_revision'"), 'Explicit revision creation flow is missing.');
$assistantSqlSources = $service . "\n" . $jobService;
foreach (array('status="', 'role="', 'lifecycle_status="', 'extraction_status="', 'IN ("', 'VALUES (?,"') as $forbiddenSql) {
    bmca_assert(
        !str_contains($assistantSqlSources, $forbiddenSql),
        'Assistant SQL must use ANSI-compatible single-quoted literals.'
    );
}

require_once $servicePath;
$reflection = new ReflectionClass(BooksManualsChangeAssistantService::class);
$instance = $reflection->newInstanceWithoutConstructor();

$conceptTerms = $reflection->getMethod('conceptTerms');
$expanded = $conceptTerms->invoke($instance, array('paper', 'training', 'file'));
bmca_assert(in_array('hardcopy', $expanded, true), 'Paper-file synonym expansion is not deterministic.');
bmca_assert(in_array('record', $expanded, true), 'Records synonym expansion is not deterministic.');

$cosine = $reflection->getMethod('cosineSimilarity');
bmca_assert(abs((float)$cosine->invoke($instance, array(1.0, 0.0), array(1.0, 0.0)) - 1.0) < 0.000001, 'Cosine identity fixture failed.');
bmca_assert(abs((float)$cosine->invoke($instance, array(1.0, 0.0), array(0.0, 1.0))) < 0.000001, 'Cosine orthogonal fixture failed.');

$canonical = $reflection->getMethod('canonicalJson');
$left = $canonical->invoke($instance, array('z' => 1, 'a' => array('y' => 2, 'b' => 3)));
$right = $canonical->invoke($instance, array('a' => array('b' => 3, 'y' => 2), 'z' => 1));
bmca_assert(hash_equals((string)$left, (string)$right), 'Manifest canonicalization fixture failed.');

echo "PASS: AI Manual Change Assistant contracts and provider-free retrieval fixtures\n";
