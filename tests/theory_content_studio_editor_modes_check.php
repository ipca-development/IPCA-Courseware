<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$ui = (string)file_get_contents($root . '/src/theory_studio/TheoryStructuredEditorUi.php');
$js = (string)file_get_contents($root . '/public/assets/theory-structured-editor.js');
$css = (string)file_get_contents($root . '/public/assets/theory-structured-editor.css');
$bootstrap = (string)file_get_contents($root . '/public/admin/theory_studio/_bootstrap.php');
$isolation = (string)file_get_contents($root . '/src/theory_studio/TheoryStudioIsolation.php');

$checks = array(
    'Theory editor uses a dedicated visual application shell' =>
        str_contains($ui, 'tse-app-header')
        && str_contains($ui, 'tse-ribbon')
        && str_contains($ui, 'tse-navigator')
        && str_contains($ui, 'tse-canvas-workspace')
        && str_contains($ui, 'tse-inspector')
        && str_contains($ui, 'tse-statusbar')
        && !str_contains($ui, 'cpb-toolbar'),
    'Template and Slide modes are explicit' =>
        str_contains($js, "mode === 'template'") && str_contains($js, "mode === 'slide'"),
    'Template geometry uses the canonical canvas' =>
        str_contains($css, 'width: 1600px')
        && str_contains($css, 'height: 900px')
        && str_contains($js, '* 1600 /')
        && str_contains($js, '* 900 /'),
    'Text Image and Video placeholders are supported' =>
        str_contains($ui, "'data-tse-add' => 'text'")
        && str_contains($ui, "'data-tse-add' => 'image'")
        && str_contains($ui, "'data-tse-add' => 'video'"),
    'Slide mode does not expose template geometry controls' =>
        str_contains($js, "mode === 'template' || !!placeholder.author_can_reposition")
        && str_contains($js, "mode === 'template' || !!placeholder.author_can_resize"),
    'Editor has shared rich editing tools' =>
        str_contains($ui, "'data-cmd' => 'bold'")
        && str_contains($ui, "'data-tse-link'")
        && str_contains($ui, "'data-tse-table'")
        && str_contains($ui, "'data-tse-callout'")
        && str_contains($ui, 'tseUndo')
        && str_contains($ui, 'tseRedo'),
    'Template guides can be toggled added and deleted' =>
        str_contains($ui, 'tseGuidesVisible')
        && str_contains($ui, 'tseSnapGuides')
        && str_contains($ui, 'tseRulerX')
        && str_contains($ui, 'tseRulerY')
        && str_contains($js, 'data-remove-guide'),
    'Guide visuals override global button chrome and remain one pixel' =>
        str_contains($css, '.tse-guide--horizontal')
        && str_contains($css, 'height: 1px !important')
        && str_contains($css, 'min-height: 0 !important')
        && str_contains($css, 'box-shadow: none !important'),
    'Guides and all placeholder boxes participate in snapping' =>
        str_contains($js, 'snapTargets')
        && str_contains($js, "state.placeholders.forEach")
        && str_contains($js, "limit / 2")
        && str_contains($js, 'bestSnap')
        && str_contains($js, 'showSmartLines'),
    'Template Text Box typography is configurable' =>
        str_contains($js, 'Typography')
        && str_contains($js, 'font_family')
        && str_contains($js, 'font_size')
        && str_contains($js, 'font_weight')
        && str_contains($js, 'text_color')
        && str_contains($js, 'line_height')
        && str_contains($js, 'vertical_alignment'),
    'Desktop authoring controls include fullscreen zoom preview grid and keyboard behavior' =>
        str_contains($ui, 'tseFullscreen')
        && str_contains($ui, 'tseZoom')
        && str_contains($ui, 'tsePreview')
        && str_contains($ui, 'tseShowGrid')
        && str_contains($js, 'requestFullscreen')
        && str_contains($js, "event.key.toLowerCase() === 's'")
        && str_contains($js, "event.key === 'Escape'"),
    'Add Slide uses a visual Template chooser' =>
        str_contains($ui, 'tseTemplateChooser')
        && str_contains($ui, 'tse-template-preview')
        && str_contains($ui, 'data-ts-form="create_structured_slide"'),
    'Legacy APIs can reject structured slides' =>
        str_contains($isolation, 'STRUCTURED_SLIDE_REQUIRES_STUDIO')
        && str_contains($isolation, "source_category")
        && str_contains($isolation, "structured"),
);

foreach ($checks as $label => $ok) {
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
    echo "PASS: {$label}\n";
}

echo "Theory Content Studio editor modes: PASS\n";
