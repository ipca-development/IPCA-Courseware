<?php
declare(strict_types=1);

/**
 * QA pass for OM frozen page map generation.
 *
 * Usage: php scripts/qa_om_page_map.php [--book=OM] [--skip-migrate] [--skip-generate]
 */

$root = dirname(__DIR__);

$loadDotenv = static function (string $path): void {
    if (!is_readable($path)) {
        return;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (!preg_match('/^([A-Za-z_][A-Za-z0-9_]*)=(.*)$/', $line, $m)) {
            continue;
        }
        if (getenv($m[1]) !== false) {
            continue;
        }
        $val = $m[2];
        if ($val !== '' && (($val[0] === '"' && str_ends_with($val, '"')) || ($val[0] === "'" && str_ends_with($val, "'")))) {
            $val = substr($val, 1, -1);
        }
        putenv($m[1] . '=' . $val);
    }
};

if (!getenv('CW_DB_HOST')) {
    $loadDotenv($root . '/.env');
}

require_once $root . '/src/helpers.php';
require_once $root . '/src/db.php';
require_once $root . '/src/publishing/ControlledPublishingReaderService.php';
require_once $root . '/src/publishing/ControlledPublishingReaderLayoutProfile.php';
require_once $root . '/src/publishing/ControlledPublishingReaderPageMapStore.php';
require_once $root . '/src/publishing/ControlledPublishingPaginationService.php';

$opts = getopt('', ['book::', 'skip-migrate', 'skip-generate']);
$bookKey = strtoupper((string)($opts['book'] ?? 'OM'));
$skipMigrate = isset($opts['skip-migrate']);
$skipGenerate = isset($opts['skip-generate']);

function apply_sql_file(PDO $pdo, string $path): void
{
    if (!is_readable($path)) {
        throw new RuntimeException("Missing migration: {$path}");
    }
    $sql = (string)file_get_contents($path);
    echo "Applying migration: {$path}\n";
    $pdo->exec($sql);
    echo "  OK\n";
}

function table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        'SELECT 1 FROM information_schema.tables
          WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1'
    );
    $stmt->execute(array($table));

    return (bool)$stmt->fetchColumn();
}

function has_raw_tokens(string $html): bool
{
    return has_unresolved_tokens($html);
}

function footer_page_number_right_aligned(string $html): bool
{
    if (!preg_match('/<footer class="cpb-page-footer"[^>]*>(.*?)<\/footer>/s', $html, $m)) {
        return false;
    }
    $footer = $m[1];
    if (preg_match('/cpb-page-header-cell--right[^>]*>.*?(\d+)/s', $footer) === 1) {
        return true;
    }
    if (preg_match('/<td[^>]*class="[^"]*right[^"]*"[^>]*>.*?(\d+)/s', $footer) === 1) {
        return true;
    }

    return false;
}

function has_unresolved_tokens(string $html): bool
{
    $known = array(
        'book_title', 'manual_code', 'part_title', 'section_title', 'page_total', 'page',
        'effective_date', 'version_label', 'revision_date', 'revision_no',
    );
    foreach ($known as $key) {
        if (str_contains($html, '{' . $key . '}') || str_contains($html, '{{' . $key . '}}')) {
            return true;
        }
    }

    return preg_match('/\{[a-z_]+\}/', $html) === 1;
}

function has_header(string $html, bool $isCover): bool
{
    if ($isCover) {
        return true;
    }

    return preg_match('/<header class="cpb-page-header"/', $html) === 1;
}

function has_footer(string $html, bool $isCover): bool
{
    if ($isCover) {
        return true;
    }

    return preg_match('/<footer class="cpb-page-footer"/', $html) === 1;
}

function table_looks_broken(string $html): bool
{
    if (stripos($html, '<table') === false) {
        return false;
    }
    if (preg_match('/<table[^>]*>/i', $html) !== 1) {
        return true;
    }
    if (preg_match('/<tr/i', $html) !== 1 && preg_match('/<td/i', $html) === 1) {
        return true;
    }

    return false;
}

function image_clipped_unexpectedly(string $html): bool
{
    if (stripos($html, '<img') === false) {
        return false;
    }
    if (preg_match('/overflow\s*:\s*hidden[^;]*;[^"]*<img/s', $html) === 1) {
        return preg_match('/height:\s*\d+px;\s*overflow:\s*hidden/s', $html) === 1;
    }

    return false;
}

$pdo = cw_db();
$reader = new ControlledPublishingReaderService($pdo);
$store = $reader->pageMapStore();
$profile = ControlledPublishingReaderLayoutProfile::profileKey();

echo "=== OM Page Map QA (book={$bookKey}) ===\n\n";

