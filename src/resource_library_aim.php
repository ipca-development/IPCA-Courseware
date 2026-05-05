<?php
declare(strict_types=1);

/**
 * FAA AIM HTML crawler — database helpers and dashboard stats.
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
 * Pick the best crawler source row for a slot (prefer active, then newest id).
 *
 * @return array<string, mixed>|null
 */
function rl_aim_fetch_source_for_slot(PDO $pdo, string $slot, string $type = 'aim_html'): ?array
{
    if (!rl_aim_tables_present($pdo)) {
        return null;
    }
    $slot = trim(strtolower($slot));
    $type = trim(strtolower($type));
    if ($slot === '' || $type === '') {
        return null;
    }
    $stmt = $pdo->prepare('
        SELECT id, crawler_slot, crawler_type, label, allowed_url_prefix, effective_date, change_number, status, notes, created_at, updated_at
        FROM resource_library_crawler_sources
        WHERE crawler_slot = ? AND crawler_type = ?
        ORDER BY FIELD(status, \'active\', \'draft\', \'archived\'), id DESC
        LIMIT 1
    ');
    $stmt->execute([$slot, $type]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

/**
 * @return array<string, mixed>|null
 */
function rl_aim_fetch_last_run(PDO $pdo, int $sourceId): ?array
{
    if ($sourceId <= 0 || !rl_aim_tables_present($pdo)) {
        return null;
    }
    $stmt = $pdo->prepare('
        SELECT id, source_id, started_at, completed_at, run_status, pages_discovered, paragraphs_upserted, error_message
        FROM resource_library_crawler_runs
        WHERE source_id = ?
        ORDER BY id DESC
        LIMIT 1
    ');
    $stmt->execute([$sourceId]);
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
        $source = rl_aim_fetch_source_for_slot($pdo, 'aim', 'aim_html');
        if (!$source) {
            $out['error'] = 'AIM crawler source row missing; re-run scripts/sql/resource_library_aim_crawl.sql.';

            return $out;
        }
        $sid = (int) ($source['id'] ?? 0);
        $out['source'] = $source;

        $stmt = $pdo->prepare('
            SELECT citation_status, COUNT(*) AS c
            FROM resource_library_aim_paragraphs
            WHERE source_id = ?
            GROUP BY citation_status
        ');
        $stmt->execute([$sid]);
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
        $out['last_run'] = rl_aim_fetch_last_run($pdo, $sid);
    } catch (Throwable $e) {
        $out['ok'] = false;
        $out['error'] = $e->getMessage();
    }

    return $out;
}
