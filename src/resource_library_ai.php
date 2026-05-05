<?php
declare(strict_types=1);

/**
 * Retrieve resource-library blocks for AI context (keyword / FULLTEXT search).
 *
 * @return list<array<string, mixed>>
 */
function rl_ai_search_resource_blocks(PDO $pdo, int $editionId, string $query, int $limit = 15): array
{
    $query = trim($query);
    if ($query === '' || $editionId <= 0) {
        return [];
    }

    $limit = max(1, min(50, $limit));

    try {
        $stmt = $pdo->prepare('
            SELECT block_key, chapter, block_local_id, body_text, section_path_json, sort_index, block_type, `level`
            FROM resource_library_blocks
            WHERE edition_id = ?
              AND MATCH(body_text) AGAINST (? IN NATURAL LANGUAGE MODE)
            ORDER BY MATCH(body_text) AGAINST (? IN NATURAL LANGUAGE MODE) DESC
            LIMIT ?
        ');
        $stmt->execute([$editionId, $query, $query, $limit]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (is_array($rows) && $rows !== []) {
            return $rows;
        }
    } catch (Throwable $e) {
        // FULLTEXT unsupported or no rows — try LIKE fallback
    }

    $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $query) . '%';
    try {
        $stmt = $pdo->prepare('
            SELECT block_key, chapter, block_local_id, body_text, section_path_json, sort_index, block_type, `level`
            FROM resource_library_blocks
            WHERE edition_id = ? AND body_text LIKE ?
            ORDER BY sort_index ASC
            LIMIT ?
        ');
        $stmt->execute([$editionId, $like, $limit]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Fetch full payload_json rows by primary ids (for expanding context).
 *
 * @param list<int> $ids
 * @return list<array<string, mixed>>
 */
function rl_ai_fetch_blocks_by_ids(PDO $pdo, int $editionId, array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if ($ids === [] || $editionId <= 0) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $params = array_merge([$editionId], $ids);
    try {
        $sql = 'SELECT id, block_key, chapter, body_text, payload_json, sort_index
            FROM resource_library_blocks
            WHERE edition_id = ? AND id IN (' . $placeholders . ')
            ORDER BY sort_index ASC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}
