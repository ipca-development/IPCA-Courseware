<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$schema = file_get_contents($root . '/scripts/sql/2026_08_17_safety_management_foundation.sql') ?: '';

$tables = array(
    'ipca_safety_config',
    'ipca_safety_role_assignments',
    'ipca_safety_risk_matrix_versions',
    'ipca_safety_risk_matrix_cells',
    'ipca_safety_reports',
    'ipca_safety_reporter_vault',
    'ipca_safety_anonymous_mailboxes',
    'ipca_safety_rate_limits',
    'ipca_safety_idempotency_keys',
    'ipca_safety_attachments',
    'ipca_safety_reporter_updates',
    'ipca_safety_events',
    'ipca_safety_occurrences',
    'ipca_safety_reportability_assessments',
    'ipca_safety_taxonomy_nodes',
    'ipca_safety_report_taxonomy',
    'ipca_safety_hazards',
    'ipca_safety_controls',
    'ipca_safety_hazard_controls',
    'ipca_safety_risk_snapshots',
    'ipca_safety_investigations',
    'ipca_safety_investigation_evidence',
    'ipca_safety_investigation_factors',
    'ipca_safety_actions',
    'ipca_safety_action_evidence',
    'ipca_safety_action_effectiveness_reviews',
    'ipca_safety_action_closures',
    'ipca_safety_report_closures',
    'ipca_safety_links',
    'ipca_safety_bulletins',
    'ipca_safety_bulletin_acknowledgements',
    'ipca_safety_analytics_snapshots',
    'ipca_safety_exposure_snapshots',
    'ipca_safety_cross_domain_snapshots',
    'ipca_safety_cross_domain_links',
    'ipca_safety_spis',
    'ipca_safety_spi_values',
    'ipca_safety_ai_runs',
    'ipca_safety_ai_reviews',
    'ipca_safety_legacy_staging',
    'ipca_safety_import_provenance',
);

$checks = array();
foreach ($tables as $table) {
    $checks['idempotent table exists: ' . $table] =
        str_contains($schema, 'CREATE TABLE IF NOT EXISTS ' . $table . ' (');
    if (preg_match(
        '/CREATE TABLE IF NOT EXISTS ' . preg_quote($table, '/') . ' \((.*?)\) ENGINE=/s',
        $schema,
        $match
    )) {
        $checks['organization scope: ' . $table] = str_contains($match[1], 'organization_id');
    } else {
        $checks['organization scope: ' . $table] = false;
    }
}

$reportsBody = '';
preg_match(
    '/CREATE TABLE IF NOT EXISTS ipca_safety_reports \((.*?)\) ENGINE=/s',
    $schema,
    $reportsMatch
);
$reportsBody = (string)($reportsMatch[1] ?? '');
$checks['confidential reporter identity has separate vault'] =
    str_contains($schema, 'ipca_safety_reporter_vault')
    && str_contains($schema, 'identity_ciphertext')
    && str_contains($reportsBody, 'reporter_subject_hash')
    && !str_contains($reportsBody, 'identity_ciphertext');
$checks['mailbox persists hashes rather than plain secret'] =
    str_contains($schema, 'receipt_code_hash')
    && str_contains($schema, 'secret_hash')
    && !preg_match('/\n\s+mailbox_secret\s+/i', $schema);
$checks['rate limiter stores only keyed fingerprint'] =
    str_contains($schema, 'fingerprint_hmac')
    && !preg_match('/\n\s+(ip_address|user_agent|device_id)\s+/i', $schema);
$checks['event stream has chained immutable event shape'] =
    str_contains($schema, 'previous_event_hash')
    && str_contains($schema, 'event_hash')
    && str_contains($schema, 'Append-only tamper-evident');
$checks['migration contains no non-idempotent alter'] = !preg_match('/\bALTER\s+TABLE\b/i', $schema);
$checks['analytics has exposure denominators and explicit cross-domain links'] =
    str_contains($schema, 'exposure_value')
    && str_contains($schema, 'subject_reference_digest')
    && str_contains($schema, 'approved_by_user_id')
    && str_contains($schema, 'Explicit human-approved bridge');
$checks['AI ledger binds output review to digests and provenance'] =
    str_contains($schema, 'output_digest')
    && str_contains($schema, 'reviewed_output_digest')
    && str_contains($schema, 'deidentification_version')
    && str_contains($schema, 'provider_provenance_json');
$checks['report closure records the accountable human decision'] =
    str_contains($schema, 'ipca_safety_report_closures')
    && str_contains($schema, 'closed_by_user_id')
    && str_contains($schema, 'closure_rationale');
$checks['mobile safety capability flags are seeded'] =
    str_contains($schema, "'safety_reporting_enabled'")
    && str_contains($schema, "'anonymous_reporting_enabled'");
$checks['safety reporting is fail-closed until rollout approval'] =
    str_contains($schema, "('safety_reporting_enabled', '0')")
    && str_contains($schema, "(1, 'enabled', JSON_OBJECT('value', FALSE))");

$failed = array();
foreach ($checks as $name => $passed) {
    echo ($passed ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$passed) {
        $failed[] = $name;
    }
}
if ($failed !== array()) {
    fwrite(STDERR, 'Failed safety schema checks: ' . implode(', ', $failed) . PHP_EOL);
    exit(1);
}
echo 'OK: safety schema contract checks passed.' . PHP_EOL;
