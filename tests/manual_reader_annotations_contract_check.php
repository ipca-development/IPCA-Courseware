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
        'ReviewerNotesLibraryView',
        'canReviewManuals: session.canAddReviewerNotes',
        'selectedItem = item',
        'ReviewerConversationSheet(',
        'onOpenInBook:',
    ),
    $failures
);
annotation_require(
    $root . '/ipca-manual-reader-ios/IPCAManualReader/ViewModels/ReaderViewModels.swift',
    array(
        'func goToHighlight',
        'refreshAnnotationHTML(at: currentIndex)',
        'showLoading: Bool = true',
        'reportErrors: Bool = true',
        'let projectionChanged = existingProjection != incomingProjection',
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
        'annotationTextNodes',
        'boundaryOffset',
        'opensReviewerNote',
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
        'mr-review-highlight',
        'mr-review-note-marker',
        'width: 21px',
        'const reviewMarkerSize = 21',
        'reviewThreads.forEach',
        'wrapTextPiece',
        'mark.dataset.annotationFragment',
    ),
    $failures
);
annotation_require(
    $root . '/tests/manual_reader_annotation_dom_check.js',
    array(
        'Cross-node selection must create multiple fragments',
        'assert(!annotationHelpers.includes("surroundContents"))',
        'assert(!annotationHelpers.includes("extractContents"))',
        'Manual reader annotation DOM check: PASS',
    ),
    $failures
);

$librarySource = (string)file_get_contents(
    $root . '/ipca-manual-reader-ios/IPCAManualReader/Views/LibraryView.swift'
);
if (str_contains($librarySource, 'onOpen(item.book, item.thread)')) {
    $failures[] = 'Reviewer Notes rows must open chat first, not open the book directly.';
}
$readerViewSource = (string)file_get_contents(
    $root . '/ipca-manual-reader-ios/IPCAManualReader/Views/ReaderView.swift'
);
$reviewerSheetStart = strpos($readerViewSource, 'struct ReviewerConversationSheet');
$reviewerSheetSource = $reviewerSheetStart !== false
    ? substr($readerViewSource, $reviewerSheetStart)
    : '';
foreach (array(
    'loadReviewThreads(showLoading: false, reportErrors: false)',
    'Task.sleep(for: .seconds(5))',
    'ReviewNoteTimestampFormatter.display(comment.createdAtUTC)',
    'ScrollViewReader { proxy in',
    'proxy.scrollTo("review-conversation-bottom", anchor: .bottom)',
    '.onChange(of: latestReviewerMessageIdentity)',
) as $liveReviewMarker) {
    if (!str_contains($readerViewSource, $liveReviewMarker)) {
        $failures[] = "iOS live reviewer synchronization missing: {$liveReviewMarker}";
    }
}
foreach (array(
    'await load(showLoading: false)',
    'ReviewNoteTimestampFormatter.display(',
    'self.selectedItem = refreshed',
) as $librarySyncMarker) {
    if (!str_contains($librarySource, $librarySyncMarker)) {
        $failures[] = "Reviewer Notes library synchronization missing: {$librarySyncMarker}";
    }
}
foreach (array('Button("Edit")', 'Button("Delete")') as $mutableCommentControl) {
    if (str_contains($reviewerSheetSource, $mutableCommentControl)) {
        $failures[] = "Reviewer comments must remain append-only: {$mutableCommentControl}";
    }
}

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
        'reviewThreadTextRange',
        'showReviewThreadPanel',
        'cpb-review-thread-pin',
        "CSS.highlights.set('cpb-review-remarks', new Highlight(...reviewRanges))",
        "pin.textContent = '•••'",
        'scheduleReviewThreadSync',
        'refreshOpenReviewThread',
        'reviewCommentTimestamp',
        'data-thread-uuid',
        'messages.scrollTop = messages.scrollHeight',
    ),
    $failures
);
annotation_require(
    $root . '/public/assets/controlled_book_editor.css',
    array(
        '::highlight(cpb-review-remarks)',
        '.cpb-review-thread-panel__regulation',
        '.cpb-review-thread-message time',
        'width: min(644px, calc(100vw - 64px))',
        'height: min(720px, calc(100vh - 96px))',
        'grid-template-rows: auto minmax(0, 1fr) auto',
    ),
    $failures
);

if ($failures !== array()) {
    fwrite(STDERR, "Manual reader annotations contract: FAIL\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}

echo "Manual reader annotations contract: PASS\n";
