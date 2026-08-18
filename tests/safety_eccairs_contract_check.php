<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$schema = file_get_contents($root . '/scripts/sql/2026_08_18_safety_eccairs2_integration.sql') ?: '';
$service = file_get_contents($root . '/src/safety/SafetyEccairsService.php') ?: '';
$access = file_get_contents($root . '/src/safety/SafetyAccessService.php') ?: '';
$api = file_get_contents($root . '/public/admin/api/safety.php') ?: '';
$ui = file_get_contents($root . '/public/admin/safety/index.php') ?: '';
$worker = file_get_contents($root . '/scripts/safety/eccairs_worker.php') ?: '';
$pdf = file_get_contents($root . '/scripts/safety/legacy_pdf_extract.php') ?: '';
$taxonomyImporter = file_get_contents($root . '/scripts/safety/eccairs_taxonomy_import.php') ?: '';
$xsd11Validator = file_get_contents($root . '/scripts/safety/eccairs_xsd11_validate.py') ?: '';
$legacyImporter = file_get_contents($root . '/scripts/safety/legacy_sms_import.php') ?: '';

$tables = array(
    'ipca_safety_eccairs_connections',
    'ipca_safety_eccairs_taxonomy_packages',
    'ipca_safety_eccairs_taxonomy_entities',
    'ipca_safety_eccairs_taxonomy_attributes',
    'ipca_safety_eccairs_taxonomy_values',
    'ipca_safety_eccairs_mappings',
    'ipca_safety_eccairs_submissions',
    'ipca_safety_eccairs_artifacts',
    'ipca_safety_eccairs_approvals',
    'ipca_safety_eccairs_attempts',
    'ipca_safety_eccairs_status_history',
    'ipca_safety_legacy_document_extractions',
    'ipca_safety_eccairs_historical_correlations',
);
$checks = array();
$approvalSection = explode(
    'CREATE TABLE IF NOT EXISTS ipca_safety_eccairs_artifacts',
    explode('CREATE TABLE IF NOT EXISTS ipca_safety_eccairs_approvals', $schema, 2)[1] ?? '',
    2
)[0] ?? '';
$historicalSection = explode(
    ') ENGINE=InnoDB',
    explode('CREATE TABLE IF NOT EXISTS ipca_safety_eccairs_historical_correlations', $schema, 2)[1] ?? '',
    2
)[0] ?? '';
foreach ($tables as $table) {
    $checks['idempotent ECCAIRS table exists: ' . $table] =
        str_contains($schema, 'CREATE TABLE IF NOT EXISTS ' . $table . ' (');
}
$checks['ECCAIRS schema is organization scoped and stores no credentials or tokens'] =
    substr_count($schema, 'organization_id') >= count($tables)
    && !preg_match('/\n\s+(access_token|refresh_token|password|client_secret)\s+/i', $schema);
$checks['production connection and transmission are fail-closed'] =
    str_contains($schema, "'production', 'https://api.aviationreporting.eu'")
    && str_contains($schema, 'production_transmission_enabled')
    && str_contains($service, "'eccairs_production_disabled'");
$checks['canonical versions and exact digests prevent duplicate preparation'] =
    str_contains($schema, 'uk_safety_e2_occurrence_version')
    && str_contains($schema, 'uk_safety_e2_canonical')
    && str_contains($service, "'eccairs_duplicate_canonical'");
$checks['human approval is bound to the exact canonical digest'] =
    str_contains($schema, 'ipca_safety_eccairs_approvals')
    && str_contains($service, "requirePermission(\$session, 'eccairs.approve')")
    && str_contains($service, "'canonical_sha256' => (string)\$submission['canonical_sha256']")
    && str_contains($service, "'awaiting_approval'");
$checks['REST and E5X artifacts derive separately from canonical data'] =
    str_contains($service, 'ipca-eccairs-canonical-v1')
    && str_contains($service, 'final class SafetyEccairsRestSerializer')
    && str_contains($service, 'final class SafetyEccairsXmlSerializer')
    && str_contains($schema, 'envelope_json JSON NOT NULL')
    && str_contains($schema, 'envelope_sha256 CHAR(64) NOT NULL')
    && str_contains($service, 'eccairs_envelope_integrity_error')
    && str_contains($schema, 'ipca_safety_eccairs_artifacts')
    && str_contains($schema, 'Transport-specific REST or E5X artifacts');
$checks['only reportable human-assessed occurrences can be prepared'] =
    str_contains($service, "\$source['assessment']['decision']")
    && str_contains($service, 'Only a human-assessed reportable occurrence');
