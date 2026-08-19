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
$foundation = (string)file_get_contents(
    $root . '/src/publishing/ControlledPublishingFoundationService.php'
);
$manualStructure = (string)file_get_contents(
    $root . '/src/publishing/ControlledPublishingManualStructureService.php'
);
$policy = (string)file_get_contents($root . '/src/publishing/BooksManualsReaderPolicyService.php');
$readerService = (string)file_get_contents(
    $root . '/src/publishing/ControlledPublishingReaderService.php'
);
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
$annexBook = (string)file_get_contents(
    $root . '/src/publishing/BooksManualsAnnexBookService.php'
);
$annexBookSql = (string)file_get_contents(
    $root . '/scripts/sql/2026_08_18_annex_book_structure.sql'
);
$migration = (string)file_get_contents(
    $root . '/scripts/sql/2026_08_18_books_manuals_workflow.sql'
);
$libraryMigration = (string)file_get_contents(
    $root . '/scripts/sql/2026_08_18_books_manuals_library_ux.sql'
);
$overrideMigration = (string)file_get_contents(
    $root . '/scripts/sql/2026_08_18_books_manuals_compliance_overrides.sql'
);
$readerApi = (string)file_get_contents($root . '/public/student/api/manual_reader_api.php');
$editorApi = (string)file_get_contents($root . '/public/admin/api/controlled_book_editor_api.php');
$editorJs = (string)file_get_contents($root . '/public/assets/controlled_book_editor.js');
$legacyVersionPage = (string)file_get_contents(
    $root . '/public/admin/compliance/controlled_book_version.php'
);
$architectApply = (string)file_get_contents(
    $root . '/scripts/apply_manual_change_architect_sms_working_revision.php'
);
$nav = (string)file_get_contents($root . '/src/nav/admin.php');
$libraryPage = (string)file_get_contents($root . '/public/admin/books_manuals/index.php');
$manualPage = (string)file_get_contents($root . '/public/admin/books_manuals/manual.php');
$bookReader = (string)file_get_contents($root . '/public/admin/books_manuals/reader.php');
$changeAssistant = (string)file_get_contents(
    $root . '/src/publishing/BooksManualsChangeAssistantService.php'
);
$changeProjects = (string)file_get_contents(
    $root . '/public/admin/books_manuals/change_projects.php'
);
$changeProject = (string)file_get_contents(
    $root . '/public/admin/books_manuals/change_project.php'
);
$iosModels = (string)file_get_contents(
    $root . '/ipca-manual-reader-ios/IPCAManualReader/Models/ManualReaderModels.swift'
);
$iosLibrary = (string)file_get_contents(
    $root . '/ipca-manual-reader-ios/IPCAManualReader/Views/LibraryView.swift'
);
$iosViewModel = (string)file_get_contents(
    $root . '/ipca-manual-reader-ios/IPCAManualReader/ViewModels/ReaderViewModels.swift'
);

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

