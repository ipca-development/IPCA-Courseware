<?php
declare(strict_types=1);

$root = dirname(__DIR__);

function annex_book_assert(string $label, bool $condition): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
    echo "PASS: {$label}\n";
}

$annexBook = (string)file_get_contents($root . '/src/publishing/BooksManualsAnnexBookService.php');
$annex = (string)file_get_contents($root . '/src/publishing/ControlledPublishingAnnexService.php');
$workflow = (string)file_get_contents($root . '/src/publishing/BooksManualsWorkflowService.php');
$nav = (string)file_get_contents($root . '/src/publishing/ControlledPublishingEditorNavService.php');
$reader = (string)file_get_contents($root . '/src/publishing/ControlledPublishingReaderService.php');
$editorApi = (string)file_get_contents($root . '/public/admin/api/controlled_book_editor_api.php');
$annexApi = (string)file_get_contents($root . '/public/admin/api/controlled_book_annex_api.php');
$annexManager = (string)file_get_contents($root . '/public/admin/compliance/controlled_book_annexes.php');
$annexPage = (string)file_get_contents($root . '/public/admin/books_manuals/annexes.php');
$editorPage = (string)file_get_contents($root . '/public/admin/compliance/controlled_book_editor.php');
$editorJs = (string)file_get_contents($root . '/public/assets/controlled_book_editor.js');
$migration = (string)file_get_contents($root . '/src/publishing/BooksManualsAnnexMigrationService.php');
$sql = (string)file_get_contents($root . '/scripts/sql/2026_08_18_annex_book_structure.sql');
$apply = (string)file_get_contents($root . '/scripts/apply_annex_book_structure.php');
$foundation = (string)file_get_contents($root . '/src/publishing/ControlledPublishingFoundationService.php');

annex_book_assert(
    '1:1 annex book map table',
    str_contains($sql, 'ipca_publishing_annex_book_map')
        && str_contains($sql, 'UNIQUE KEY uk_ipca_pub_annex_map_parent')
);
annex_book_assert(
    'append-only annex revision log',
    str_contains($sql, 'ipca_publishing_annex_revisions')
        && str_contains($sql, 'revision_from')
        && str_contains($sql, 'revision_to')
        && str_contains($sql, 'actor_name')
);
annex_book_assert(
    'apply script creates annex book tables',
    str_contains($apply, '2026_08_18_annex_book_structure.sql')
        && str_contains($apply, 'ipca_publishing_annex_book_map')
);
annex_book_assert(
    'Annex Book type and cover title',
    str_contains($annexBook, "BOOK_TYPE = 'annex_book'")
        && str_contains($annexBook, 'Annexes Manual ')
        && str_contains($annexBook, 'syncCoverFromParent')
        && str_contains($annexBook, 'scaffoldAnnexBookSections')
);
annex_book_assert(
    'Annex Book outline is cover + register + annexes',
    str_contains($annexBook, "'cover'")
        && str_contains($annexBook, 'PARENT_SECTION_KEY')
        && str_contains($annexBook, 'ensureAnnexInfrastructure')
);
annex_book_assert(
    'annex books skip Cross Ref and Highlights infrastructure',
    str_contains($annex, 'isAnnexBookVersion($version)')
        && str_contains($annex, 'CROSS_REF_SECTION_KEY')
);
annex_book_assert(
    'automatic per-annex revision logging',
    str_contains($annex, 'function recordAnnexRevision')
        && str_contains($annex, 'function touchAnnexContentRevision')
        && str_contains($annex, 'function nextAnnexRevisionLabel')
        && str_contains($annex, "'create'")
        && str_contains($annex, "'reimport'")
        && str_contains($annex, "'content_update'")
        && str_contains($annex, "'delete'")
        && str_contains($annex, "'restore'")
);
annex_book_assert(
    'authored annex revision_date is preserved on content save',
    str_contains($annex, 'normalizeAnnexRevisionDate')
        && str_contains($annex, '$authoredDate')
        && !str_contains($annex, "'revision_date' => date('Y-m-d')")
);
annex_book_assert(
    'soft-delete and restore annexes without removing blocks',
    str_contains($annex, 'function softDeleteAnnex')
        && str_contains($annex, 'function restoreAnnex')
        && str_contains($annex, "'deleted_at'")
        && str_contains($annex, 'function isAnnexDeleted')
        && str_contains($annex, 'includeDeleted')
);
annex_book_assert(
    'editor records annex revisions without changing the annex editor UI',
    str_contains($editorApi, 'cp_editor_touch_annex_revision')
        && str_contains($editorApi, 'cp_editor_sync_annex_cover_from_parent')
);
annex_book_assert(
    'Annex Book editor nav is cover then annexes only',
    str_contains($nav, 'isAnnexBookVersion($version)')
        && str_contains($nav, 'formatAnnexSubsections(')
        && str_contains($nav, 'parentHasAnnexBook')
);
annex_book_assert(
    'parent manuals hide annex family once an Annex Book exists',
    str_contains($nav, 'hideParentAnnexes')
);
annex_book_assert(
    'annex book cover is a real cover section',
    str_contains($reader, 'BooksManualsAnnexBookService::isAnnexBookVersion')
        && str_contains($foundation, 'b.book_type')
);
annex_book_assert(
    'admin Annexes page lists Annex Books not standalone annexes',
    str_contains($annexPage, 'ensure_annex_book')
        && str_contains($annexPage, 'Create Annex Book')
        && str_contains($annexPage, 'controlled_book_annexes.php')
        && !str_contains($annexPage, 'Create a standalone annex')
);
annex_book_assert(
    'library lists 1:1 annex books',
    str_contains($workflow, "b.book_type = 'annex_book'")
        && str_contains($workflow, 'annex_book_map')
);
annex_book_assert(
    'migration copies embedded annexes into the parent Annex Book',
    str_contains($migration, 'ensureAnnexBookForParent')
        && str_contains($migration, 'copySectionShell')
        && str_contains($migration, "['canonical_excerpt_id'] = null")
        && str_contains($migration, 'legacy_section_preserved')
);

