<?php
declare(strict_types=1);

require_once __DIR__ . '/resource_library_catalog.php';
require_once __DIR__ . '/resource_library_pdf_storage.php';

function rl_pdf_tables_ok(PDO $pdo): bool
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    try {
        $pdo->query('SELECT 1 FROM resource_library_pdf_batches LIMIT 1');
        $pdo->query('SELECT 1 FROM resource_library_pdf_articles_staging LIMIT 1');
        $pdo->query('SELECT 1 FROM resource_library_pdf_article_diffs LIMIT 1');
        $cache = true;
    } catch (Throwable) {
        $cache = false;
    }

    return $cache;
}

/**
 * @return list<string>
 */
function rl_pdf_normalize_applicability_tags(mixed $raw): array
{
    if (is_string($raw)) {
        $parts = preg_split('/[\s,;]+/', $raw) ?: [];
    } elseif (is_array($raw)) {
        $parts = $raw;
    } else {
        return [];
    }
    $out = [];
    foreach ($parts as $p) {
        $t = strtolower(trim((string) $p));
        $t = preg_replace('/[^a-z0-9_\-]+/', '_', $t) ?? $t;
        $t = trim($t, '_');
        if ($t !== '' && strlen($t) <= 64) {
            $out[$t] = $t;
        }
    }

    return array_values($out);
}

function rl_pdf_validate_official_url(string $url): ?string
{
    $url = rl_source_verify_sanitize_verify_url_input(trim($url));
    if ($url === '') {
        return 'Official PDF URL is required.';
    }
    if (!preg_match('#^https?://#i', $url)) {
        return 'URL must start with http:// or https://';
    }
    if (strlen($url) > 2048) {
        return 'URL is too long (max 2048 characters).';
    }

    return null;
}

/**
 * Display status for admin cards (not stored as single DB enum on edition).
 *
 * @param array<string, mixed> $extra
 * @return string draft|monitoring|changed|ready_for_review|live|failed
 */
function rl_pdf_compute_display_status(array $row, array $extra, ?array $latestBatch, ?array $readyBatch): string
{
    $editionStatus = strtolower(trim((string) ($row['status'] ?? 'draft')));
    if ($editionStatus === 'archived') {
        return 'draft';
    }
    if ($latestBatch !== null && (string) ($latestBatch['status'] ?? '') === 'failed') {
        return 'failed';
    }
    if ($readyBatch !== null) {
        return 'ready_for_review';
    }
    if ($editionStatus === 'live') {
        return 'live';
    }
    $mon = is_array($extra['pdf_monitor_state'] ?? null) ? $extra['pdf_monitor_state'] : [];
    if (!empty($mon['last_error']) && empty($mon['last_downloaded_at'])) {
        return 'failed';
    }
    if (!empty($extra['source_verify_state']['change_detected']) || !empty($mon['change_detected'])) {
        return 'changed';
    }
    $interval = rl_source_verify_normalize_interval((string) ($extra['source_verify_interval'] ?? 'off'));
    if ($interval !== 'off' && trim((string) ($extra['official_pdf_url'] ?? '')) !== '') {
        return 'monitoring';
    }

    return 'draft';
}

/**
 * @return array<string, mixed>|null
 */
