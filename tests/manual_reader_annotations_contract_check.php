<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$failures = array();

function annotation_require(string $path, array $markers, array &$failures): void
{
    $source = is_file($path) ? (string)file_get_contents($path) : '';
    foreach ($markers as $marker) {
        if (!str_contains($source, $marker)) {
            $failures[] = basename($path) . ' missing: ' . $marker;
        }
    }
}

annotation_require(
    $root . '/scripts/sql/2026_08_16_manual_reader_annotations.sql',
    array(
        'can_manual_reviewer',
        'ipca_manual_reader_annotations',
        'ipca_manual_reader_review_threads',
        'ipca_manual_reader_review_comments',
        'regulation_reference_json',
    ),
    $failures
);
annotation_require(
    $root . '/src/publishing/ControlledPublishingReaderAccessService.php',
    array(
        'public function canReviewManuals',
        "if (\$role === 'admin')",
        "array('instructor', 'supervisor', 'chief_instructor')",
        "can_manual_reviewer",
    ),
    $failures
);
annotation_require(
    $root . '/public/student/api/manual_reader_api.php',
    array(
        "case 'annotations_pull':",
        "case 'annotations_push':",
        "case 'review_threads':",
        "case 'review_thread_create':",
        "case 'review_comment_add':",
        'Reviewer approval required.',
    ),
    $failures
);
annotation_require(
    $root . '/ipca-manual-reader-ios/IPCAManualReader/Views/ReaderView.swift',
    array(
        'ReaderSelectionActionMenu',
        'HighlightColorMenu',
        'PersonalNoteEditorSheet',
        'ReviewerConversationSheet',
        '"Remove Highlight"',
        '"Add Personal Note"',
        '"Add Reviewer Note"',
        '"Confirm you want to delete your personal note?"',
        'shareAnnexPDF',
    ),
    $failures
);
annotation_require(
    $root . '/ipca-manual-reader-ios/IPCAManualReader/Views/LibraryView.swift',
    array(
        '"Highlighted text"',
        '"Personal note"',
        '"Open in Book"',
        '"Confirm you want to delete your personal note?"',
        'initialHighlight: openingHighlight',
    ),
    $failures
);
annotation_require(
    $root . '/ipca-manual-reader-ios/IPCAManualReader/ViewModels/ReaderViewModels.swift',
    array(
        'func goToHighlight',
        'refreshAnnotationHTML(at: currentIndex)',
    ),
    $failures
);
annotation_require(
    $root . '/ipca-manual-reader-ios/IPCAManualReader/Views/ManualPageWebView.swift',
    array(
        'private final class ReaderWebView',
        'canPerformAction',
        'existingHighlightID',
        'opensPersonalNote',
    ),
    $failures
);
annotation_require(
    $root . '/ipca-manual-reader-ios/IPCAManualReader/Services/ManualReaderSessionStore.swift',
    array(
        'func syncAnnotations',
        'annotationMutationRevision',
        'pendingReviewNotes',
        'queueReviewNote',
    ),
    $failures
);
annotation_require(
    $root . '/ipca-manual-reader-ios/IPCAManualReader/Services/ManualReaderAPIClient.swift',
    array(
        'URLComponents(string: path)',
        'relative.queryItems',
        'mr-personal-note-marker',
        'opensPersonalNote: true',
    ),
    $failures
);
annotation_require(
    $root . '/public/student/api/manual_reader_annex_pdf.php',
    array(
        'application/pdf',
        'section_page_index',
        'render_annex_pdf.cjs',
    ),
    $failures
);
annotation_require(
    $root . '/public/assets/controlled_book_editor.js',
    array(
        'loadReviewThreadMarkers',
        'showReviewThreadPanel',
        'cpb-review-thread-pin',
    ),
    $failures
);

if ($failures !== array()) {
    fwrite(STDERR, "Manual reader annotations contract: FAIL\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}

echo "Manual reader annotations contract: PASS\n";
