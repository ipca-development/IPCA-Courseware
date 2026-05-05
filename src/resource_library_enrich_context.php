<?php
declare(strict_types=1);

require_once __DIR__ . '/resource_library_ai.php';
require_once __DIR__ . '/resource_library_catalog.php';

/**
 * Resolve which resource_library_editions.id to use for enrichment context.
 * Only editions with status = 'live' are used (Resource Library admin Live toggle).
 *
 * - If $explicitId > 0 and a matching live row exists, use it.
 * - Else CW_RESOURCE_LIBRARY_ENRICH_EDITION_ID env (if that id is live).
 * - Else first live json_book edition by sort_order.
 *
 * @return int 0 if none available
 */
function rl_enrich_resolve_edition_id(PDO $pdo, ?int $explicitId): int
{
    $bookOnly = rl_catalog_has_resource_type_column($pdo)
        ? " AND COALESCE(NULLIF(TRIM(resource_type), ''), 'json_book') = 'json_book'"
        : '';

    if ($explicitId !== null && $explicitId > 0) {
        try {
            $st = $pdo->prepare("SELECT id FROM resource_library_editions WHERE id = ? AND status = 'live'" . $bookOnly . ' LIMIT 1');
            $st->execute([$explicitId]);
            $found = (int)$st->fetchColumn();
            if ($found > 0) {
                return $found;
            }
        } catch (Throwable $e) {
            return 0;
        }
    }

    $env = (int)(getenv('CW_RESOURCE_LIBRARY_ENRICH_EDITION_ID') ?: 0);
    if ($env > 0) {
        try {
            $st = $pdo->prepare("SELECT id FROM resource_library_editions WHERE id = ? AND status = 'live'" . $bookOnly . ' LIMIT 1');
            $st->execute([$env]);
            $found = (int)$st->fetchColumn();
            if ($found > 0) {
                return $found;
            }
        } catch (Throwable $e) {
            // continue
        }
    }

    try {
        $st = $pdo->query("
            SELECT id FROM resource_library_editions
            WHERE status = 'live'" . $bookOnly . "
            ORDER BY sort_order ASC, id ASC
            LIMIT 1
        ");
        $id = (int)$st->fetchColumn();

        return $id > 0 ? $id : 0;
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * Build a short search string from slide + lesson titles (for PHAK retrieval).
 */
function rl_enrich_search_hint_for_slide(PDO $pdo, int $slideId): string
{
    if ($slideId <= 0) {
        return '';
    }
    try {
        $st = $pdo->prepare('
            SELECT
              TRIM(COALESCE(s.title, "")) AS slide_title,
              TRIM(COALESCE(l.title, "")) AS lesson_title
            FROM slides s
            INNER JOIN lessons l ON l.id = s.lesson_id
            WHERE s.id = ?
            LIMIT 1
        ');
        $st->execute([$slideId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return '';
        }
        $parts = array_filter([
            (string)($row['slide_title'] ?? ''),
            (string)($row['lesson_title'] ?? ''),
        ], static fn (string $s): bool => $s !== '');
        $q = implode(' ', $parts);
        if (strlen($q) > 400) {
            $q = substr($q, 0, 400);
        }

        return trim($q);
    } catch (Throwable $e) {
        return '';
    }
}

/**
 * Fetch ranked PHAK (or other) blocks and format as plain text for a vision / text prompt.
 *
 * @return string Empty if edition invalid, no rows, or search returns nothing.
 */
function rl_enrich_context_pack_for_slide(PDO $pdo, int $editionId, string $searchHint, int $maxTotalChars = 12000, int $maxBlocks = 14): string
{
    if ($editionId <= 0) {
        return '';
    }
    $hint = trim($searchHint);
    if ($hint === '') {
        return '';
    }

    try {
        $chk = $pdo->prepare('SELECT COUNT(*) FROM resource_library_blocks WHERE edition_id = ?');
        $chk->execute([$editionId]);
        if ((int)$chk->fetchColumn() <= 0) {
            return '';
        }
    } catch (Throwable $e) {
        return '';
    }

    $hits = rl_ai_search_resource_blocks($pdo, $editionId, $hint, $maxBlocks);
    if ($hits === []) {
        return '';
    }

    $lines = [
        '--- Indexed handbook excerpts (database retrieval; cite chapter + block id when used) ---',
    ];
    $used = strlen($lines[0]) + 8;
    foreach ($hits as $h) {
        if (!is_array($h)) {
            continue;
        }
        $ch = (string)($h['chapter'] ?? '');
        $bid = (string)($h['block_local_id'] ?? '');
        $body = (string)($h['body_text'] ?? '');
        if ($body === '') {
            continue;
        }
        if (strlen($body) > 1400) {
            $body = substr($body, 0, 1400) . '…';
        }
        $chunk = '[' . $ch . ' / ' . $bid . "]\n" . $body;
        if ($used + strlen($chunk) + 4 > $maxTotalChars) {
            break;
        }
        $lines[] = $chunk;
        $used += strlen($chunk) + 2;
    }

    if (count($lines) <= 1) {
        return '';
    }

    return implode("\n\n", $lines);
}
