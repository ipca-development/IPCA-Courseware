<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$analytics = file_get_contents($root . '/src/safety/SafetyAnalyticsService.php') ?: '';
$ai = file_get_contents($root . '/src/safety/SafetyAiGovernanceService.php') ?: '';
$analyticsApi = file_get_contents($root . '/public/api/safety/analytics.php') ?: '';
$aiApi = file_get_contents($root . '/public/api/safety/ai-assistance.php') ?: '';
$kernel = file_get_contents($root . '/src/safety/SafetyKernel.php') ?: '';
$support = file_get_contents($root . '/src/safety/SafetySupport.php') ?: '';

require_once $root . '/src/safety/SafetyAiGovernanceService.php';

$deidentified = SafetyAiGovernanceService::deidentify(array(
    'reporter' => array('name' => 'Example Person', 'email' => 'person@example.test'),
    'reporter_user_id' => 42,
    'report_id' => 91,
    'aircraft_registration' => 'N123AB',
    'narrative' => 'Contact person@example.test or +1 (555) 123-4567 about N123AB after the event.',
    'category_code' => 'runway_incursion',
    'facts' => array('device_uuid' => 'secret', 'phase' => 'taxi'),
));

$checks = array(
    'analytics summary includes exposure-normalized rates' =>
        str_contains($analytics, "'exposure_normalized_rates'")
        && str_contains($analytics, "'rate_per_1000'")
        && str_contains($analytics, 'ipca_safety_exposure_snapshots'),
    'SPI definitions use whitelisted numerator tables' =>
        str_contains($analytics, 'private const NUMERATORS')
        && str_contains($analytics, "'denominator' => 'exposure'")
        && !str_contains($analytics, "\$definition['sql']"),
    'cross-domain analysis requires approved links and snapshots' =>
        str_contains($analytics, 'ipca_safety_cross_domain_links')
        && str_contains($analytics, 'ipca_safety_cross_domain_snapshots')
        && str_contains($analytics, 'approved_by_user_id')
        && str_contains($analytics, 'analyticsReferenceHash(')
        && str_contains($support, 'CW_SAFETY_ANALYTICS_LINK_KEY')
        && !preg_match('/JOIN\s+ipca_flight_/i', $analytics),
    'correlation output is non-causal and small samples are suppressed' =>
        str_contains($analytics, 'count($x) < 5')
        && str_contains($analytics, 'does not establish causation'),
    'AI use cases are an explicit assistance allowlist' =>
        str_contains($ai, "'taxonomy_suggestions'")
        && str_contains($ai, "'duplicate_candidates'")
        && str_contains($ai, "'summary'")
        && str_contains($ai, "'trend_candidates'")
        && str_contains($ai, "'missing_field_prompts'"),
    'AI authority boundaries block reserved human decisions' =>
        str_contains($ai, "'just_culture_decision'")
        && str_contains($ai, "'risk_acceptance'")
        && str_contains($ai, "'reportability_decision'")
        && str_contains($ai, "'action_approval'")
        && str_contains($ai, "'closure_decision'")
        && str_contains($ai, 'ai_decision_boundary_violation'),
    'AI ledger captures model template input output and provenance' =>
        str_contains($ai, 'prompt_template_version')
        && str_contains($ai, 'input_digest')
        && str_contains($ai, 'output_digest')
        && str_contains($ai, 'provider_provenance_json')
        && str_contains($ai, 'reviewed_output_digest'),
    'AI output cannot leave awaiting-review without human review' =>
        str_contains($ai, "'awaiting_review'")
        && str_contains($ai, "'ai.output_human_reviewed'")
        && str_contains($ai, "'accepted_as_advisory'"),
    'AI input requires scoped subjects and human free-text de-identification review' =>
        str_contains($ai, 'assertSubjectScoped(')
        && str_contains($ai, "'human_deidentification_reviewed'")
        && str_contains($ai, "'human_deidentification_review_required'"),
    'de-identification removes identity keys and redacts contact values' =>
        !isset($deidentified['reporter'])
        && !isset($deidentified['reporter_user_id'])
        && !isset($deidentified['report_id'])
        && !isset($deidentified['aircraft_registration'])
        && ($deidentified['category_code'] ?? null) === 'runway_incursion'
        && ($deidentified['facts']['phase'] ?? null) === 'taxi'
        && !isset($deidentified['facts']['device_uuid'])
        && str_contains((string)($deidentified['narrative'] ?? ''), '[REDACTED_EMAIL]')
        && str_contains((string)($deidentified['narrative'] ?? ''), '[REDACTED_PHONE]')
        && str_contains((string)($deidentified['narrative'] ?? ''), '[REDACTED_AIRCRAFT]')
        && !str_contains((string)($deidentified['narrative'] ?? ''), 'person@example.test'),
    'analytics and AI APIs require authenticated sessions' =>
        str_contains($analyticsApi, '$communicationKernel->auth->requireSession()')
        && str_contains($aiApi, '$communicationKernel->auth->requireSession()'),
    'kernel injects audit events into both assurance services' =>
        str_contains($kernel, 'new SafetyAnalyticsService($pdo, $this->access, $this->events)')
        && str_contains($kernel, 'new SafetyAiGovernanceService($pdo, $this->access, $this->config, $this->events)'),
);

$failed = array();
foreach ($checks as $name => $passed) {
    echo ($passed ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$passed) {
        $failed[] = $name;
    }
}
if ($failed !== array()) {
    fwrite(STDERR, 'Failed safety analytics/AI checks: ' . implode(', ', $failed) . PHP_EOL);
    exit(1);
}
echo 'OK: safety analytics and AI governance contract checks passed.' . PHP_EOL;
