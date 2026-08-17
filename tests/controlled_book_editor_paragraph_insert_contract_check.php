<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$js = (string)file_get_contents($root . '/public/assets/controlled_book_editor.js');
$api = (string)file_get_contents($root . '/public/admin/api/controlled_book_editor_api.php');
$failures = array();

foreach (array(
    'function focusCreatedBlock()',
    '(res.block && res.block.id) || res.block_id',
    'range.collapse(true)',
    'focusCreatedBlock();',
) as $marker) {
    if (!str_contains($js, $marker)) {
        $failures[] = 'Missing paragraph focus marker: ' . $marker;
    }
}
foreach (array(
    "trim((string)(\$payload['html'] ?? '')) === '<p>New paragraph</p>'",
    "\$payload['html'] = '<p><br></p>';",
) as $marker) {
    if (!str_contains($api, $marker)) {
        $failures[] = 'Missing empty paragraph storage marker: ' . $marker;
    }
}

if ($failures !== array()) {
    fwrite(STDERR, "Controlled book paragraph insertion contract: FAIL\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, " - {$failure}\n");
    }
    exit(1);
}

echo "Controlled book paragraph insertion contract: PASS\n";
