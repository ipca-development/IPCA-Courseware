<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$migrationPath = $root . '/scripts/sql/2026_08_18_ai_manual_change_architect.sql';
$runnerPath = $root . '/scripts/apply_ai_manual_change_architect.php';
$migration = file_get_contents($migrationPath) ?: '';
$runner = file_get_contents($runnerPath) ?: '';

$checks = array(
    'migration exists and is non-empty' => $migration !== '',
    'apply runner exists and is non-empty' => $runner !== '',
    'migration is additive and idempotent' => substr_count($migration, 'CREATE TABLE IF NOT EXISTS ') === 18,
    'migration has no destructive statements' => preg_match('/\b(?:DROP|TRUNCATE|DELETE)\s+(?:TABLE|FROM)\b/i', $migration) !== 1,
    'migration does not alter Assistant tables' => preg_match('/\bALTER\s+TABLE\b/i', $migration) !== 1,
    'all created tables use architect namespace' => preg_match_all(
        '/CREATE TABLE IF NOT EXISTS\s+([a-z0-9_]+)/i',
        $migration,
        $tableMatches
    ) === 18 && count(array_filter(
        $tableMatches[1],
        static fn(string $table): bool => str_starts_with($table, 'ipca_manual_ai_architect_')
    )) === 18,
    'plan has one required primary revision' => str_contains($migration, 'primary_book_version_id BIGINT UNSIGNED NOT NULL')
        && substr_count($migration, 'primary_book_version_id BIGINT UNSIGNED NOT NULL') === 1,
    'plan exposes explicit stage and status' => str_contains($migration, 'stage VARCHAR(32) NOT NULL')
        && str_contains($migration, 'status VARCHAR(32) NOT NULL'),
    'plan stores fingerprints and owner' => str_contains($migration, 'source_fingerprint CHAR(64) NOT NULL')
        && str_contains($migration, 'plan_fingerprint CHAR(64) NOT NULL')
        && str_contains($migration, 'owner_id INT NOT NULL'),
    'plan stores the complete natural-language request' => str_contains(
        $migration,
        'change_request MEDIUMTEXT NOT NULL'
    ),
    'legacy project linkage is optional' => str_contains($migration, 'linked_legacy_project_id BIGINT UNSIGNED NULL')
        && str_contains($migration, 'REFERENCES ipca_manual_ai_projects(id) ON DELETE SET NULL'),
    'scope classifications are explicit' => str_contains(
        $migration,
        'MUST_CHANGE | MUST_PRESERVE | OUT_OF_SCOPE | REVIEW_SEPARATELY'
    ),
    'legacy dispositions are explicit' => substr_count(
        $migration,
        'REMOVE_OR_REPLACE | PRESERVE_WITH_JUSTIFICATION | REVIEW_SEPARATELY'
    ) >= 2,
    'legacy decisions retain history' => str_contains($migration, 'ipca_manual_ai_architect_legacy_hit_decisions')
        && str_contains($migration, 'previous_decision_id BIGINT UNSIGNED NULL'),
    'target-state coverage matrix exists' => str_contains(
        $migration,
        'CREATE TABLE IF NOT EXISTS ipca_manual_ai_architect_target_coverage'
    ) && str_contains($migration, 'change_intent_id BIGINT UNSIGNED NOT NULL')
        && str_contains($migration, 'target_component_id BIGINT UNSIGNED NOT NULL')
        && str_contains(
            $migration,
            'PRESERVED_COVERED | AMEND_EXISTING | ADD_CONTENT | NOT_APPLICABLE | REVIEW_REQUIRED'
        ),
    'change intent preserves specialist reasoning constraints' => str_contains(
        $migration,
        'required_outcomes_json JSON NOT NULL'
    ) && str_contains($migration, 'constraints_json JSON NOT NULL')
        && str_contains($migration, 'preserved_concepts_json JSON NOT NULL')
        && str_contains($migration, 'known_limitations_json JSON NOT NULL')
        && str_contains($migration, 'authoritative_facts_json JSON NOT NULL'),
    'target state covers the complete operating model' => str_contains(
        $migration,
        'human_decision | automatic_action | record_evidence | deadline | control | approval | monitoring | training | closure | limitation'
    ),
    'impact architecture stores evidence and quality gates' => str_contains(
        $migration,
        'PRESERVE | AMEND | REPLACE | RESTRUCTURE | ADD | REMOVE_OBSOLETE | REVIEW_SEPARATELY'
    ) && str_contains($migration, 'substantive_rationale TEXT NOT NULL')
        && str_contains($migration, 'current_state_summary TEXT NOT NULL')
        && str_contains($migration, 'preserved_logic_json JSON NOT NULL')
        && str_contains($migration, 'canonical_evidence_json JSON NOT NULL')
        && str_contains($migration, 'minimality_test TEXT NOT NULL')
        && str_contains($migration, 'completeness_test TEXT NOT NULL'),
    'impact dependencies exist' => str_contains(
        $migration,
        'CREATE TABLE IF NOT EXISTS ipca_manual_ai_architect_impact_dependencies'
    ) && str_contains($migration, 'depends_on_impact_id BIGINT UNSIGNED NOT NULL'),
    'structure proposal tree exists' => str_contains(
        $migration,
        'CREATE TABLE IF NOT EXISTS ipca_manual_ai_architect_structure_proposals'
    ) && str_contains($migration, 'parent_node_id BIGINT UNSIGNED NULL')
        && str_contains($migration, "decision_status VARCHAR(24) NOT NULL DEFAULT 'proposed'")
        && str_contains($migration, 'fk_imaa_node_decider'),
    'immutable event streams have no mutable timestamp' => preg_match(
        '/CREATE TABLE IF NOT EXISTS ipca_manual_ai_architect_decision_events\s*\((.*?)\)\s*ENGINE=/s',
        $migration,
        $decisionEventMatch
    ) === 1 && !str_contains($decisionEventMatch[1], 'updated_at')
        && preg_match(
            '/CREATE TABLE IF NOT EXISTS ipca_manual_ai_architect_edit_events\s*\((.*?)\)\s*ENGINE=/s',
            $migration,
            $editEventMatch
        ) === 1 && !str_contains($editEventMatch[1], 'updated_at'),
    'cross-manual impact and plan links exist' => str_contains($migration, 'impact_id BIGINT UNSIGNED NULL')
        && str_contains($migration, 'linked_plan_id BIGINT UNSIGNED NULL')
        && str_contains($migration, 'target_book_version_id BIGINT UNSIGNED NOT NULL'),
    'future persistence tables exist' => str_contains(
        $migration,
        'CREATE TABLE IF NOT EXISTS ipca_manual_ai_architect_drafts'
    ) && str_contains($migration, 'CREATE TABLE IF NOT EXISTS ipca_manual_ai_architect_reviews')
        && str_contains($migration, 'CREATE TABLE IF NOT EXISTS ipca_manual_ai_architect_operations'),
    'SQL is ANSI_QUOTES safe' => preg_match('/DEFAULT\s+"/i', $migration) !== 1
        && preg_match('/COMMENT\s+"/i', $migration) !== 1,
    'runner is CLI-only and targets migration' => str_contains($runner, "PHP_SAPI !== 'cli'")
        && str_contains($runner, "sql/2026_08_18_ai_manual_change_architect.sql"),
    'runner verifies every architect table' => count(array_filter(
        $tableMatches[1],
        static fn(string $table): bool => str_contains($runner, "'{$table}'")
    )) === 18,
);

$failures = array();
foreach ($checks as $label => $passed) {
    echo ($passed ? 'PASS' : 'FAIL') . ' ' . $label . PHP_EOL;
    if (!$passed) {
        $failures[] = $label;
    }
}

if ($failures !== array()) {
    fwrite(STDERR, 'Failed Manual Change Architect schema checks: ' . implode(', ', $failures) . PHP_EOL);
    exit(1);
}

echo 'OK: Manual Change Architect schema and apply runner contracts passed.' . PHP_EOL;
