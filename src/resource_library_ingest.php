<?php
declare(strict_types=1);

require_once __DIR__ . '/resource_library_storage.php';

/**
 * True when $arr is a JSON-like sequential list (0..n-1), including empty.
 */
function rl_json_decoded_is_list(array $arr): bool
{
    if (function_exists('array_is_list')) {
        return array_is_list($arr);
    }
    $i = 0;
    foreach ($arr as $k => $_) {
        if ($k !== $i) {
            return false;
        }
        $i++;
    }

    return true;
}

/**
 * Normalize decoded source.json to a flat list of block rows.
 *
 * Supports:
 * - Top-level JSON array of block objects (PHAK-style).
 * - Canonical envelope: object with `chapters`, each chapter having `blocks` (Instrument Flying Handbook merges).
 * - Object with top-level `blocks` array.
 *
 * @return list<array<string, mixed>>
 */
function rl_normalize_resource_library_source_blocks(array $decoded): array
{
    if ($decoded === []) {
        return [];
    }

    if (rl_json_decoded_is_list($decoded)) {
        return array_values(array_filter($decoded, 'is_array'));
    }

    if (isset($decoded['chapters']) && is_array($decoded['chapters'])) {
        $out = [];
        foreach ($decoded['chapters'] as $ch) {
            if (!is_array($ch)) {
                continue;
            }
            $bl = $ch['blocks'] ?? null;
            if (!is_array($bl)) {
                continue;
            }
            foreach ($bl as $b) {
                if (is_array($b)) {
                    $out[] = $b;
                }
            }
        }

        return $out;
    }

    if (isset($decoded['blocks']) && is_array($decoded['blocks'])) {
        return array_values(array_filter($decoded['blocks'], 'is_array'));
    }

    throw new RuntimeException(
        'source.json must be a JSON array of blocks, or an object with "chapters" (each with "blocks"), or an object with top-level "blocks".'
    );
}

/**
 * Stable local id for indexing: prefer id, then original_id (canonical IFH exports).
 *
 * @param int|string $idx Foreach index used only when no id fields exist.
 */
function rl_resource_library_block_local_id(array $row, $idx): string
{
    $localId = trim((string)($row['id'] ?? ''));
    if ($localId === '') {
        $localId = trim((string)($row['original_id'] ?? ''));
    }
    if ($localId === '') {
        $localId = 'idx_' . $idx;
    }

    return $localId;
}

/**
 * Replace all blocks for an edition from on-disk source.json (array of block objects,
 * or canonical envelope with chapters[].blocks).
 *
 * @return array{imported: int, chapter_count: int}
 */
