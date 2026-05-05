<?php
declare(strict_types=1);

require_once __DIR__ . '/resource_library_catalog.php';

/**
 * FAA AIM HTML crawler — index tables reference resource_library_editions (resource_type = crawler).
 * Schema: scripts/sql/resource_library_aim_crawl.sql
 */

function rl_aim_schema_table(): string
{
    return 'resource_library_aim_paragraphs';
}

function rl_aim_tables_present(PDO $pdo): bool
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    try {
        $stmt = $pdo->prepare('SHOW TABLES LIKE ?');
        $stmt->execute([rl_aim_schema_table()]);
        $cache = (bool) $stmt->fetchColumn();
    } catch (Throwable) {
        $cache = false;
    }

    return $cache;
}

/**
 * True when paragraphs table uses edition_id (unified schema); false when legacy source_id exists.
 */
function rl_aim_uses_edition_id(PDO $pdo): bool
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    try {
        $stmt = $pdo->prepare('SHOW COLUMNS FROM resource_library_aim_paragraphs LIKE ?');
        $stmt->execute(['edition_id']);
        $cache = (bool) $stmt->fetchColumn();
    } catch (Throwable) {
        $cache = false;
    }

    return $cache;
}

function rl_aim_paragraphs_fk_column(PDO $pdo): string
{
    return rl_aim_uses_edition_id($pdo) ? 'edition_id' : 'source_id';
}

function rl_aim_runs_fk_column(PDO $pdo): string
{
    try {
        $stmt = $pdo->prepare('SHOW COLUMNS FROM resource_library_crawler_runs LIKE ?');
        $stmt->execute(['edition_id']);
        if ($stmt->fetchColumn()) {
            return 'edition_id';
        }
    } catch (Throwable) {
        // ignore
    }

    return 'source_id';
}

/**
 * @return array<string, mixed>|null
 */
function rl_aim_fetch_last_run(PDO $pdo, int $editionOrSourceId): ?array
{
    if ($editionOrSourceId <= 0 || !rl_aim_tables_present($pdo)) {
        return null;
    }
    $col = rl_aim_runs_fk_column($pdo);
    $sql = "
        SELECT id, {$col} AS edition_id, started_at, completed_at, run_status, pages_discovered, paragraphs_upserted, error_message
        FROM resource_library_crawler_runs
        WHERE {$col} = ?
        ORDER BY id DESC
        LIMIT 1
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$editionOrSourceId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

/**
 * Dashboard payload for admin UI / JSON API.
 *
 * @return array{
 *   ok: bool,
 *   schema: bool,
 *   slot: string,
 *   error?: string,
 *   source?: array<string, mixed>,
 *   counts?: array<string, int>,
 *   last_run?: array<string, mixed>|null
 * }
 */
function rl_aim_slot_dashboard(PDO $pdo, string $slot): array
{
    $slot = trim(strtolower($slot));
    $out = [
        'ok' => true,
        'schema' => false,
        'slot' => $slot,
    ];
    if ($slot !== 'aim') {
        $out['schema'] = false;
        $out['message'] = 'Database schema is only provisioned for the AIM (HTML) slot.';

        return $out;
    }
    try {
        if (!rl_aim_tables_present($pdo)) {
            return $out;
        }
        $out['schema'] = true;
        if (!rl_catalog_has_resource_type_column($pdo)) {
            $out['error'] = 'Run scripts/sql/resource_library_editions_extend_types.sql to add resource_type to editions.';

            return $out;
        }
        $edition = rl_catalog_fetch_crawler_edition_by_slot($pdo, 'aim');
        if (!$edition) {
            $out['error'] = 'AIM crawler edition missing; run scripts/sql/resource_library_aim_crawl.sql.';

            return $out;
        }
        $eid = (int) ($edition['id'] ?? 0);
        $fk = rl_aim_paragraphs_fk_column($pdo);
        $out['source'] = rl_catalog_crawler_row_as_source($edition);

        $stmt = $pdo->prepare("
            SELECT citation_status, COUNT(*) AS c
            FROM resource_library_aim_paragraphs
            WHERE {$fk} = ?
            GROUP BY citation_status
        ");
        $stmt->execute([$eid]);
        $counts = ['active' => 0, 'superseded' => 0, 'url_broken' => 0, 'total' => 0];
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $k = (string) ($r['citation_status'] ?? '');
            $n = (int) ($r['c'] ?? 0);
            if (isset($counts[$k])) {
                $counts[$k] = $n;
            }
            $counts['total'] += $n;
        }
        $out['counts'] = $counts;
        $runId = rl_aim_uses_edition_id($pdo) ? $eid : $eid;
        $out['last_run'] = rl_aim_fetch_last_run($pdo, $runId);
    } catch (Throwable $e) {
        $out['ok'] = false;
        $out['error'] = $e->getMessage();
    }

    return $out;
}
