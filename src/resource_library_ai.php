<?php
declare(strict_types=1);

/**
 * @return array<string, true>
 */
function rl_search_stopword_map(): array
{
    $w = [
        'a', 'an', 'the', 'what', 'which', 'who', 'whom', 'whose', 'where', 'when', 'why', 'how',
        'is', 'are', 'was', 'were', 'be', 'been', 'being', 'have', 'has', 'had', 'do', 'does', 'did',
        'will', 'would', 'could', 'should', 'may', 'might', 'must', 'shall', 'can', 'need', 'ought',
        'i', 'you', 'he', 'she', 'it', 'we', 'they', 'me', 'him', 'her', 'us', 'them',
        'this', 'that', 'these', 'those',
        'and', 'or', 'but', 'if', 'then', 'so', 'as', 'at', 'by', 'for', 'from', 'in', 'into', 'of', 'on', 'to', 'with',
        'about', 'above', 'after', 'against', 'before', 'between', 'through', 'during', 'under', 'over',
        'indicates', 'indicate', 'mean', 'means', 'called', 'define', 'definition', 'does', 'did',
    ];

    return array_fill_keys($w, true);
}

/**
 * Extract searchable tokens from a natural-language question.
 *
 * @return list<string>
 */
function rl_search_query_tokens(string $query): array
{
    $q = strtolower(trim($query));
    $q = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $q);
    if (!is_string($q) || $q === '') {
        return [];
    }
    $q = preg_replace('/\s+/u', ' ', $q);
    $stop = rl_search_stopword_map();
    $raw = explode(' ', (string)$q);
    $out = [];
    foreach ($raw as $t) {
        $t = trim($t);
        if ($t === '' || strlen($t) < 2) {
            continue;
        }
        if (isset($stop[$t])) {
            continue;
        }
        $out[] = $t;
    }

    return $out;
}

/**
 * Pick up to N distinctive tokens (longer words first) for AND-style LIKE scoring.
 *
 * @param list<string> $tokens
 * @return list<string>
 */
function rl_search_pick_keyword_tokens(array $tokens, int $max = 8): array
{
    if ($tokens === []) {
        return [];
    }
    $tokens = array_values(array_unique($tokens));
    usort($tokens, static function (string $a, string $b): int {
        return strlen($b) <=> strlen($a);
    });

    return array_slice($tokens, 0, $max);
}

/**
 * Shorter tokens first for BOOLEAN +term* (avoid requiring rare long words not in every paragraph).
 *
 * @param list<string> $tokens
 * @return list<string>
 */
function rl_search_boolean_prefix_tokens(array $tokens, int $max = 4): array
{
    if ($tokens === []) {
        return [];
    }
    $tokens = array_values(array_unique($tokens));
    usort($tokens, static function (string $a, string $b): int {
        return strlen($a) <=> strlen($b);
    });

    return array_slice($tokens, 0, $max);
}

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
    $tokens = rl_search_query_tokens($query);
    $keywords = rl_search_pick_keyword_tokens($tokens, 8);

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
        // continue
    }

    if ($keywords !== []) {
        $likeParts = [];
        $likeParams = [];
        foreach ($keywords as $t) {
            $likeParts[] = '(body_text LIKE ?)';
            $likeParams[] = '%' . str_replace(['%', '_', '\\'], ['\\%', '\\_', '\\\\'], $t) . '%';
        }
        $sumExpr = implode(' + ', $likeParts);
        $nKw = count($likeParams);
        $minScore = $nKw >= 3 ? 2 : ($nKw === 2 ? 2 : 1);
        if ($nKw >= 2) {
            try {
                $sql = "
                    SELECT block_key, chapter, block_local_id, body_text, section_path_json, sort_index, block_type, `level`,
                        {$sumExpr} AS kw_score
                    FROM resource_library_blocks
                    WHERE edition_id = ?
                      AND {$sumExpr} >= ?
                    ORDER BY kw_score DESC, sort_index ASC
                    LIMIT ?
                ";
                $stmt = $pdo->prepare($sql);
                $bind = array_merge($likeParams, [$editionId], $likeParams, [$minScore, $limit]);
                $stmt->execute($bind);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                if (is_array($rows) && $rows !== []) {
                    return $rows;
                }
            } catch (Throwable $e) {
                // continue
            }
        }
        if ($nKw === 1) {
            try {
                $stmt = $pdo->prepare('
                    SELECT block_key, chapter, block_local_id, body_text, section_path_json, sort_index, block_type, `level`
                    FROM resource_library_blocks
                    WHERE edition_id = ? AND body_text LIKE ?
                    ORDER BY sort_index ASC
                    LIMIT ?
                ');
                $stmt->execute([$editionId, $likeParams[0], $limit]);

                return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } catch (Throwable $e) {
                return [];
            }
        }
    }

    $boolToks = rl_search_boolean_prefix_tokens($tokens, 4);
    if ($boolToks !== []) {
        $boolParts = [];
        foreach ($boolToks as $t) {
            $safe = preg_replace('/[^\p{L}\p{N}]+/u', '', $t);
            if (!is_string($safe) || $safe === '' || strlen($safe) < 2) {
                continue;
            }
            $boolParts[] = '+' . $safe . '*';
        }
        $boolQuery = implode(' ', $boolParts);
        if ($boolQuery !== '') {
            try {
                $stmt = $pdo->prepare('
                    SELECT block_key, chapter, block_local_id, body_text, section_path_json, sort_index, block_type, `level`
                    FROM resource_library_blocks
                    WHERE edition_id = ?
                      AND MATCH(body_text) AGAINST (? IN BOOLEAN MODE)
                    ORDER BY sort_index ASC
                    LIMIT ?
                ');
                $stmt->execute([$editionId, $boolQuery, $limit]);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                if (is_array($rows) && $rows !== []) {
                    return $rows;
                }
            } catch (Throwable $e) {
                // continue
            }
        }
    }

    $like = '%' . str_replace(['%', '_', '\\'], ['\\%', '\\_', '\\\\'], $query) . '%';
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