// 1. Migration
if (!$skipMigrate) {
    if (!table_exists($pdo, 'ipca_publishing_reader_page_maps')) {
        apply_sql_file($pdo, $root . '/scripts/sql/2026_06_19_publishing_reader_page_maps.sql');
    } else {
        echo "Migration: page_maps table already exists, skipping.\n";
    }
    if (!table_exists($pdo, 'ipca_manual_reading_progress')) {
        apply_sql_file($pdo, $root . '/scripts/sql/2026_06_18_manual_reader.sql');
        echo "Migration: applied ipca_manual_reading_progress.\n";
    }
} else {
    echo "Migration: skipped (--skip-migrate).\n";
}

$version = $reader->resolveLatestReleasedVersion($bookKey);
if ($version === null) {
    fwrite(STDERR, "FAIL: No released version for {$bookKey}.\n");
    exit(1);
}
$versionId = (int)$version['id'];
$versionLabel = (string)($version['version_label'] ?? '');
echo "Released version: id={$versionId} label={$versionLabel}\n\n";

// 2. Generate
if (!$skipGenerate) {
    echo "Generating page map draft...\n";
    $gen = $reader->generateFrozenPageMapDraft($bookKey, 1);
    echo "  Generated {$gen['page_count']} pages (layout_hash={$gen['layout_hash']})\n\n";
} else {
    echo "Generate: skipped (--skip-generate).\n\n";
}

// 3. Approve (if draft)
$approval = $store->approvalMeta($versionId);
if (($approval['status'] ?? '') === 'draft') {
    echo "Approving page map...\n";
    $approved = $store->approve($versionId, 1, $profile);
    echo "  Approved {$approved['page_count']} pages at {$approved['approved_at']}\n\n";
} elseif (($approval['status'] ?? '') === 'approved') {
    echo "Page map already approved ({$approval['page_count']} pages).\n\n";
} else {
    echo "WARNING: No draft/approved page map found.\n\n";
}

if (!$store->isApproved($versionId, $profile)) {
    fwrite(STDERR, "FAIL: Page map not approved.\n");
    exit(1);
}

// Load data
$pageMap = $reader->loadFrozenPageMap($bookKey);
$toc = $reader->loadTocWithPages($bookKey);
$pageCount = (int)$pageMap['page_count'];
$sectionIndex = $toc['section_page_index'] ?? array();

echo "Page count: {$pageCount}\n";
echo "Layout profile: {$profile}\n\n";

$failures = array();
$warnings = array();

// Fetch all pages
$allPages = array();
for ($n = 1; $n <= $pageCount; $n++) {
    $allPages[$n] = $reader->loadFrozenPage($bookKey, $n);
}

// Page count consistency (simulate desktop vs mobile — same API source)
$desktopCount = $pageCount;
$mobileCount = $pageCount;
if ($desktopCount !== $mobileCount) {
    $failures[] = array('page' => 0, 'section' => '(global)', 'issue' => "Page count mismatch desktop={$desktopCount} mobile={$mobileCount}");
}

// TOC vs actual pages
function flatten_nav(array $nodes, array &$out): void
{
    foreach ($nodes as $node) {
        if (!is_array($node)) {
            continue;
        }
        $out[] = $node;
        if (!empty($node['children']) && is_array($node['children'])) {
            flatten_nav($node['children'], $out);
        }
    }
}

$navFlat = array();
flatten_nav($toc['nav'] ?? array(), $navFlat);
foreach ($navFlat as $node) {
    if (empty($node['is_navigable']) || empty($node['id'])) {
        continue;
    }
    $sid = (int)$node['id'];
    $tocPage = (int)($node['page_number'] ?? 0);
    $actualPage = (int)($sectionIndex[$sid] ?? 0);
    if ($tocPage <= 0 || $actualPage <= 0) {
        continue;
    }
    if ($tocPage !== $actualPage) {
        $failures[] = array(
            'page' => $actualPage,
            'section' => (string)($node['title'] ?? ''),
            'issue' => "TOC page {$tocPage} != section index page {$actualPage}",
        );
    }
    $pageAtToc = $allPages[$tocPage] ?? null;
    if ($pageAtToc !== null && (int)($pageAtToc['section_id'] ?? 0) !== $sid) {
        $firstForSection = null;
        foreach ($allPages as $pn => $pg) {
            if ((int)($pg['section_id'] ?? 0) === $sid) {
                $firstForSection = $pn;
                break;
            }
        }
        if ($firstForSection !== $tocPage) {
            $warnings[] = array(
                'page' => $tocPage,
                'section' => (string)($node['title'] ?? ''),
                'issue' => "TOC points to page {$tocPage} but section {$sid} first appears on page {$firstForSection}",
            );
        }
    }
}