function rl_pdf_fetch_latest_batch(PDO $pdo, int $editionId): ?array
{
    if ($editionId <= 0) {
        return null;
    }
    $st = $pdo->prepare('SELECT * FROM resource_library_pdf_batches WHERE edition_id = ? ORDER BY id DESC LIMIT 1');
    $st->execute([$editionId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

/**
 * @return array<string, mixed>|null
 */
function rl_pdf_fetch_ready_for_review_batch(PDO $pdo, int $editionId): ?array
{
    if ($editionId <= 0) {
        return null;
    }
    $st = $pdo->prepare("
        SELECT * FROM resource_library_pdf_batches
        WHERE edition_id = ? AND status = 'ready_for_review'
        ORDER BY id DESC LIMIT 1
    ");
    $st->execute([$editionId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

/**
 * @return array<string, mixed>|null
 */
function rl_pdf_fetch_published_batch(PDO $pdo, int $editionId): ?array
{
    if ($editionId <= 0) {
        return null;
    }
    $st = $pdo->prepare("
        SELECT * FROM resource_library_pdf_batches
        WHERE edition_id = ? AND status = 'published'
        ORDER BY published_at DESC, id DESC LIMIT 1
    ");
    $st->execute([$editionId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

/**
 * @return array{edition: array<string, mixed>, source: array<string, mixed>, display_status: string, article_count: int, ready_batch_id: int, published_sha256: string}
 */
function rl_pdf_build_source_summary(PDO $pdo, array $editionRow): array
{
    $eid = (int) ($editionRow['id'] ?? 0);
    $extra = rl_catalog_decode_extra(isset($editionRow['extra_config_json']) ? (string) $editionRow['extra_config_json'] : null);
    $latest = rl_pdf_fetch_latest_batch($pdo, $eid);
    $ready = rl_pdf_fetch_ready_for_review_batch($pdo, $eid);
    $published = rl_pdf_fetch_published_batch($pdo, $eid);
    $articleCount = 0;
    if ($published !== null) {
        $articleCount = (int) ($published['article_count'] ?? 0);
    } elseif ($ready !== null) {
        $articleCount = (int) ($ready['article_count'] ?? 0);
    } elseif ($latest !== null) {
        $articleCount = (int) ($latest['article_count'] ?? 0);
    }

    $display = rl_pdf_compute_display_status($editionRow, $extra, $latest, $ready);
    $src = rl_catalog_pdf_row_as_source($editionRow);
    if ($published !== null) {
        $src['published_file_sha256'] = (string) ($published['file_sha256'] ?? '');
    }

    return [
        'edition' => $editionRow,
        'source' => $src,
        'display_status' => $display,
        'article_count' => $articleCount,
        'ready_batch_id' => $ready !== null ? (int) ($ready['id'] ?? 0) : 0,
        'published_sha256' => $published !== null ? (string) ($published['file_sha256'] ?? '') : (string) ($extra['published_file_sha256'] ?? ''),
        'latest_batch' => $latest,
    ];
}

/**
 * @return list<array<string, mixed>>
 */
function rl_pdf_list_sources(PDO $pdo): array
{
    if (!rl_catalog_has_resource_type_column($pdo)) {
        return [];
    }
    $stmt = $pdo->prepare("
        SELECT id, title, revision_code, revision_date, status, thumbnail_path, work_code, sort_order,
               resource_type, extra_config_json, created_at, updated_at
        FROM resource_library_editions
        WHERE resource_type = ?
        ORDER BY FIELD(status, 'live', 'draft', 'archived'), sort_order ASC, title ASC, id ASC
    ");
    $stmt->execute([RL_RESOURCE_PDF_BOOK]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($rows)) {
        return [];
    }
    $out = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $sum = rl_pdf_build_source_summary($pdo, $row);
        $out[] = [
            'id' => (int) ($row['id'] ?? 0),
            'edition' => $row,
            'source' => $sum['source'],
            'display_status' => $sum['display_status'],
            'article_count' => $sum['article_count'],
            'ready_batch_id' => $sum['ready_batch_id'],
            'published_sha256' => $sum['published_sha256'],
        ];
    }

    return $out;
}

function rl_pdf_excerpt(string $text, int $max = 480): string
{
    $t = rl_pdf_sanitize_utf8(trim(preg_replace('/\s+/u', ' ', $text) ?? $text));
    if ($t === '') {
        return '';
    }
    $ellipsis = '…';
    $maxChars = max(1, $max - mb_strlen($ellipsis, 'UTF-8'));
    if (mb_strlen($t, 'UTF-8') <= $maxChars) {
        return $t;
    }

    return mb_substr($t, 0, $maxChars, 'UTF-8') . $ellipsis;
}

/**
 * Promote staging articles from a published batch into resource_library_blocks (live retrieval).
 *
 * @return array{blocks: int}
 */
function rl_pdf_publish_batch_to_blocks(PDO $pdo, int $editionId, int $batchId, string $officialUrl, array $applicabilityTags): array
{
    if ($editionId <= 0 || $batchId <= 0) {
        throw new InvalidArgumentException('Invalid edition or batch id');
    }
    $st = $pdo->prepare('
        SELECT article_key, article_title, hierarchy_path, canonical_text, legal_state, amendment_notes, sort_order
        FROM resource_library_pdf_articles_staging
        WHERE batch_id = ? AND edition_id = ?
        ORDER BY sort_order ASC, article_key ASC
    ');
    $st->execute([$batchId, $editionId]);
    $articles = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($articles) || $articles === []) {
        throw new RuntimeException('No staging articles to publish for this batch');
    }

    $ins = $pdo->prepare('
        INSERT INTO resource_library_blocks (
            edition_id, block_key, chapter, block_local_id, section_path_json, block_type, `level`, body_text, sort_index, payload_json
        ) VALUES (?,?,?,?,?,?,?,?,?,?)
    ');

    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM resource_library_blocks WHERE edition_id = ?')->execute([$editionId]);
        $sort = 0;
        foreach ($articles as $row) {
            if (!is_array($row)) {
                continue;
            }
            $akey = trim((string) ($row['article_key'] ?? ''));
            if ($akey === '') {
                continue;
            }
            $title = trim((string) ($row['article_title'] ?? ''));
            $body = trim((string) ($row['canonical_text'] ?? ''));
            $notes = trim((string) ($row['amendment_notes'] ?? ''));
            if ($notes !== '') {
                $body .= "\n\n[Amendment notes]\n" . $notes;
            }
            $blockKey = 'pdf|' . $akey;
            if (strlen($blockKey) > 380) {
                $blockKey = 'pdf|' . hash('sha256', $akey);
            }
            $chapter = trim((string) ($row['hierarchy_path'] ?? ''));
            if ($chapter === '') {
                $chapter = 'legal';
            }
            if (strlen($chapter) > 128) {
                $chapter = substr($chapter, 0, 128);
            }
            $payload = [
                'type' => 'pdf_article',
                'id' => $akey,
                'article_key' => $akey,
                'title' => $title !== '' ? $title : $akey,
                'chapter' => $chapter,
                'legal_state' => (string) ($row['legal_state'] ?? 'unknown'),
                'official_pdf_url' => $officialUrl,
                'applicability_tags' => $applicabilityTags,
                'text' => $body,
            ];
            $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($payloadJson === false) {
                $payloadJson = '{}';
            }
            $sectionJson = json_encode([$chapter], JSON_UNESCAPED_UNICODE);
            if ($sectionJson === false) {
                $sectionJson = null;
            }
            $ins->execute([
                $editionId,
                $blockKey,
                $chapter,
                $akey,
                $sectionJson,
                'pdf_article',
                null,
                $body,
                $sort,
                $payloadJson,
            ]);
            $sort++;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return ['blocks' => $sort];
}