function rl_ingest_blocks_from_source_file(PDO $pdo, int $editionId): array
{
    if ($editionId <= 0) {
        throw new InvalidArgumentException('Invalid edition id');
    }

    $path = rl_source_json_path($editionId);
    if (!is_file($path)) {
        throw new RuntimeException('No source.json on disk for this edition. Upload JSON first.');
    }

    $raw = file_get_contents($path);
    if ($raw === false || $raw === '') {
        throw new RuntimeException('Could not read source.json');
    }

    try {
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (\JsonException $e) {
        throw new RuntimeException('Invalid JSON in source.json: ' . $e->getMessage());
    }

    if (!is_array($decoded)) {
        throw new RuntimeException('source.json root must be a JSON array or object');
    }

    $blocks = rl_normalize_resource_library_source_blocks($decoded);

    if ($blocks === []) {
        throw new RuntimeException('source.json contains no block objects to import');
    }

    $ins = $pdo->prepare('
        INSERT INTO resource_library_blocks (
            edition_id, block_key, chapter, block_local_id, section_path_json, block_type, `level`, body_text, sort_index, payload_json
        ) VALUES (?,?,?,?,?,?,?,?,?,?)
    ');

    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM resource_library_blocks WHERE edition_id = ?')->execute([$editionId]);

        $chapters = [];
        $imported = 0;
        foreach ($blocks as $idx => $row) {
            if (!is_array($row)) {
                continue;
            }

            $chapter = trim((string)($row['chapter'] ?? ''));
            $localId = rl_resource_library_block_local_id($row, $idx);
            if ($chapter === '') {
                $chapter = '_unknown';
            }

            $blockKey = $chapter . '|' . $localId;
            if (strlen($blockKey) > 380) {
                $blockKey = hash('sha256', $chapter . "\0" . $localId);
            }

            $sectionPath = $row['section_path'] ?? null;
            $sectionJson = null;
            if (is_array($sectionPath)) {
                $enc = json_encode($sectionPath, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $sectionJson = $enc !== false ? $enc : null;
            }

            $type = isset($row['type']) ? trim((string)$row['type']) : '';
            $typeDb = $type === '' ? null : $type;

            $level = null;
            if (array_key_exists('level', $row) && $row['level'] !== null && $row['level'] !== '') {
                $level = (int)$row['level'];
            }

            $textVal = $row['text'] ?? null;
            if (is_string($textVal)) {
                $body = $textVal;
            } elseif (is_array($textVal) || is_object($textVal)) {
                $enc = json_encode($textVal, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $body = $enc !== false ? $enc : '';
            } elseif ($textVal === null) {
                $body = '';
            } else {
                $body = (string)$textVal;
            }

            $payload = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($payload === false) {
                $payload = '{}';
            }

            $ins->execute([
                $editionId,
                $blockKey,
                $chapter,
                $localId,
                $sectionJson,
                $typeDb,
                $level,
                $body,
                $imported,
                $payload,
            ]);

            $chapters[$chapter] = true;
            $imported++;
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return [
        'imported' => $imported,
        'chapter_count' => count($chapters),
    ];
}

/**
 * @return array{table_ok: bool, row_count: int, chapter_count: int, error?: string}
 */
function rl_blocks_stats(PDO $pdo, int $editionId): array
{
    if ($editionId <= 0) {
        return ['table_ok' => false, 'row_count' => 0, 'chapter_count' => 0, 'error' => 'bad_id'];
    }
    try {
        $st = $pdo->prepare('SELECT COUNT(*) FROM resource_library_blocks WHERE edition_id = ?');
        $st->execute([$editionId]);
        $n = (int)$st->fetchColumn();

        $st2 = $pdo->prepare('SELECT COUNT(DISTINCT chapter) FROM resource_library_blocks WHERE edition_id = ?');
        $st2->execute([$editionId]);
        $c = (int)$st2->fetchColumn();

        return ['table_ok' => true, 'row_count' => $n, 'chapter_count' => $c];
    } catch (Throwable $e) {
        $msg = $e->getMessage();
        $missing = (stripos($msg, "doesn't exist") !== false) || (stripos($msg, 'Unknown table') !== false);

        return [
            'table_ok' => false,
            'row_count' => 0,
            'chapter_count' => 0,
            'error' => $missing ? 'table_missing' : $msg,
        ];
    }
}

/**
 * Delete indexed blocks for an edition (e.g. when source.json is removed).
 */
function rl_delete_blocks_for_edition(PDO $pdo, int $editionId): void
{
    if ($editionId <= 0) {
        return;
    }
    try {
        $pdo->prepare('DELETE FROM resource_library_blocks WHERE edition_id = ?')->execute([$editionId]);
    } catch (Throwable $e) {
        // Table may not exist yet
    }
}

/**
 * Row counts per edition for UI badges (e.g. "Validated").
 *
 * @return array<int, int>
 */
function rl_block_counts_by_edition_map(PDO $pdo): array
{
    try {
        $stmt = $pdo->query('SELECT edition_id, COUNT(*) AS c FROM resource_library_blocks GROUP BY edition_id');
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            $out[(int)($r['edition_id'] ?? 0)] = (int)($r['c'] ?? 0);
        }

        return $out;
    } catch (Throwable $e) {
        return [];
    }
}