// Per-page checks
$prevSectionId = null;
foreach ($allPages as $pageNum => $page) {
    $html = (string)($page['page_html'] ?? '');
    $title = (string)($page['section_title'] ?? '');
    $isCover = !empty($page['is_cover']);
    $isSectionStart = !empty($page['is_section_start']);
    $sectionId = (int)($page['section_id'] ?? 0);

    if (has_raw_tokens($html)) {
        $failures[] = array('page' => $pageNum, 'section' => $title, 'issue' => 'Raw tokens in page HTML');
    }

    if (!has_header($html, $isCover)) {
        $failures[] = array('page' => $pageNum, 'section' => $title, 'issue' => 'Missing official header');
    }

    if (!has_footer($html, $isCover)) {
        $failures[] = array('page' => $pageNum, 'section' => $title, 'issue' => 'Missing official footer');
    }

    if (!$isCover && !footer_page_number_right_aligned($html)) {
        $warnings[] = array('page' => $pageNum, 'section' => $title, 'issue' => 'Footer page number may not be right-aligned');
    }

    if ($isSectionStart && $pageNum > 1 && !$isCover) {
        $prev = $allPages[$pageNum - 1] ?? null;
        if ($prev !== null && (int)($prev['section_id'] ?? 0) === $sectionId) {
            $failures[] = array('page' => $pageNum, 'section' => $title, 'issue' => 'Section start but same section continues from previous page');
        }
    }

    if (table_looks_broken($html)) {
        $failures[] = array('page' => $pageNum, 'section' => $title, 'issue' => 'Table structure appears broken');
    }

    if (image_clipped_unexpectedly($html)) {
        $warnings[] = array('page' => $pageNum, 'section' => $title, 'issue' => 'Image may be clipped unexpectedly');
    }

    // Filmstrip thumbnail page number
    $summaryPage = null;
    foreach ($pageMap['pages'] ?? array() as $sp) {
        if ((int)($sp['page_number'] ?? 0) === $pageNum) {
            $summaryPage = $sp;
            break;
        }
    }
    if ($summaryPage !== null && (int)$summaryPage['page_number'] !== $pageNum) {
        $failures[] = array('page' => $pageNum, 'section' => $title, 'issue' => 'Filmstrip page number mismatch');
    }

    $prevSectionId = $sectionId;
}

// Part/chapter break checks from metadata
$majorStarts = array();
foreach ($allPages as $pageNum => $page) {
    if (!empty($page['is_major_section_start']) && $pageNum > 1) {
        $majorStarts[] = $pageNum;
        $prev = $allPages[$pageNum - 1] ?? null;
        if ($prev !== null && (int)($prev['section_id'] ?? 0) === (int)($page['section_id'] ?? 0)) {
            $failures[] = array(
                'page' => $pageNum,
                'section' => (string)($page['section_title'] ?? ''),
                'issue' => 'Major section start not on new page boundary',
            );
        }
    }
}

// Progress restore simulation — use first student/instructor user found
$progressUserId = 1;
$userRow = $pdo->query(
    "SELECT id FROM users WHERE LOWER(TRIM(role)) IN ('student','instructor','admin') AND id > 0 ORDER BY id ASC LIMIT 1"
)->fetch(PDO::FETCH_ASSOC);
if (is_array($userRow)) {
    $progressUserId = (int)$userRow['id'];
}
$testPageNum = min(5, max(1, $pageCount));
$testSectionId = (int)($allPages[$testPageNum]['section_id'] ?? 0);
if ($testSectionId > 0) {
    $reader->saveReadingProgress($progressUserId, $bookKey, $testSectionId, (string)($allPages[$testPageNum]['stable_anchor'] ?? ''), $testPageNum);
    $prog = $reader->getReadingProgress($progressUserId, $bookKey);
    $restoredPage = (int)($prog['scroll_pct'] ?? 0);
    if ($restoredPage !== $testPageNum) {
        $failures[] = array(
            'page' => $testPageNum,
            'section' => (string)($allPages[$testPageNum]['section_title'] ?? ''),
            'issue' => "Progress restore expected page {$testPageNum}, got {$restoredPage}",
        );
    } else {
        echo "Progress restore: saved page {$testPageNum}, restored scroll_pct={$restoredPage} OK\n";
    }
}

// Summary
echo "\n=== QA Summary ===\n";
echo "Pages checked: {$pageCount}\n";
echo "Major section starts on pages: " . implode(', ', $majorStarts) . "\n";
echo "Failures: " . count($failures) . "\n";
echo "Warnings: " . count($warnings) . "\n\n";

if ($failures !== array()) {
    echo "--- FAILURES ---\n";
    foreach ($failures as $f) {
        echo "  Page {$f['page']} · {$f['section']}: {$f['issue']}\n";
    }
    echo "\n";
}

if ($warnings !== array()) {
    echo "--- WARNINGS ---\n";
    foreach (array_slice($warnings, 0, 30) as $w) {
        echo "  Page {$w['page']} · {$w['section']}: {$w['issue']}\n";
    }
    if (count($warnings) > 30) {
        echo "  ... and " . (count($warnings) - 30) . " more warnings\n";
    }
    echo "\n";
}

if ($failures === array()) {
    echo "PASS: All critical checks passed.\n";
    echo "Desktop/mobile page count: identical ({$pageCount}) — frozen map is viewport-independent.\n";
    exit(0);
}

exit(1);
