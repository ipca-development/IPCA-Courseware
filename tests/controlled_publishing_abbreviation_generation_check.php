<?php
declare(strict_types=1);

$service = (string)file_get_contents(
    dirname(__DIR__) . '/src/publishing/ControlledPublishingPart0PageService.php'
);

$checks = array(
    'legacy canonical-only error is removed' =>
        !str_contains($service, 'No abbreviations found in the linked manual source set.'),
    'empty canonical index falls back to live manual content' =>
        str_contains(
            $service,
            "\$source = \$canonicalEntries === array() ? 'manual_content' : 'canonical_and_manual';"
        ),
    'live block discovery remains part of generation' =>
        str_contains($service, 'discoverAbbreviationsFromManual($versionId)'),
    'generated live-content lists do not resynchronize on every page load' =>
        str_contains($service, "array('canonical', 'canonical_and_manual', 'manual_content')"),
);

foreach ($checks as $label => $passed) {
    if (!$passed) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
    echo "PASS: {$label}\n";
}

echo "Controlled publishing abbreviation generation: PASS\n";
