<?php
declare(strict_types=1);

$root = dirname(__DIR__);

function bm_contract_assert(string $label, bool $condition): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
    echo "PASS: {$label}\n";
}

$workflow = (string)file_get_contents($root . '/src/publishing/BooksManualsWorkflowService.php');
$policy = (string)file_get_contents($root . '/src/publishing/BooksManualsReaderPolicyService.php');
$audit = (string)file_get_contents($root . '/src/publishing/BooksManualsAuditService.php');
$annexMigration = (string)file_get_contents(
    $root . '/src/publishing/BooksManualsAnnexMigrationService.php'
);
$annexCommand = (string)file_get_contents(
    $root . '/scripts/migrate_books_manuals_annexes.php'
);
$migration = (string)file_get_contents(
    $root . '/scripts/sql/2026_08_18_books_manuals_workflow.sql'
);
$readerApi = (string)file_get_contents($root . '/public/student/api/manual_reader_api.php');
$nav = (string)file_get_contents($root . '/src/nav/admin.php');

foreach (array(
    "'draft' => array('publish_review' => 'in_review')" => 'Draft to Draft Review',
    "'in_review' => array('revert_draft' => 'draft', 'ready_approval' => 'approved')" => 'Draft Review transitions',
    "'manual_approve' => 'released'" => 'Awaiting Approval to Approved',
    'createNextDraftVersion' => 'revision cloning reuses publishing foundation',
    'suggestNextVersionLabel' => 'major revision label calculation',
    'syncUpdateIdentity' => 'monotonic update identity',
    'source_fingerprint' => 'source fingerprint',
    'page_map_hash' => 'page-map fingerprint',
    'manifest_hash' => 'manifest fingerprint',
) as $needle => $label) {
    bm_contract_assert($label, str_contains($workflow, $needle));
}

foreach (array(
    'ipca_publishing_book_profiles',
    'ipca_publishing_version_workflow',
    'ipca_publishing_lifecycle_events',
    'ipca_publishing_version_audiences',
    'ipca_publishing_audit_snapshots',
    'ipca_publishing_annex_book_links',
) as $table) {
    bm_contract_assert("additive schema {$table}", str_contains($migration, $table));
}

bm_contract_assert(
    'existing released audiences are backfilled',
    str_contains($migration, "WHERE bv.lifecycle_status = 'released'")
);
bm_contract_assert(
    'Draft is hidden by governed reader policy',
    str_contains($policy, "if (\$status === 'draft')")
);
bm_contract_assert(
    'review phases require approved reviewers',
    str_contains($policy, "array('in_review', 'approved')")
        && str_contains($policy, 'isApprovedReviewer')
);
bm_contract_assert(
    'Approved reader access uses assigned audiences',
    str_contains($policy, 'matchesAudience')
);
bm_contract_assert(
    'reader API decorates optional workflow fields',
    str_contains($readerApi, 'filterAndDecorateLibrary')
        && str_contains($readerApi, 'BooksManualsReaderPolicyService')
);
bm_contract_assert(
    'legacy Controlled Books menu item is hidden',
    preg_match(
        "/'key'\\s*=>\\s*'compliance_controlled_books'.*?'visible'\\s*=>\\s*false/s",
        $nav
    ) === 1
);
bm_contract_assert(
    'native Books & Manuals navigation is present',
    str_contains($nav, "'key' => 'books_manuals'")
        && str_contains($nav, "'label' => 'IPCA Library'")
        && str_contains($nav, "'label' => 'Annexes'")
);
bm_contract_assert(
    'Awaiting Approval creates an immutable audit snapshot',
    str_contains($workflow, "if (\$action === 'ready_approval')")
        && str_contains($workflow, 'createSnapshot')
);
bm_contract_assert(
    'approval rejects stale audit evidence',
    str_contains($workflow, 'assertPassingCurrentAudit')
        && str_contains($workflow, 'changed after its audit snapshot')
);
foreach (array(
    'ControlledPublishingMccfIntegrityJobService' => 'existing MCCF integrity jobs are reused',
    'ipca_mccf_integrity_scores' => 'MCCF scores feed immutable snapshots',
    'assertAuthoritativePageMapReadyForRelease' => 'authoritative pagination gate',
    "baseline_status'] ?? '') === 'frozen'" => 'source baseline gate',
    'ipca_publishing_audit_snapshots' => 'immutable audit snapshot persistence',
) as $needle => $label) {
    bm_contract_assert($label, str_contains($audit, $needle));
}
foreach (array(
    "LIKE 'annexes_annex_%'" => 'embedded annex inventory',
    'dryRunReport' => 'annex dry-run report',
    'manifest_hash' => 'approved annex manifest integrity',
    'already_migrated' => 'idempotent annex migration',
    'validateRenderedParity' => 'rendered annex output comparison',
    'legacy_section_preserved' => 'legacy annex preservation',
    'rollback' => 'annex rollback mapping',
    'remapPayload' => 'annex cross-reference remapping',
    "annex_parent.section_key = 'annexes'" => 'annex subtree-only inventory',
    "['canonical_excerpt_id'] = null" => 'standalone annex canonical identity reset',
) as $needle => $label) {
    bm_contract_assert($label, str_contains($annexMigration, $needle));
}
bm_contract_assert(
    'production annex migration requires explicit reviewed manifest',
    str_contains($annexCommand, '--apply')
        && str_contains($annexCommand, '--approved-manifest')
        && str_contains($annexCommand, '--actor-user-id')
);

echo "Books & Manuals workflow contracts: PASS\n";
