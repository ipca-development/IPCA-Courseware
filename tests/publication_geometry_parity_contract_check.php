<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$failures = array();
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$fixturePath = $root . '/tests/fixtures/publication_geometry_golden.json';
$policyPath = $root . '/tests/fixtures/publication_geometry_golden_policy.json';
$runnerPath = $root . '/scripts/qa_publication_geometry_parity.js';
$builderPath = $root . '/scripts/build_publication_geometry_fixture.php';
$documentationPath = $root . '/docs/architecture/publication-geometry-parity.md';

foreach (array($fixturePath, $policyPath, $runnerPath, $builderPath, $documentationPath) as $path) {
    $assert(is_file($path), 'Required parity suite file is missing: ' . str_replace($root . '/', '', $path));
}

$fixture = is_file($fixturePath) ? json_decode((string)file_get_contents($fixturePath), true) : null;
$policy = is_file($policyPath) ? json_decode((string)file_get_contents($policyPath), true) : null;
$runner = is_file($runnerPath) ? (string)file_get_contents($runnerPath) : '';
$builder = is_file($builderPath) ? (string)file_get_contents($builderPath) : '';
$assert(is_array($fixture), 'Publication geometry fixture is not valid JSON.');
$assert(is_array($policy), 'Publication geometry policy is not valid JSON.');

$requiredCategories = array(
    'controlled_header_footer',
    'logo_bounds',
    'title_metadata_cells',
    'paragraph_typography_margins',
    'heading',
    'ordered_list',
    'unordered_list',
    'note_callout',
    'standard_table',
    'figure_image_caption',
);
$fixtureCategories = is_array($fixture['categories'] ?? null) ? $fixture['categories'] : array();
$policyCategories = is_array($policy['required_categories'] ?? null) ? $policy['required_categories'] : array();
foreach ($requiredCategories as $category) {
    $assert(in_array($category, $fixtureCategories, true), "Fixture category is missing: {$category}");
    $assert(in_array($category, $policyCategories, true), "Golden policy category is missing: {$category}");
}

$assert(
    ($fixture['fixture_version'] ?? '') === 'publication-geometry-golden-v1',
    'Fixture version is missing or unexpected.'
);
$assert(!empty($fixture['online_page_html']), 'Fixture lacks ControlledPublishingBookRenderer HTML.');
$assert(!empty($fixture['source']['sections'][0]['units']), 'Fixture lacks canonical iOS pagination source.');
$assert(!empty($fixture['book_style_css']), 'Fixture lacks publication-package book CSS.');
$goldenHtml = (string)($fixture['online_page_html'] ?? '');
$assert(str_contains($goldenHtml, 'cpb-page-header'), 'Golden lacks controlled header.');
$assert(str_contains($goldenHtml, 'cpb-page-footer'), 'Golden lacks controlled footer.');
$assert(str_contains($goldenHtml, 'cpb-page-header-logo'), 'Golden lacks controlled logo bounds.');
$assert(str_contains($goldenHtml, 'cpb-page-header-cell--center'), 'Golden lacks title cell.');
$assert(str_contains($goldenHtml, 'cpb-page-header-cell--right'), 'Golden lacks metadata cell.');
$assert(str_contains($goldenHtml, 'margin-top:7px;margin-bottom:9px'), 'Golden lacks paragraph margin coverage.');
$assert(str_contains($goldenHtml, 'cpb-heading'), 'Golden lacks heading coverage.');
$assert(str_contains($goldenHtml, '<ol'), 'Golden lacks ordered list coverage.');
$assert(str_contains($goldenHtml, '<ul'), 'Golden lacks unordered list coverage.');
$assert(str_contains($goldenHtml, 'cpb-callout--note'), 'Golden lacks NOTE callout coverage.');
$assert(str_contains($goldenHtml, 'data-table-style-kind="standard"'), 'Golden lacks standard table coverage.');
$assert(str_contains($goldenHtml, '<figure'), 'Golden lacks figure coverage.');
$assert(str_contains($goldenHtml, '<img'), 'Golden lacks image coverage.');
$assert(str_contains($goldenHtml, '<figcaption'), 'Golden lacks caption coverage.');
$assert(str_contains($goldenHtml, 'data:image/svg+xml;base64,'), 'Golden is not network-independent.');
$assert(str_contains($builder, 'ControlledPublishingBookRenderer'), 'Fixture builder does not use the shared renderer.');
$assert(str_contains($builder, 'buildPublicationPackage'), 'Fixture builder does not use publication-package CSS generation.');
$assert(str_contains($runner, 'ReaderPaginationCore.js'), 'Parity runner bypasses ReaderPaginationCore.');
$assert(str_contains($runner, 'webkit'), 'Parity runner is not WebKit-oriented.');
$assert(str_contains($runner, 'per_channel_antialias_tolerance'), 'Parity runner lacks configurable antialiasing tolerance.');
$assert(str_contains($runner, 'getComputedStyle'), 'Parity runner lacks computed style comparison.');
$assert(str_contains($runner, 'getBoundingClientRect'), 'Parity runner lacks DOM geometry comparison.');
$assert(str_contains($runner, 'getImageData'), 'Parity runner lacks in-browser pixel comparison.');
$assert(str_contains($runner, '--update'), 'Parity runner lacks documented golden update support.');
$assert(str_contains($runner, 'EXPLICIT FALLBACK'), 'Chromium fallback is not explicitly reported.');

if ($failures !== array()) {
    fwrite(STDERR, "Publication geometry parity contract: FAIL\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, " - {$failure}\n");
    }
    exit(1);
}

echo "Publication geometry parity contract: PASS\n";
echo "Renderer fixture, iOS pagination path, geometry/style categories, pixel policy, and update mode verified\n";
