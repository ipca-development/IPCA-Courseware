<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/publishing/ControlledPublishingReaderService.php';

function publication_snapshot_assert(string $label, bool $condition): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
    echo "PASS: {$label}\n";
}

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP: released publication package snapshot (pdo_sqlite unavailable)\n";
    exit(0);
}

$package = array(
    'manifest_version' => 'book-style-manifest-v1-test',
    'manifest_hash' => hash('sha256', 'manifest'),
    'manifest' => array('test' => true),
    'css' => array(
        'content' => '.frozen{color:#123}',
        'hash' => hash('sha256', '.frozen{color:#123}'),
    ),
    'templates' => array(),
    'assets' => array(),
);
$metadata = array(
    'reader_page_map' => array(
        'status' => 'approved',
        'generation' => array(
            'style_hash' => $package['css']['hash'],
            'manifest_hash' => $package['manifest_hash'],
            'publication_package' => $package,
        ),
    ),
);

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE ipca_publishing_book_versions (
    id INTEGER PRIMARY KEY,
    metadata_json TEXT NOT NULL
)');
$stmt = $pdo->prepare(
    'INSERT INTO ipca_publishing_book_versions (id, metadata_json) VALUES (?, ?)'
);
$stmt->execute(array(10, json_encode($metadata, JSON_THROW_ON_ERROR)));

$reader = new ControlledPublishingReaderService($pdo);
$resolved = $reader->readerPublicationPackage(
    array('id' => 10, 'lifecycle_status' => 'released'),
    array()
);
publication_snapshot_assert(
    'released reader receives CSS frozen with its approved page map',
    ($resolved['css']['content'] ?? '') === '.frozen{color:#123}'
);
publication_snapshot_assert(
    'released reader package style hash matches frozen map identity',
    ($resolved['css']['hash'] ?? '') === $metadata['reader_page_map']['generation']['style_hash']
);
publication_snapshot_assert(
    'released reader package manifest hash matches frozen map identity',
    ($resolved['manifest_hash'] ?? '') === $metadata['reader_page_map']['generation']['manifest_hash']
);

$authoritative = (string)file_get_contents(
    __DIR__ . '/../src/publishing/ControlledPublishingAuthoritativePaginationService.php'
);
$api = (string)file_get_contents(__DIR__ . '/../public/student/api/manual_reader_api.php');
$store = (string)file_get_contents(
    __DIR__ . '/../src/publishing/ControlledPublishingReaderPageMapStore.php'
);
publication_snapshot_assert(
    'authoritative generation freezes its publication package',
    str_contains($authoritative, "'publication_package' => \$package")
);
publication_snapshot_assert(
    'reader API serves the frozen package contract',
    str_contains($api, 'readerPublicationPackage(')
);
publication_snapshot_assert(
    'released compatibility refresh is explicit and approval-preserving',
    str_contains($store, 'function replaceApprovedReleasedPages(')
        && str_contains($store, 'released_publication_compatibility_refresh')
);

echo "Released publication package snapshot: PASS\n";
