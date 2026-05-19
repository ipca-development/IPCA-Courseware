<?php
declare(strict_types=1);

/**
 * Read-only foundation report for the Controlled Publishing canonical layer.
 *
 * This script does not write to the database. It checks:
 * - canonical source set inventory
 * - latest sync run counts
 * - missing/broken requirement/excerpt links
 * - source baseline hash inventory
 * - book versions that cannot be released because no source set/baseline exists
 *
 * Usage:
 *   php scripts/report_controlled_publishing_foundation.php
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

require_once __DIR__ . '/../src/db.php';

$pdo = cw_db();
$requiredTables = array(
    'ipca_canonical_sources',
    'ipca_canonical_source_sets',
    'ipca_canonical_documents',
    'ipca_canonical_requirements',
    'ipca_canonical_excerpts',
    'ipca_canonical_requirement_excerpt_links',
    'ipca_canonical_sync_runs',
    'ipca_canonical_sync_row_map',
    'ipca_publishing_books',
    'ipca_publishing_book_versions',
    'ipca_publishing_book_version_source_sets',
    'ipca_publishing_source_baselines',
);

$missing = missing_tables($pdo, $requiredTables);
if ($missing !== array()) {
    fwrite(STDOUT, "Controlled Publishing Foundation Report\n");
    fwrite(STDOUT, "Status: schema incomplete\n");
    fwrite(STDOUT, "Missing tables: " . implode(', ', $missing) . "\n");
    exit(2);
}

fwrite(STDOUT, "Controlled Publishing Foundation Report\n");
fwrite(STDOUT, "Generated: " . gmdate('c') . "\n\n");

print_section('Canonical source sets');
print_rows($pdo->query("
    SELECT
      ss.id,
      s.source_key,
      ss.source_set_key,
      ss.source_family,
      ss.authority,
      ss.revision_label,
      ss.status,
      ss.source_hash,
      ss.last_synced_at
    FROM ipca_canonical_source_sets ss
    INNER JOIN ipca_canonical_sources s ON s.id = ss.source_id
    ORDER BY ss.source_family, ss.source_set_key
")->fetchAll(PDO::FETCH_ASSOC));

print_section('Canonical counts by source set');
print_rows($pdo->query("
    SELECT
      ss.source_set_key,
      ss.source_family,
      COUNT(DISTINCT d.id) AS documents,
      COUNT(DISTINCT r.id) AS requirements,
      COUNT(DISTINCT e.id) AS excerpts,
      COUNT(DISTINCT l.id) AS requirement_excerpt_links
    FROM ipca_canonical_source_sets ss
    LEFT JOIN ipca_canonical_documents d ON d.source_set_id = ss.id
    LEFT JOIN ipca_canonical_requirements r ON r.source_set_id = ss.id
    LEFT JOIN ipca_canonical_excerpts e ON e.source_set_id = ss.id
    LEFT JOIN ipca_canonical_requirement_excerpt_links l ON l.source_set_id = ss.id
    GROUP BY ss.id, ss.source_set_key, ss.source_family
    ORDER BY ss.source_family, ss.source_set_key
")->fetchAll(PDO::FETCH_ASSOC));

print_section('Latest sync runs');
print_rows($pdo->query("
    SELECT
      sr.id,
      s.source_key,
      ss.source_set_key,
      sr.status,
      sr.dry_run,
      sr.started_at,
      sr.completed_at,
      sr.source_inventory_hash,
      sr.observed_counts_json,
      sr.action_counts_json
    FROM ipca_canonical_sync_runs sr
    INNER JOIN ipca_canonical_sources s ON s.id = sr.source_id
    LEFT JOIN ipca_canonical_source_sets ss ON ss.id = sr.source_set_id
    ORDER BY sr.started_at DESC
    LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC));

print_section('Broken canonical requirement/excerpt links');
print_rows($pdo->query("
    SELECT
      l.id,
      l.source_set_id,
      l.requirement_key,
      l.excerpt_key,
      CASE WHEN r.id IS NULL THEN 'missing_requirement' ELSE NULL END AS requirement_issue,
      CASE WHEN e.id IS NULL THEN 'missing_excerpt' ELSE NULL END AS excerpt_issue
    FROM ipca_canonical_requirement_excerpt_links l
    LEFT JOIN ipca_canonical_requirements r ON r.id = l.requirement_id
    LEFT JOIN ipca_canonical_excerpts e ON e.id = l.excerpt_id
    WHERE r.id IS NULL OR e.id IS NULL
    ORDER BY l.id
    LIMIT 100
")->fetchAll(PDO::FETCH_ASSOC));

print_section('Source baselines');
print_rows($pdo->query("
    SELECT
      b.id,
      bv.id AS book_version_id,
      pb.book_key,
      bv.version_label,
      b.baseline_key,
      b.baseline_status,
      b.baseline_hash,
      b.frozen_at,
      COUNT(bs.id) AS source_sets
    FROM ipca_publishing_source_baselines b
    INNER JOIN ipca_publishing_book_versions bv ON bv.id = b.book_version_id
    INNER JOIN ipca_publishing_books pb ON pb.id = bv.book_id
    LEFT JOIN ipca_publishing_source_baseline_sets bs ON bs.source_baseline_id = b.id
    GROUP BY b.id, bv.id, pb.book_key, bv.version_label, b.baseline_key, b.baseline_status, b.baseline_hash, b.frozen_at
    ORDER BY b.created_at DESC
    LIMIT 50
")->fetchAll(PDO::FETCH_ASSOC));

print_section('Book versions blocked from release foundation');
print_rows($pdo->query("
    SELECT
      bv.id,
      pb.book_key,
      bv.version_label,
      bv.lifecycle_status,
      COUNT(vss.id) AS selected_source_sets,
      bv.source_baseline_id,
      CASE
        WHEN COUNT(vss.id) = 0 THEN 'missing_source_set_selection'
        WHEN bv.source_baseline_id IS NULL THEN 'missing_source_baseline'
        ELSE 'ok'
      END AS release_foundation_status
    FROM ipca_publishing_book_versions bv
    INNER JOIN ipca_publishing_books pb ON pb.id = bv.book_id
    LEFT JOIN ipca_publishing_book_version_source_sets vss ON vss.book_version_id = bv.id
    GROUP BY bv.id, pb.book_key, bv.version_label, bv.lifecycle_status, bv.source_baseline_id
    HAVING release_foundation_status <> 'ok'
    ORDER BY bv.updated_at DESC
")->fetchAll(PDO::FETCH_ASSOC));

/**
 * @param list<string> $tables
 * @return list<string>
 */
function missing_tables(PDO $pdo, array $tables): array
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.tables
        WHERE table_schema = DATABASE()
          AND table_name = :table_name
    ");

    $missing = array();
    foreach ($tables as $table) {
        $stmt->execute(array(':table_name' => $table));
        if ((int)$stmt->fetchColumn() === 0) {
            $missing[] = $table;
        }
    }
    return $missing;
}

function print_section(string $title): void
{
    fwrite(STDOUT, "\n## {$title}\n");
}

/**
 * @param list<array<string,mixed>> $rows
 */
function print_rows(array $rows): void
{
    if ($rows === array()) {
        fwrite(STDOUT, "(none)\n");
        return;
    }

    foreach ($rows as $row) {
        fwrite(STDOUT, '- ' . json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    }
}
