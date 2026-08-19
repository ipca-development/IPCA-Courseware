<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$paths = array();
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator(
        $root . '/ipca-manual-reader-ios/IPCAManualReader',
        FilesystemIterator::SKIP_DOTS
    )
);
foreach ($iterator as $file) {
    if ($file instanceof SplFileInfo && $file->isFile()) {
        $paths[] = substr($file->getPathname(), strlen($root) + 1);
    }
}
$paths[] = 'ipca-manual-reader-ios/IPCAManualReader.xcodeproj/project.pbxproj';
foreach (array(
    'public/assets/controlled_book_editor.js',
    'public/assets/controlled_book_editor.css',
    'public/admin/compliance/controlled_book_editor.php',
) as $path) {
    $paths[] = $path;
}
foreach (glob($root . '/public/admin/api/controlled_book_*_api.php') ?: array() as $path) {
    $paths[] = substr($path, strlen($root) + 1);
}

sort($paths);
$hash = hash_init('sha256');
foreach ($paths as $path) {
    if (!is_file($root . '/' . $path)) {
        fwrite(STDERR, "Protected Books & Manuals boundary file is missing: {$path}\n");
        exit(1);
    }
    hash_update($hash, $path . "\0" . hash_file('sha256', $root . '/' . $path) . "\n");
}

$actual = hash_final($hash);
$expected = 'acd4be8a6dd901176b0726d4e226015c1b8e7e0171d3f85d430e658c6ade9516';
if ($actual !== $expected) {
    fwrite(STDERR, "Books & Manuals protected boundary changed: {$actual}\n");
    exit(1);
}

echo "Books & Manuals protected boundary: PASS\n";
echo 'Protected files: ' . count($paths) . "\n";