$checks['queue claiming is concurrency safe and bounded'] =
    str_contains($service, 'FOR UPDATE SKIP LOCKED')
    && str_contains($service, 'CW_ECCAIRS_MAX_ATTEMPTS')
    && str_contains($service, "'retry_pending'")
    && str_contains($worker, 'recoverStaleSending');
$checks['uncertain delivery blocks blind automatic retries'] =
    str_contains($service, "'delivery_uncertain'")
    && str_contains($service, 'verify ECCAIRS before manually retrying')
    && str_contains($service, "'verification_reference'");
$checks['HTTP client verifies TLS and never follows redirects'] =
    str_contains($service, 'CURLOPT_SSL_VERIFYPEER => true')
    && str_contains($service, 'CURLOPT_SSL_VERIFYHOST => 2')
    && str_contains($service, 'CURLOPT_FOLLOWLOCATION => false');
$checks['E2 payload IDs and OAuth refresh follow the published API contract'] =
    str_contains($service, "'instance_id' => '#id_number#'")
    && str_contains($service, "'ID' => (string)(\$entity['instance_id']")
    && str_contains($service, "'grant_type' => 'refresh_token'")
    && str_contains($service, 'private ?string $refreshToken = null');
$checks['E5X fallback requires official XSD 1.1 validation'] =
    str_contains($service, 'CW_ECCAIRS_TAXONOMY_ARCHIVE_PATH')
    && str_contains($service, 'CW_ECCAIRS_XSD11_PYTHON_PATH')
    && str_contains($service, 'CW_ECCAIRS_E5X_PACKAGER_COMMAND')
    && str_contains($service, 'CW_ECCAIRS_E5X_PACKAGER_PROFILE')
    && str_contains($service, 'eccairs_e5x_packaging_profile_unavailable')
    && str_contains($xsd11Validator, 'xmlschema.XMLSchema11')
    && str_contains($worker, "'e5x_export'");
$checks['taxonomy import preserves immutable package provenance'] =
    str_contains($taxonomyImporter, "hash_file('sha256', \$archivePath)")
    && str_contains($taxonomyImporter, 'eccairsSchemaAttributeCardinality')
    && str_contains($schema, 'uk_safety_e2_taxonomy_source')
    && str_contains($schema, 'source_sha256');
$checks['staff API and UI expose prepare, approve and retry controls'] =
    str_contains($api, "'prepare_eccairs'")
    && str_contains($api, "'approve_eccairs'")
    && str_contains($api, "'retry_eccairs'")
    && str_contains($ui, 'ECCAIRS 2 transmission')
    && str_contains($ui, 'Approve & queue');
$checks['ECCAIRS permissions separate preparation and transmission'] =
    str_contains($access, "'eccairs.prepare'")
    && str_contains($access, "'eccairs.approve'")
    && str_contains($access, "'eccairs.transmit'");
$checks['historical taxonomy correlation requires separate human review and never transmits'] =
    str_contains($access, "'eccairs.historical_review'")
    && str_contains($service, 'separation_of_duties_required')
    && str_contains($schema, 'ipca_safety_eccairs_historical_correlations')
    && !str_contains($legacyImporter, 'SafetyEccairsApiClient')
    && str_contains($legacyImporter, "'eccairs_transmission_queued' => 0");
$checks['correlation digest belongs only to historical correlation rows'] =
    !str_contains($approvalSection, 'correlation_sha256 CHAR(64) NOT NULL')
    && str_contains($historicalSection, 'correlation_sha256 CHAR(64) NOT NULL');
$checks['legacy SQL migration stages, quarantines and promotes with provenance'] =
    str_contains($legacyImporter, "validation_status = 'approved'")
    && str_contains($legacyImporter, 'ipca_safety_import_provenance')
    && str_contains($legacyImporter, 'Legacy source changed after staging')
    && str_contains($legacyImporter, "'restricted'");
$checks['legacy PDF extraction is local, aggregate-only and never transmits'] =
    str_contains($pdf, "'external_transmission' => false")
    && str_contains($pdf, "'content_emitted' => false")
    && str_contains($pdf, "'pdftotext'")
    && str_contains($pdf, "'tesseract'")
    && !str_contains($pdf, 'curl_');

$failed = array();
foreach ($checks as $name => $passed) {
    echo ($passed ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$passed) {
        $failed[] = $name;
    }
}
if ($failed !== array()) {
    fwrite(STDERR, 'Failed ECCAIRS contract checks: ' . implode(', ', $failed) . PHP_EOL);
    exit(1);
}
echo 'OK: ECCAIRS 2 contract checks passed.' . PHP_EOL;
