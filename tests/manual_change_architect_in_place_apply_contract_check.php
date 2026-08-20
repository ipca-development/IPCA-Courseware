<?php
declare(strict_types=1);

/** @param mixed $condition */
function wizard_apply_assert($condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__);
$service = file_get_contents($root . '/src/publishing/BooksManualsChangeApplyService.php');
$architectApi = file_get_contents($root . '/public/admin/api/books_manuals_change_architect_api.php');
$editorApi = file_get_contents($root . '/public/admin/api/controlled_book_editor_api.php');
$editorPage = file_get_contents($root . '/public/admin/compliance/controlled_book_editor.php');
$editorJs = file_get_contents($root . '/public/assets/controlled_book_editor.js');
$wizardPage = file_get_contents($root . '/public/admin/books_manuals/change_architect.php');

foreach (array($service, $architectApi, $editorApi, $editorPage, $editorJs, $wizardPage) as $source) {
    wizard_apply_assert(is_string($source), 'A required in-place application file is missing.');
}

wizard_apply_assert(
    !preg_match('/INSERT\s+INTO\s+ipca_publishing_book_versions/i', $service),
    'Wizard apply must never create a book version.'
);
wizard_apply_assert(
    !preg_match('/UPDATE\s+ipca_publishing_book_versions[\s\S]{0,300}(?:lifecycle_status|version_label|revision)/i', $service),
    'Wizard apply must never transition or renumber a book version.'
);
wizard_apply_assert(
    str_contains($service, "array('draft', 'in_review')")
        && str_contains($service, 'primaryVersionId($plan)')
        && str_contains($service, 'book_version_id'),
    'Wizard apply must target only the originally selected Draft or Draft Review version.'
);
wizard_apply_assert(
    str_contains($service, 'beginTransaction()')
        && str_contains($service, 'commit()')
        && str_contains($service, 'rollBack()'),
    'Wizard apply and undo must be transactional.'
);
wizard_apply_assert(
    str_contains($service, 'WIZARD_APPLICATION_STALE_CLAIM_RECOVERED')
        && str_contains($service, '|attempt:')
        && str_contains($service, '$claimedOperation')
        && str_contains($service, 'Stale Wizard application claim recovered safely.'),
    'Apply claims must prevent duplicate-key failures and recover abandoned running operations.'
);
wizard_apply_assert(
    str_contains($service, 'context_hash')
        && str_contains($service, 'assertTargetsUnchanged')
        && str_contains($service, 'buildPreflightPackage')
        && str_contains($service, 'independently_reviewed_package')
        && str_contains($service, 'The selected manual changed after impact analysis'),
    'Wizard apply must build and revalidate the independently reviewed operation package.'
);
wizard_apply_assert(
    str_contains($service, 'before_sections')
        && str_contains($service, 'after_sections')
        && str_contains($service, 'restoreSectionSnapshot')
        && str_contains($service, 'WIZARD_EDIT_UNDONE'),
    'Wizard operations must retain exact reversible snapshots and immutable undo audit.'
);
wizard_apply_assert(
    str_contains($service, 'Undo refused: manual content changed after the Wizard application')
        && str_contains($service, 'later_manual_edits_overwritten') ,
    'Undo must refuse to overwrite manual edits made after Wizard application.'
);
wizard_apply_assert(
    str_contains($service, 'regenerateHighlightsSection')
        && str_contains($service, 'regenerateTocSection')
        && str_contains($service, 'syncUpdateIdentity')
        && str_contains($service, 'ControlledPublishingLivePageMapService')
        && str_contains($service, 'startIntegrityRefresh'),
    'Apply and undo must regenerate controlled identity, highlights, TOC, pagination, and MCCF content.'
);
wizard_apply_assert(
    str_contains($service, "save('edit_events'")
        && str_contains($service, 'AuditEventService')
        && str_contains($service, 'manual_change_architect.undo_wizard_edit'),
    'Apply and undo must append Architect edit events and global audit records.'
);
wizard_apply_assert(
    str_contains($architectApi, '$apply->apply($planId, $userId)')
        && str_contains($architectApi, "\$result['book_version_id']")
        && !str_contains($architectApi, "\$result['working_revision_id']"),
    'Step 6 must redirect to the same edited version, not a cloned working revision.'
);
wizard_apply_assert(
    str_contains($architectApi, 'architect_api_prepare_independent_review')
        && str_contains($architectApi, 'verifyReadableAmendmentProposal')
        && str_contains($architectApi, 'evaluateGovernanceGate'),
    'Accepted wording must receive an independent review package before Step 6.'
);
wizard_apply_assert(
    str_contains($editorPage, 'Undo Wizard Edit')
        && str_contains($editorApi, "case 'undo_wizard_edit'")
        && str_contains($editorJs, "apiPost('undo_wizard_edit'"),
    'The Editor must expose the guarded server-authoritative Undo Wizard Edit action.'
);
wizard_apply_assert(
    str_contains($service, 'editorChanges(')
        && str_contains($service, 'revertEditorChange(')
        && str_contains($service, "'operation_type' => 'revert_wizard_change_item'")
        && str_contains($service, 'expectedCurrentFingerprint')
        && str_contains($service, "'derived_sections_refreshed'")
        && str_contains($service, "'overwritten_section'")
        && str_contains($editorApi, "case 'wizard_changes'")
        && str_contains($editorApi, "case 'revert_wizard_change'")
        && str_contains($editorPage, 'cpbWizardChanges')
        && str_contains($editorJs, 'data-cpb-revert-wizard-change'),
    'The Editor must expose independently guarded per-section Wizard reverts.'
);
wizard_apply_assert(
    str_contains($service, "'review_guidance' => \$this->reviewGuidance")
        && str_contains($service, "'editor_action_required' => false")
        && str_contains($editorJs, 'Independent Review guidance'),
    'Governed review answers must be carried into the Editor sidebar without another Author pass.'
);
wizard_apply_assert(
    str_contains($wizardPage, 'No revision will be created, incremented, approved or transitioned')
        && str_contains($wizardPage, 'Apply Accepted Wizard Changes')
        && str_contains($wizardPage, 'Open Updated Manual in Editor'),
    'Step 6 must clearly describe in-place application and return to the same Editor version.'
);

echo "Manual Change Architect in-place apply contract checks passed.\n";
