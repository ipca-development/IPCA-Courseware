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

/**
 * Columns present on resource_library_crawler_runs (legacy installs used source_id only).
 *
 * @return array{edition_id: bool, source_id: bool}
 */
function rl_crawler_runs_fk_shape(PDO $pdo): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $cache = ['edition_id' => false, 'source_id' => false];
    try {
        $stmt = $pdo->prepare('SHOW COLUMNS FROM resource_library_crawler_runs LIKE ?');
        $stmt->execute(['edition_id']);
        $cache['edition_id'] = (bool) $stmt->fetchColumn();
        $stmt->execute(['source_id']);
        $cache['source_id'] = (bool) $stmt->fetchColumn();
    } catch (Throwable) {
        // leave false
    }

    return $cache;
}

function rl_catalog_crawler_sources_table_exists(PDO $pdo): bool
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'resource_library_crawler_sources'");
        $cache = (bool) $stmt->fetchColumn();
    } catch (Throwable) {
        $cache = false;
    }

    return $cache;
}

/**
 * Legacy DBs store crawler runs under resource_library_crawler_sources.id, not edition id.
 */
function rl_catalog_resolve_legacy_crawler_source_id_for_edition(PDO $pdo, int $editionId): ?int
{
    if ($editionId <= 0 || !rl_catalog_crawler_sources_table_exists($pdo)) {
        return null;
    }
    $row = rl_catalog_fetch_edition($pdo, $editionId);
    if ($row === null) {
        return null;
    }
    if (rl_catalog_has_resource_type_column($pdo)) {
        $rt = rl_catalog_normalize_resource_type(isset($row['resource_type']) ? (string) $row['resource_type'] : null);
        if ($rt !== RL_RESOURCE_CRAWLER) {
            return null;
        }
    }
    $extra = rl_catalog_decode_extra(isset($row['extra_config_json']) ? (string) $row['extra_config_json'] : null);
    $slot = strtolower(trim((string) ($extra['crawler_slot'] ?? '')));
    if ($slot === '') {
        return null;
    }
    try {
        $stmt = $pdo->prepare('
            SELECT id
            FROM resource_library_crawler_sources
            WHERE LOWER(TRIM(crawler_slot)) = ?
            ORDER BY id ASC
            LIMIT 1
        ');
        $stmt->execute([$slot]);
        $id = $stmt->fetchColumn();

        return $id !== false ? (int) $id : null;
    } catch (Throwable) {
        return null;
    }
}

/**
 * Insert resource_library_crawler_runs row (running). Supports edition_id-only, source_id-only, or both.
 *
 * @throws RuntimeException when FK columns cannot be satisfied
 */
function rl_aim_insert_crawler_run_started(PDO $pdo, int $editionId, string $metaJson): int
{
    $shape = rl_crawler_runs_fk_shape($pdo);
    if (!$shape['edition_id'] && !$shape['source_id']) {
        throw new RuntimeException('resource_library_crawler_runs has neither edition_id nor source_id.');
    }

    $sourceId = null;
    if ($shape['source_id']) {
        $sourceId = rl_catalog_resolve_legacy_crawler_source_id_for_edition($pdo, $editionId);
        if ($sourceId === null || $sourceId <= 0) {
            throw new RuntimeException(
                'Cannot start crawler run: resource_library_crawler_runs.source_id must reference '
                . 'resource_library_crawler_sources.id, but no source row matched this edition’s crawler_slot '
                . '(legacy schema). Either add/sync a crawler_sources row for the AIM slot, or migrate '
                . 'resource_library_crawler_runs to edition_id-only per scripts/sql/resource_library_aim_crawl.sql.'
            );
        }
    }

    if ($shape['edition_id'] && $shape['source_id']) {
        $stmt = $pdo->prepare('
            INSERT INTO resource_library_crawler_runs (edition_id, source_id, run_status, pages_discovered, paragraphs_upserted, meta_json)
            VALUES (?, ?, \'running\', 0, 0, ?)
        ');
        $stmt->execute([$editionId, $sourceId, $metaJson]);
    } elseif ($shape['edition_id']) {
        $stmt = $pdo->prepare('
            INSERT INTO resource_library_crawler_runs (edition_id, run_status, pages_discovered, paragraphs_upserted, meta_json)
            VALUES (?, \'running\', 0, 0, ?)
        ');
        $stmt->execute([$editionId, $metaJson]);
    } else {
        $stmt = $pdo->prepare('
            INSERT INTO resource_library_crawler_runs (source_id, run_status, pages_discovered, paragraphs_upserted, meta_json)
            VALUES (?, \'running\', 0, 0, ?)
        ');
        $stmt->execute([$sourceId, $metaJson]);
    }

    return (int) $pdo->lastInsertId();
}

/**
 * @return array<string, mixed>|null
 */
function rl_aim_fetch_last_run(PDO $pdo, int $editionId): ?array
{
    if ($editionId <= 0 || !rl_aim_tables_present($pdo)) {
        return null;
    }
    $shape = rl_crawler_runs_fk_shape($pdo);
    if ($shape['edition_id']) {
        $col = 'edition_id';
        $lookupId = $editionId;
    } elseif ($shape['source_id']) {
        $col = 'source_id';
        $lookupId = (int) (rl_catalog_resolve_legacy_crawler_source_id_for_edition($pdo, $editionId) ?? 0);
    } else {
        return null;
    }
    if ($lookupId <= 0) {
        return null;
    }
    $sql = "
        SELECT id, {$col} AS edition_id, started_at, completed_at, run_status, pages_discovered, paragraphs_upserted, error_message
        FROM resource_library_crawler_runs
        WHERE {$col} = ?
        ORDER BY id DESC
        LIMIT 1
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$lookupId]);
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
        $out['last_run'] = rl_aim_fetch_last_run($pdo, $eid);
    } catch (Throwable $e) {
        $out['ok'] = false;
        $out['error'] = $e->getMessage();
    }

    return $out;
}