annex_book_assert(
    'soft-delete lives only on Manage Annexes',
    str_contains($annexApi, "action === 'soft_delete'")
        && str_contains($annexApi, "action === 'restore'")
        && str_contains($annexManager, 'cp-annex-delete-btn')
        && str_contains($annexManager, 'cp-annex-restore-btn')
        && str_contains($annexManager, 'Show deleted annexes')
        && str_contains($annexManager, 'name="revision_date"')
        && !str_contains($editorApi, 'soft_delete')
        && !str_contains($editorApi, 'restoreAnnex')
);
annex_book_assert(
    'manage annexes uses hero New Annex plus rename and revert modals',
    str_contains($annexManager, '+ New Annex')
        && str_contains($annexManager, 'cp-annex-create-modal')
        && str_contains($annexManager, 'cp-annex-rename-modal')
        && str_contains($annexManager, 'cp-annex-revert-modal')
        && str_contains($annexManager, 'Rename Annex')
        && str_contains($annexManager, 'action=revisions')
        && str_contains($annexApi, "action === 'revisions'")
        && str_contains($annexApi, "action === 'revert'")
        && str_contains($annex, 'function revertAnnexToRevision')
        && str_contains($annex, 'function captureAnnexSnapshot')
);
annex_book_assert(
    'Manage Annexes uses compact actions and content symbols',
    str_contains($annexManager, 'cp-annex-list-table')
        && str_contains($annexManager, 'cp-annex-row-actions')
        && str_contains($annexManager, 'contentModeSymbol')
        && str_contains($annexManager, 'orientationSymbol')
        && str_contains($annexManager, 'cp-annex-col-date')
        && str_contains($annexManager, 'overflow-wrap: normal')
        && str_contains($annexManager, '@container (max-width: 560px)')
        && str_contains($annexManager, 'cp-annex-column-resizer')
        && str_contains($annexManager, 'columnWidthStorageKey')
        && str_contains($annexManager, 'ipca.manageAnnexes.user.')
        && str_contains($annexManager, 'grid-template-columns: repeat(4, minmax(0, 1fr))')
        && str_contains($annexManager, 'Reset columns')
);
annex_book_assert(
    'Annex Editor keeps editable toolbars and omits outline controls',
    str_contains($editorPage, 'data-annex-book=')
        && str_contains($editorPage, 'if (!$isAnnexBook)')
        && str_contains($editorApi, "'is_annex_book' => BooksManualsAnnexBookService::isAnnexBookVersion(\$version)")
        && str_contains($editorJs, '(isReleased && !isAnnexBook)')
        && str_contains($editorJs, "documentType !== 'form' && !isAnnexBook")
);
annex_book_assert(
    'Governance action is removed from the controlled editor',
    !str_contains($editorPage, "'label' => 'Governance'")
);
annex_book_assert(
    'annex revision snapshots are additive',
    str_contains($sql, 'ipca_publishing_annex_revisions')
        && str_contains((string)file_get_contents($root . '/scripts/sql/2026_08_18_annex_revision_snapshots.sql'), 'snapshot_json')
        && str_contains($apply, '2026_08_18_annex_revision_snapshots.sql')
        && str_contains($annex, "'revert'")
);
annex_book_assert(
    'Annex Books publish or unpublish without the manual review cycle',
    str_contains($workflow, 'function transitionAnnexBook')
        && str_contains($workflow, "'action' => 'publish_annex'")
        && str_contains($workflow, "'action' => 'unpublish_annex'")
        && str_contains($workflow, "return \$status === 'released' ? 'PUBLISHED' : 'NOT PUBLISHED'")
        && str_contains($foundation, 'function releaseAnnexBookVersion')
        && str_contains($foundation, 'ensureAnnexBookPageMapApproved')
        && str_contains($foundation, 'ensureAnnexBookReleaseBaseline')
        && str_contains($foundation, "'annex_book_authored_publication'")
        && str_contains($foundation, "\$version['released_at'] = \$releaseAt")
        && str_contains($foundation, 'released_at = :released_at')
        && str_contains($foundation, "':released_at' => \$releaseAt")
        && str_contains($reader, 'function ensureAnnexBookPageMapApproved')
        && str_contains($annexBook, 'function allowsReleasedEdits')
        && str_contains($annexPage, 'name="action" value="transition"')
        && str_contains($annexPage, 'lifecycle_action')
);

echo "Books & Manuals Annex Book contracts: PASS\n";
