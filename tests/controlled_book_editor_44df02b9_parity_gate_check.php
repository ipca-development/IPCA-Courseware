<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$harness = $root . '/tests/controlled_book_editor_44df02b9_behavioral_parity.mjs';
$nodeModules = $root . '/scripts/garmin/node_modules/playwright';
if (!is_file($harness) || !is_dir($nodeModules)) {
    fwrite(STDERR, "FAIL parity.harness_available — behavioral harness or scripts/garmin Playwright dependency missing\n");
    exit(1);
}

$command = 'cd ' . escapeshellarg($root)
    . ' && node ' . escapeshellarg($harness);
passthru($command, $exitCode);
if ($exitCode !== 0) {
    fwrite(STDERR, "\nEDITOR_PARITY_GATE: BLOCKED — executable behavioral contract failed\n");
    exit($exitCode);
}

echo "\nEDITOR_PARITY_GATE: PASS — all 67 baseline/current behaviors match\n";
