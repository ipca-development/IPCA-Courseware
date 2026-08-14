<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$reportPath = $root . '/docs/qa/controlled-book-editor-44df02b9-parity.md';
$currentPath = $root . '/public/assets/controlled_book_editor.js';
$currentCssPath = $root . '/public/assets/controlled_book_editor.css';
$editorShellPath = $root . '/public/admin/compliance/controlled_book_editor.php';
$editorApiPath = $root . '/public/admin/api/controlled_book_editor_api.php';
$report = (string)file_get_contents($reportPath);
$current = (string)file_get_contents($currentPath);
$baseline = (string)shell_exec(
    'cd ' . escapeshellarg($root)
    . ' && git show 44df02b9:public/assets/controlled_book_editor.js 2>/dev/null'
);
$baselineCss = (string)shell_exec(
    'cd ' . escapeshellarg($root)
    . ' && git show 44df02b9:public/assets/controlled_book_editor.css 2>/dev/null'
);
$baselineEditorShell = (string)shell_exec(
    'cd ' . escapeshellarg($root)
    . ' && git show 44df02b9:public/admin/compliance/controlled_book_editor.php 2>/dev/null'
);
$baselineEditorApi = (string)shell_exec(
    'cd ' . escapeshellarg($root)
    . ' && git show 44df02b9:public/admin/api/controlled_book_editor_api.php 2>/dev/null'
);

if ($baseline === '' || $baselineCss === '' || $baselineEditorShell === '' || $baselineEditorApi === '') {
    fwrite(STDERR, "FAIL parity.baseline_available — cannot read 44df02b9\n");
    exit(1);
}

preg_match_all(
    '/^\|[^|\n]+\|[^|\n]+\|[^|\n]+\|[^|\n]+\|\s*`([^`]+)`\s*\|$/m',
    $report,
    $matches
);
$namedTests = array_values(array_unique($matches[1] ?? array()));

$required = array(
    'toolbar.structure_and_order',
    'toolbar.all_commands_target_source',
    'toolbar.menus_and_popovers',
    'paragraph.typing_single_source_object',
    'paragraph.enter',
    'paragraph.shift_enter',
    'paragraph.cross_page_identity',
    'heading.edit_and_save',
    'heading.enter',
    'heading.numbering',
    'selection.cross_page_source_range',
    'clipboard.copy',
    'clipboard.paste',
    'formatting.all_text_commands',
    'history.undo',
    'history.redo',
    'list.bullet_visual_identity',
    'list.numbered_visual_identity',
    'list.enter',
    'list.empty_item_exit',
    'list.shift_enter',
    'list.indent',
    'list.outdent',
    'list.nested_structure',
    'list.ordered_start',
    'list.copy_paste_multiple_items',
    'list.single_block_actions',
    'block.insert',
    'block.delete',
    'block.move_up',
    'block.move_down',
    'block.insert_paragraph_below',
    'table.single_source_object',
    'table.cell_edit',
    'table.add_row',
    'table.delete_row',
    'table.add_column',
    'table.delete_column',
    'table.column_resize',
    'table.title_row',
    'table.header_row',
    'table.repeated_header_is_presentation_only',
    'table.merged_and_spanned_cells',
    'table.formatting',
    'table.cell_copy_paste',
    'table.undo_redo',
    'callout.single_source_object',
    'callout.edit_and_save',
    'callout.type_switch',
    'image.upload',
    'image.resize',
    'image.rotate',
    'image.caption',
    'figure.all_controls',
    'field.all_controls',
    'special.cover',
    'special.toc',
    'special.lep',
    'special.part0',
    'special.annex',
    'save.identical_payloads',
    'save.timing_and_status',
    'editing.focus_retention',
    'editing.scroll_retention',
    'pagination.manual_break_only_addition',
    'pagination.page_furniture_only_addition',
    'pagination.presentation_copies_excluded',
);

$failures = 0;
foreach ($required as $testName) {
    if (!in_array($testName, $namedTests, true)) {
        echo "FAIL {$testName} — missing from parity matrix\n";
        $failures++;
    }
}

$baselineSourceCanvas = str_contains($baseline, 'canvasEl.innerHTML = res.page_html')
    && str_contains($baseline, 'wireCanvas();');
$currentSourceCanvas = !str_contains($current, 'return loadPaginatedView().then(function ()')
    && str_contains($current, 'canvasEl.innerHTML = res.page_html')
    && str_contains($current, 'wireCanvas();');
$currentFragmentEditor = str_contains($current, 'wirePaginatedFields();')
    && str_contains($current, "viewMode: 'paginated'");
$fragmentSpecificCommands = str_contains($current, 'flushPaginatedParagraphFragment')
    || str_contains($current, 'flushPaginatedListItem')
    || str_contains($current, 'flushPaginatedTableRow')
    || str_contains($current, 'flushPaginatedCalloutFragment');

$exactEditorParity = hash_equals(hash('sha256', $baseline), hash_file('sha256', $currentPath))
    && hash_equals(hash('sha256', $baselineCss), hash_file('sha256', $currentCssPath))
    && hash_equals(hash('sha256', $baselineEditorShell), hash_file('sha256', $editorShellPath))
    && hash_equals(hash('sha256', $baselineEditorApi), hash_file('sha256', $editorApiPath));

$architectureParity = $exactEditorParity
    && $baselineSourceCanvas
    && $currentSourceCanvas
    && !$currentFragmentEditor;

foreach ($required as $testName) {
    if (!in_array($testName, $namedTests, true)) {
        continue;
    }
    if (str_starts_with($testName, 'pagination.manual_break_')) {
        $passed = str_contains($current, 'insertPageBreakAtCursor');
    } elseif ($testName === 'pagination.page_furniture_only_addition') {
        $passed = str_contains($current, 'reader-generated-page');
    } else {
        $passed = $architectureParity;
    }

    if ($passed) {
        echo "PASS {$testName}\n";
    } else {
        echo "FAIL {$testName} — current editor does not preserve the one live 44df02b9 source block/command path\n";
        $failures++;
    }
}

echo "\nParity matrix rows: " . count($namedTests) . "\n";
echo "Baseline source canvas: " . ($baselineSourceCanvas ? 'yes' : 'no') . "\n";
echo "Current source canvas: " . ($currentSourceCanvas ? 'yes' : 'no') . "\n";
echo "Current fragment editor: " . ($currentFragmentEditor ? 'yes' : 'no') . "\n";
echo "Fragment-specific command paths: " . ($fragmentSpecificCommands ? 'yes' : 'no') . "\n";
echo "Exact 44df02b9 editor asset parity: " . ($exactEditorParity ? 'yes' : 'no') . "\n";

if ($failures > 0) {
    echo "\nEDITOR_PARITY_GATE: BLOCKED ({$failures} failures)\n";
    exit(1);
}

echo "\nEDITOR_PARITY_GATE: PASS\n";
