<?php
declare(strict_types=1);

require_once __DIR__ . '/resource_library_storage.php';

/**
 * Replace all blocks for an edition from on-disk source.json (array of block objects).
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
        $blocks = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (\JsonException $e) {
        throw new RuntimeException('Invalid JSON in source.json: ' . $e->getMessage());
    }

    if (!is_array($blocks)) {
        throw new RuntimeException('source.json root must be a JSON array of blocks');
    }

    if ($blocks === []) {
        throw new RuntimeException('source.json array is empty');
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
            $localId = trim((string)($row['id'] ?? ''));
            if ($localId === '') {
                $localId = 'idx_' . $idx;
            }
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
