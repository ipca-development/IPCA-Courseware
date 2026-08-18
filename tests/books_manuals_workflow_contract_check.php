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
$auditPage = (string)file_get_contents(
    $root . '/public/admin/compliance/books_manuals_audits.php'
);
$annexMigration = (string)file_get_contents(
    $root . '/src/publishing/BooksManualsAnnexMigrationService.php'
);
$annexCommand = (string)file_get_contents(
    $root . '/scripts/migrate_books_manuals_annexes.php'
);
$migration = (string)file_get_contents(
    $root . '/scripts/sql/2026_08_18_books_manuals_workflow.sql'
);
$libraryMigration = (string)file_get_contents(
    $root . '/scripts/sql/2026_08_18_books_manuals_library_ux.sql'
);
$readerApi = (string)file_get_contents($root . '/public/student/api/manual_reader_api.php');
$nav = (string)file_get_contents($root . '/src/nav/admin.php');
$libraryPage = (string)file_get_contents($root . '/public/admin/books_manuals/index.php');
$bookReader = (string)file_get_contents($root . '/public/admin/books_manuals/reader.php');

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
    'review phases require book-specific reviewer assignment',
    str_contains($policy, 'isBookReviewer')
        && str_contains($libraryMigration, 'ipca_publishing_book_reviewers')
);
bm_contract_assert(
    'Approved reader access uses the selected book policy',
    str_contains($policy, 'approvedReaderPolicy')
        && str_contains($libraryMigration, 'approved_reader_policy')
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
foreach (array(
    "array('label' => '+', 'modal' => 'bm-create-manual')" => 'hero plus-button creation',
    'Create NEW Revision Draft' => 'card revision overflow action',
    'manual_reader_cover_thumbnail.php' => 'authoritative front-page thumbnails',
    '/admin/books_manuals/reader.php' => 'modal read-only page viewer',
    'bm-lifecycle-track' => 'four-stage lifecycle progress',
    'data-bm-settings-toggle' => 'manual settings modal',
    '+ Add person as reviewer' => 'book reviewer assignment',
) as $needle => $label) {
    bm_contract_assert($label, str_contains($libraryPage, $needle));
}
bm_contract_assert(
    'modal book reader exposes pages without publishing controls',
    str_contains($bookReader, "action=stored_preview")
        && !str_contains($bookReader, 'cpp-generate')
        && !str_contains($bookReader, 'cpp-approve')
        && !str_contains($bookReader, 'Back to Editor')
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
    "'requirements' =>" => 'live MCCF requirement details',
    'MCCF items requiring attention' => 'visible audit gap list',
    'coverage_state' => 'missing and insufficient filters',
    '/admin/compliance/mccf_browser.php?' => 'deep links into MCCF requirements',
    "'req' => (int)\$requirement['id']" => 'requirement-specific MCCF links',
) as $needle => $label) {
    bm_contract_assert(
        $label,
        str_contains($needle === "'requirements' =>" ? $audit : $auditPage, $needle)
    );
}
bm_contract_assert(
    'audit identifies the BCAA submission MCCF source version',
    str_contains($audit, "'required_source_sets' =>")
        && str_contains($auditPage, 'BCAA MCCF version')
        && str_contains($auditPage, 'View BCAA Submission MCCF')
        && str_contains($auditPage, "'layout' => 'bcaa'")
);
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
