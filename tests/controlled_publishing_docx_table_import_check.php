<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/src/publishing/ControlledPublishingDocxReader.php';

$failures = array();
$assert = static function (bool $ok, string $message) use (&$failures): void {
    if (!$ok) {
        $failures[] = $message;
    }
};

$wNs = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

$makeDocx = static function (string $bodyInner) use ($wNs): string {
    $documentXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<w:document xmlns:w="' . $wNs . '"><w:body>'
        . $bodyInner
        . '</w:body></w:document>';
    $path = tempnam(sys_get_temp_dir(), 'ipca-docx-');
    if ($path === false) {
        throw new RuntimeException('Could not create temp DOCX path.');
    }
    $zipPath = $path . '.docx';
    @unlink($path);
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Could not create test DOCX.');
    }
    $zip->addFromString('word/document.xml', $documentXml);
    $zip->close();

    return $zipPath;
};

$cell = static function (string $text, int $gridSpan = 1) use ($wNs): string {
    $span = $gridSpan > 1
        ? '<w:tcPr><w:gridSpan w:val="' . $gridSpan . '"/></w:tcPr>'
        : '';
    return '<w:tc>' . $span . '<w:p><w:r><w:t>' . htmlspecialchars($text, ENT_XML1) . '</w:t></w:r></w:p></w:tc>';
};

$tableXml = '<w:tbl><w:tblGrid><w:gridCol/><w:gridCol/><w:gridCol/></w:tblGrid>'
    . '<w:tr>' . $cell('A') . $cell('') . $cell('C') . '</w:tr>'
    . '<w:tr>' . $cell('Merged', 2) . $cell('Right') . '</w:tr>'
    . '</w:tbl>';

$reader = new ControlledPublishingDocxReader();

$wrappedPath = $makeDocx(
    '<w:sdt><w:sdtContent>' . $tableXml . '</w:sdtContent></w:sdt>'
    . '<w:sectPr><w:pgSz w:w="15840" w:h="12240" w:orient="landscape"/></w:sectPr>'
);
$annexParsed = $reader->parseFile($wrappedPath, -1, array(
    'include_front_matter' => true,
    'detect_part_from_filename' => false,
));
@unlink($wrappedPath);

$tables = array_values(array_filter(
    $annexParsed['nodes'],
    static fn (array $node): bool => ($node['type'] ?? '') === 'table'
));
$assert(count($tables) === 1, 'Annex import must keep a table that is the first body element.');
$assert(($annexParsed['orientation'] ?? '') === 'landscape', 'Landscape Word pages must be detected.');
$assert(
    ($tables[0]['rows'][0] ?? null) === array('A', '', 'C'),
    'Empty table cells must be preserved instead of being collapsed into a colspan.'
);
$assert(
    ($tables[0]['row_colspans'][0] ?? null) === array(1, 1, 1),
    'Unmerged empty cells must keep colspan 1.'
);
$assert(
    ($tables[0]['rows'][1] ?? null) === array('Merged', 'Right'),
    'Explicit Word gridSpan must stay as a merged cell, not empty placeholders.'
);
$assert(
    ($tables[0]['row_colspans'][1] ?? null) === array(2, 1),
    'Word gridSpan=2 must be imported as colspan metadata.'
);

$assert(
    ControlledPublishingDocxReader::sanitizeImportedText('18-08-2026') === '18-08-2026',
    'dd-mm-yyyy dates must not lose the year during import sanitization.'
);
$assert(
    ControlledPublishingDocxReader::sanitizeImportedText('18/08/2026') === '18/08/2026',
    'dd/mm/yyyy dates must be preserved during import sanitization.'
);
$assert(
    ControlledPublishingDocxReader::sanitizeImportedText('2026-08-18') === '2026-08-18',
    'yyyy-mm-dd dates must be preserved during import sanitization.'
);
$assert(
    ControlledPublishingDocxReader::sanitizeImportedText('Electronic Mass & Balance-92178') === 'Electronic Mass & Balance',
    '5-digit Word bookmark suffixes must still be stripped.'
);

$manualPath = $makeDocx(
    '<w:p><w:pPr><w:pStyle w:val="TOC1"/></w:pPr><w:r><w:t>Contents</w:t></w:r></w:p>'
    . $tableXml
    . '<w:p><w:pPr><w:pStyle w:val="Heading1"/></w:pPr><w:r><w:t>1. INTRODUCTION</w:t></w:r></w:p>'
);
$manualBeforeHeading = $reader->parseFile($manualPath, 1);
@unlink($manualPath);
$manualTables = array_values(array_filter(
    $manualBeforeHeading['nodes'],
    static fn (array $node): bool => ($node['type'] ?? '') === 'table'
));
$assert(
    $manualTables === array(),
    'Manual part import must still skip tables that appear before the first real heading.'
);

$namedPartPath = $makeDocx($tableXml);
$namedCopy = sys_get_temp_dir() . '/OM_Part_1_Annex_tables.docx';
if (!@copy($namedPartPath, $namedCopy)) {
    $failures[] = 'Could not copy annex fixture with a Part filename.';
} else {
    $namedParsed = $reader->parseFile($namedCopy, -1, array(
        'include_front_matter' => true,
        'detect_part_from_filename' => false,
    ));
    $namedTables = array_values(array_filter(
        $namedParsed['nodes'],
        static fn (array $node): bool => ($node['type'] ?? '') === 'table'
    ));
    $assert(
        count($namedTables) === 1,
        'Annex filenames that contain "Part 1" must not drop tables via TOC skipping.'
    );
    @unlink($namedCopy);
}
@unlink($namedPartPath);

if ($failures !== array()) {
    fwrite(STDERR, "Controlled publishing DOCX table import: FAIL\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, ' - ' . $failure . "\n");
    }
    exit(1);
}

echo "Controlled publishing DOCX table import: PASS\n";
echo "Tables: content-control unwrap, empty cells, annex front-matter, landscape detection\n";