bm_contract_assert(
    'successor revision creation requires governed web authorization',
    str_contains($foundation, 'authorizeNextDraftVersionCreation')
        && str_contains($foundation, 'consumeRevisionCloneAuthorization')
        && str_contains($foundation, "PHP_SAPI === 'cli'")
        && str_contains($workflow, 'authorizeNextDraftVersionCreation')
        && str_contains($workflow, '$authorization')
);
bm_contract_assert(
    'legacy revision action uses the governed workflow',
    str_contains($legacyVersionPage, '$workflowSvc->createRevision(')
        && !str_contains($legacyVersionPage, '$svc->createNextDraftVersion(')
);
bm_contract_assert(
    'Manual Change Architect apply utility is permanently read-only',
    str_contains($architectApply, 'This Manual Change Architect utility is permanently read-only.')
        && str_contains($architectApply, 'if (!$preflightOnly)')
);

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
        && strpos($policy, "if (strtolower((string)(\$user['role'] ?? '')) === 'admin')")
            < strpos($policy, "if (\$status === 'draft')")
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
bm_contract_assert(
    'AI Manual Change Assistant surface is connected to Books & Manuals',
    str_contains($nav, "'label' => 'Change Projects'")
        && str_contains($libraryPage, 'AI Change Assistant')
        && str_contains($changeProjects, 'New Change Project')
        && str_contains($changeProject, 'Impact Finder')
);
bm_contract_assert(
    'AI analysis is advisory while controlled apply remains draft-only',
    str_contains($changeAssistant, 'Only Draft versions can receive an AI change set')
        && str_contains($changeAssistant, 'createDraftRevisionForScope')
        && str_contains($changeAssistant, 'expected_block_hash')
        && str_contains($changeAssistant, 'source_fingerprint')
);
foreach (array(
    "array('label' => '+', 'modal' => 'bm-create-manual')" => 'hero plus-button creation',
    'Create NEW Revision Draft' => 'card revision overflow action',
    'manual_reader_cover_thumbnail.php' => 'authoritative front-page thumbnails',
    '/admin/books_manuals/reader.php' => 'modal read-only page viewer',
    'bm-lifecycle-track' => 'four-stage lifecycle progress',
    'data-bm-settings-toggle' => 'manual settings modal',
    '+ Add person as reviewer' => 'book reviewer assignment',
    'Previous Versions (' => 'previous versions card action',
    'bm-version-history__cover' => 'previous version thumbnail list',
    'Open Read-Only Version' => 'previous version read-only viewer action',
) as $needle => $label) {
    bm_contract_assert($label, str_contains($libraryPage, $needle));
}
bm_contract_assert(
    'previous version history excludes the current version and includes approval evidence',
    str_contains($workflow, 'listPreviousVersions')
        && str_contains($workflow, 'AND bv.id <> ?')
        && str_contains($workflow, 'bv.released_at')
        && str_contains($workflow, 'approved_by_name')
        && str_contains($workflow, 'audit.coverage_percent')
);
bm_contract_assert(
    'hero approved count includes historical released versions',
    str_contains($libraryPage, '$hasApprovedVersion = $status === \'released\'')
        && str_contains(
            $libraryPage,
            "(string)(\$previousVersion['lifecycle_status'] ?? '') === 'released'"
        )
);
bm_contract_assert(
    'reader advertises only stored page maps',
    str_contains($readerService, '$hasStoredPageMap = false')
        && str_contains($readerService, '? $hasStoredPageMap')
);
bm_contract_assert(
    'revision page-map state is version-specific and generated on creation',
    str_contains($foundation, "unset(\$sourceMeta['reader_page_map'])")
        && str_contains($workflow, 'refreshRevisionHighlights')
        && str_contains($workflow, 'queueInitialPageMap')
        && str_contains($workflow, "'mutation_kind' => 'create_revision'")
);
bm_contract_assert(
    'legacy approval override safely binds its existing page map to the released package',
    str_contains($workflow, 'approveStoredPageMapUnderOverride')
        && str_contains($workflow, "'approval_basis' => 'legacy_compliance_override'")
        && str_contains($workflow, "'approval_override_uuid' => \$overrideUuid")
        && str_contains($workflow, 'paginationPublicationPackage')
        && str_contains($workflow, "\$generation['manifest_hash']")
        && str_contains($workflow, 'hash_equals($frozenStyleHash, $currentStyleHash)')
);
bm_contract_assert(
    'iOS distinguishes approved and review versions',
    str_contains($iosModels, 'var isDraftPreview: Bool')
        && str_contains($iosLibrary, 'case "released": "Approved"')
        && str_contains($iosLibrary, 'case "in_review": "Draft Review"')
        && str_contains($iosLibrary, '.font(.system(size: 9, weight: .bold))')
        && str_contains($iosLibrary, 'Text("Revision \(book.versionLabel)")')
);
bm_contract_assert(
    'iOS Annexes tab lists annex books without revision chrome',
    str_contains($iosModels, 'case isAnnexBookFlag = "is_annex_book"')
        && str_contains($iosModels, 'var isAnnexBook: Bool')
        && str_contains($iosLibrary, 'case annexes = "Annexes"')
        && str_contains($iosLibrary, 'Label("Annexes", systemImage: "doc.on.doc")')
        && str_contains($iosLibrary, 'showsRevisionMetadata: false')
        && str_contains($iosLibrary, 'if showsRevisionMetadata')
        && str_contains($readerService, "'is_annex_book' => \$isAnnexBook")
);
bm_contract_assert(
    'iOS Annexes tab lists only published annex books',
    str_contains($iosLibrary, 'books.filter { $0.isAnnexBook && !$0.isDraftPreview }')
        && str_contains($readerService, 'if (!$includeDraftPreview || $isAnnexBook)')
);
bm_contract_assert(
    'iOS landscape annex pages use landscape page geometry',
    str_contains($iosModels, 'var isLandscapePage: Bool')
        && str_contains($iosViewModel, 'publicationLayout.landscapeSized')
        && str_contains(
            (string)file_get_contents($root . '/ipca-manual-reader-ios/IPCAManualReader/Views/ReaderView.swift'),
            'pageIsLandscape'
        )
        && str_contains(
            (string)file_get_contents($root . '/ipca-manual-reader-ios/IPCAManualReader/Services/ReaderPaginationCore.js'),
            'function layoutForSection'
        )
);
bm_contract_assert(
    'Annex Books skip the four-phase review and publish or unpublish',
    str_contains($workflow, 'function transitionAnnexBook')
        && str_contains($workflow, 'publish_annex')
        && str_contains($workflow, 'unpublish_annex')
        && str_contains($workflow, "Annex Books can only be Published or Unpublished.")
        && str_contains($foundation, 'function releaseAnnexBookVersion')
        && str_contains($foundation, 'ensureAnnexBookPageMapApproved')
        && str_contains($foundation, 'ensureAnnexBookReleaseBaseline')
        && str_contains($foundation, 'source_baseline_id = ?')
        && str_contains($annexBook, 'function allowsReleasedEdits')
        && str_contains($manualPage, 'Annex Books are Published or not Published')
        && str_contains($manualPage, 'if (!$isAnnexBook)')
);
bm_contract_assert(
    'iOS reviewer notes stay disabled for released manuals',
    substr_count($iosViewModel, 'book.isDraftPreview') >= 3
        && str_contains(
            $iosViewModel,
            'ManualReaderSessionStore.shared.canAddReviewerNotes && book.isDraftPreview'
        )
);
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
bm_contract_assert(
    'bulk override requires rationale and creates a new revision',
    str_contains($workflow, 'createRevisionWithBulkOverride')
        && str_contains($workflow, 'strlen($rationale) < 20')
        && str_contains($workflow, "'bulk_override_legacy_approval'")
        && str_contains($workflow, 'createRevision($sourceVersionId, $actorUserId)')
);
bm_contract_assert(
    'revision cloning safely reuses source selection transaction',
    str_contains($foundation, '$ownsTransaction = !$this->pdo->inTransaction()')
        && str_contains($foundation, 'if ($ownsTransaction && $this->pdo->inTransaction())')
);
bm_contract_assert(
    'revision cloning repairs legacy nested MAIN chapter content',
    str_contains($foundation, 'repairClonedNestedMainChapters')
        && str_contains($manualStructure, 'repairNestedMainChapterSections')
        && str_contains($manualStructure, 'nestedChapterBlockStart')
);
bm_contract_assert(
    'bulk override captures all blockers immutably',
    str_contains($overrideMigration, 'ipca_publishing_compliance_overrides')
        && str_contains($overrideMigration, 'blockers_json JSON NOT NULL')
        && str_contains($workflow, "'all_release_blockers'")
        && str_contains($workflow, "'gap_items' => \$gapItems")
);
bm_contract_assert(
    'bulk override repairs required safety evidence before release',
    str_contains($workflow, 'ensureOverridePageMapStylesCurrent')
        && str_contains($workflow, "'legacy_override_style_refresh'")
        && str_contains($workflow, 'freezeSourceBaseline($sourceVersionId, $actorUserId)')
        && str_contains($workflow, 'Wait for generation to complete')
);
bm_contract_assert(
    'editor refreshes revision bars and change list after saves',
    str_contains($editorApi, '$revision->annotateChangeStatus')
        && str_contains($editorJs, 'syncBlockChangePresentation')
        && str_contains($editorJs, 'scheduleAutomaticHighlights')
        && str_contains($editorJs, "apiPost('regenerate_highlights'")
);
bm_contract_assert(
    'library exposes one confirmed override-all action',
    str_contains($libraryPage, 'Override All Blockers &amp; Create Draft')
        && str_contains($libraryPage, 'name="override_rationale"')
        && str_contains($libraryPage, 'minlength="20"')
);
bm_contract_assert(
    'compliance audit displays override rationale and identity',
    str_contains($audit, 'listComplianceOverrides')
        && str_contains($auditPage, 'Compliance overrides')
        && str_contains($auditPage, "override['rationale']")
        && str_contains($auditPage, "override['override_uuid']")
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
bm_contract_assert(
    'one Annex Book per parent with revision log',
    str_contains($annexBook, "BOOK_TYPE = 'annex_book'")
        && str_contains($annexBookSql, 'ipca_publishing_annex_book_map')
        && str_contains($annexBookSql, 'ipca_publishing_annex_revisions')
);

echo "Books & Manuals workflow contracts: PASS\n";
