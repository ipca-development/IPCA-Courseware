<?php
declare(strict_types=1);

require_once __DIR__ . '/resource_library_pdf.php';

/** Excerpt length for diff rows. */
const RL_PDF_DIFF_EXCERPT_LEN = 600;

/**
 * Extract plain text from a PDF file using pdftotext.
 */
function rl_pdf_extract_text_from_file(string $pdfAbsolutePath): string
{
    $probe = rl_pdf_pdftotext_probe();
    if (!$probe['available'] || $probe['path'] === null) {
        throw new RuntimeException(rl_pdf_pdftotext_required_error());
    }
    if (!is_file($pdfAbsolutePath) || !is_readable($pdfAbsolutePath)) {
        throw new RuntimeException('PDF file is not readable: ' . $pdfAbsolutePath);
    }

    $outTmp = $pdfAbsolutePath . '.extracted.' . bin2hex(random_bytes(4)) . '.txt';
    $cmd = escapeshellarg($probe['path'])
        . ' -layout -enc UTF-8 '
        . escapeshellarg($pdfAbsolutePath) . ' '
        . escapeshellarg($outTmp) . ' 2>&1';
    $output = [];
    $code = 0;
    exec($cmd, $output, $code);
    if ($code !== 0 || !is_file($outTmp)) {
        @unlink($outTmp);
        $msg = implode("\n", $output);

        throw new RuntimeException('pdftotext failed' . ($msg !== '' ? ': ' . $msg : ''));
    }
    $text = file_get_contents($outTmp);
    @unlink($outTmp);
    if ($text === false || trim($text) === '') {
        throw new RuntimeException('pdftotext produced empty text — PDF may be scanned/image-only');
    }

    return rl_pdf_sanitize_utf8($text);
}

/**
 * Download PDF bytes from an official URL (HTTPS preferred).
 */
function rl_pdf_download_url(string $url, int $timeoutSec = 120): string
{
    $url = trim($url);
    $err = rl_pdf_validate_official_url($url);
    if ($err !== null) {
        throw new RuntimeException($err);
    }
    if (!function_exists('curl_init')) {
        throw new RuntimeException('cURL is required to download PDFs');
    }

    $buf = '';
    $writer = static function ($ch, string $data) use (&$buf): int {
        $buf .= $data;
        if (strlen($buf) > RL_PDF_MAX_DOWNLOAD_BYTES) {
            return 0;
        }

        return strlen($data);
    };

    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('curl_init failed');
    }
    curl_setopt_array($ch, [
        CURLOPT_HTTPGET => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => $timeoutSec,
        CURLOPT_CONNECTTIMEOUT => min(20, $timeoutSec),
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT => 'IPCA-ResourceLibrary/1.0 (PDF monitor)',
        CURLOPT_WRITEFUNCTION => $writer,
    ]);
    $ok = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr = curl_error($ch);
    if ($code < 200 || $code >= 400 || $buf === '') {
        throw new RuntimeException('PDF download failed HTTP ' . $code . ($cerr !== '' ? ' · ' . $cerr : ''));
    }
    if (!$ok && strlen($buf) >= RL_PDF_MAX_DOWNLOAD_BYTES) {
        throw new RuntimeException('PDF exceeds maximum allowed size');
    }

    return $buf;
}

/**
 * @return array{batch_id: int, sha256: string, unchanged: bool}
 */
function rl_pdf_download_and_create_batch(PDO $pdo, int $editionId, string $url, bool $skipIfShaExists = true): array
{
    if ($editionId <= 0) {
        throw new InvalidArgumentException('Invalid edition id');
    }
    if (!rl_pdf_tables_ok($pdo)) {
        throw new RuntimeException('Apply scripts/sql/resource_library_pdf_crawler.sql first');
    }

    $binary = rl_pdf_download_url($url);
    $sha = hash('sha256', $binary);
    if ($sha === false) {
        throw new RuntimeException('Could not hash PDF');
    }

    if ($skipIfShaExists) {
        $dup = $pdo->prepare('SELECT id FROM resource_library_pdf_batches WHERE edition_id = ? AND file_sha256 = ? LIMIT 1');
        $dup->execute([$editionId, $sha]);
        $existingId = (int) $dup->fetchColumn();
        if ($existingId > 0) {
            return ['batch_id' => $existingId, 'sha256' => $sha, 'unchanged' => true];
        }
    }

    $ins = $pdo->prepare('
        INSERT INTO resource_library_pdf_batches (
            edition_id, official_pdf_url, storage_relpath, file_sha256, file_size_bytes, status, downloaded_at
        ) VALUES (?, ?, \'\', ?, ?, \'downloaded\', UTC_TIMESTAMP())
    ');
    $ins->execute([$editionId, $url, $sha, strlen($binary)]);
    $batchId = (int) $pdo->lastInsertId();
    if ($batchId <= 0) {
        throw new RuntimeException('Could not create PDF batch row');
    }

    $stored = rl_pdf_store_downloaded_file($editionId, $batchId, $binary);
    $pdo->prepare('UPDATE resource_library_pdf_batches SET storage_relpath = ?, file_size_bytes = ? WHERE id = ?')
        ->execute([$stored['relpath'], $stored['size'], $batchId]);

    return ['batch_id' => $batchId, 'sha256' => $sha, 'unchanged' => false];
}

function rl_pdf_normalize_ws(string $s): string
{
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s) ?? $s;

    return rl_pdf_sanitize_utf8(trim(preg_replace('/\s+/u', ' ', $s) ?? $s));
}

function rl_pdf_strip_page_noise(string $text): string
{
    $text = preg_replace('/Pagina\s+\d+\s+van\s+\d+/iu', '', $text) ?? $text;
    $text = preg_replace('/Copyright\s+Belgisch\s+Staatsblad/iu', '', $text) ?? $text;

    return $text;
}

/**
 * Normalize article key from marker match groups.
 */
function rl_pdf_normalize_article_key(string $rawId, string $suffix = ''): string
{
    $id = strtoupper(trim($rawId));
    $id = preg_replace('/\s+/', '_', $id) ?? $id;
    $suf = strtoupper(trim($suffix));
    if ($suf !== '') {
        $suf = preg_replace('/\s+/', '_', $suf) ?? $suf;
        $id .= '_' . $suf;
    }
    $id = preg_replace('/[^A-Z0-9_\-]+/', '_', $id) ?? $id;
    $id = trim($id, '_');

    return $id !== '' ? $id : 'UNKNOWN';
}

/**
 * JUSTEL PDFs repeat "Art. …" in the TOC and cross-references; keep one row per key unless body differs.
 *
 * @param list<array<string, mixed>> $articles
 * @return list<array<string, mixed>>
 */
function rl_pdf_dedupe_parsed_articles(array $articles): array
{
    /** @var array<string, array{hash: string, seq: int}> $seen */
    $seen = [];
    $out = [];

    foreach ($articles as $a) {
        if (!is_array($a)) {
            continue;
        }
        $key = (string) ($a['article_key'] ?? '');
        if ($key === '') {
            continue;
        }
        $hash = (string) ($a['content_hash'] ?? '');

        if (!isset($seen[$key])) {
            $seen[$key] = ['hash' => $hash, 'seq' => 1];
            $out[] = $a;
            continue;
        }

        if ($hash !== '' && $hash === $seen[$key]['hash']) {
            continue;
        }

        $seen[$key]['seq']++;
        $suffix = '__' . $seen[$key]['seq'];
        $unique = $key;
        if (strlen($unique) + strlen($suffix) > 190) {
            $unique = substr($unique, 0, 190 - strlen($suffix));
        }
        $unique .= $suffix;
        $a['article_key'] = $unique;
        $a['hierarchy_path'] = 'legal/' . $unique;
        $out[] = $a;
    }

    return $out;
}

/**
 * Detect legal_state from article heading line and body.
 */
function rl_pdf_detect_legal_state(string $headingLine, string $body): string
{
    $h = strtoupper($headingLine);
    if (str_contains($h, 'TOEKOMSTIG RECHT') || str_contains($h, 'TOEKOMSTIG_RECHT')) {
        return 'future_law';
    }
    if (str_contains($h, 'DUITSTALIGE_GEMEENSCHAP') || str_contains($h, 'DUITSTALIGE GEMEENSCHAP')) {
        return 'language_variant';
    }
    if (preg_match('/<Opgeheven\s+bij/i', $body) || preg_match('/Opgeheven\s+bij/iu', $body)) {
        return 'repealed';
    }

    return 'active';
}

/**
 * Split amendment notes after a line of dashes.
 *
 * @return array{body: string, notes: string}
 */
function rl_pdf_split_amendment_notes(string $chunk): array
{
    $parts = preg_split('/\n-{5,}\n/u', $chunk, 2);
    if (!is_array($parts) || count($parts) < 2) {
        return ['body' => trim($chunk), 'notes' => ''];
    }

    return [
        'body' => trim((string) $parts[0]),
        'notes' => trim((string) $parts[1]),
    ];
}

/**
 * JUSTEL article id: WER books (Art. I.1), Easy Access (Art. L1122-10), numeric (Art. 54), N-articles (XII.N1).
 */
function rl_pdf_justel_article_id_pattern(): string
{
    return '(?:[IVXLC]{1,6}\.N\d+(?:\.\d+)?'
        . '|[IVXLC]{1,6}\.\d+(?:\/\d+)?(?:ter|bis|quater)?'
        . '|\d+[a-z]*(?:ter|bis|quater)?'
        . '|[A-Z]?\d[\w\.\-]*'
        . '|N\d+)';
}

/**
 * Locate main legal text (after "Tekst") for JUSTEL-style exports.
 */
function rl_pdf_justel_main_text_slice(string $text): string
{
    $text = str_replace("\r\n", "\n", $text);
    $tekstMarker = '/\n\s*Tekst\s*\n/iu';
    if (preg_match($tekstMarker, $text, $m, PREG_OFFSET_CAPTURE)) {
        $pos = (int) ($m[0][1] ?? 0);

        return substr($text, $pos + strlen($m[0][0]));
    }
    if (preg_match('/\nInhoudstafel\n/iu', $text, $m2, PREG_OFFSET_CAPTURE)) {
        $pos = (int) ($m2[0][1] ?? 0);
        $afterToc = substr($text, $pos);
        if (preg_match($tekstMarker, $afterToc, $m3, PREG_OFFSET_CAPTURE)) {
            $p2 = (int) ($m3[0][1] ?? 0);

            return substr($afterToc, $p2 + strlen($m3[0][0]));
        }
    }

    return $text;
}

/**
 * Parse JUSTEL-style legal PDF text into article rows.
 *
 * @return list<array<string, mixed>>
 */
function rl_pdf_parse_justel_articles(string $rawText): array
{
    $text = rl_pdf_strip_page_noise(str_replace("\r\n", "\n", $rawText));
    $main = rl_pdf_justel_main_text_slice($text);

    $idPat = rl_pdf_justel_article_id_pattern();
    // Line-anchored markers only (form feeds between pages); lowercase "art." in cross-refs is ignored.
    $pattern = '/(?:^|\n|\f)\s*(?:Art\.|Artikel)\s+(' . $idPat . ')(?:\s+(TOEKOMSTIG\s+RECHT|DUITSTALIGE[_\s]GEMEENSCHAP))?/iu';
    if (!preg_match_all($pattern, $main, $matches, PREG_OFFSET_CAPTURE)) {
        throw new RuntimeException('No legal articles detected (expected Art. / Artikel markers after Tekst section)');
    }

    $starts = [];
    $count = count($matches[0]);
    for ($i = 0; $i < $count; $i++) {
        $starts[] = [
            'offset' => (int) ($matches[0][$i][1] ?? 0),
            'id' => (string) ($matches[1][$i][0] ?? ''),
            'suffix' => (string) ($matches[2][$i][0] ?? ''),
            'heading' => (string) ($matches[0][$i][0] ?? ''),
        ];
    }

    $articles = [];
    $sort = 0;
    for ($i = 0; $i < $count; $i++) {
        $start = $starts[$i]['offset'];
        $end = ($i + 1 < $count) ? $starts[$i + 1]['offset'] : strlen($main);
        $chunk = substr($main, $start, $end - $start);
        $split = rl_pdf_split_amendment_notes($chunk);
        $bodyRaw = rl_pdf_strip_page_noise($split['body']);
        $canonical = rl_pdf_normalize_ws($bodyRaw);
        if ($canonical === '') {
            continue;
        }
        $key = rl_pdf_normalize_article_key($starts[$i]['id'], $starts[$i]['suffix']);
        // TOC / index lines often match "Art. 325" or "Art. I.2" with almost no body — skip stubs.
        if (strlen($canonical) < 120 && preg_match('/^(?:\d+|[IVXLC]+(?:_\d+)+)$/', $key)) {
            continue;
        }
        $legalState = rl_pdf_detect_legal_state($starts[$i]['heading'], $chunk);
        $titleLine = trim(preg_replace('/\s+/u', ' ', $starts[$i]['heading']) ?? $starts[$i]['heading']);
        $hash = hash('sha256', $canonical . "\n|\n" . $key . "\n|\n" . $legalState);
        $articles[] = [
            'article_key' => $key,
            'article_title' => mb_substr(rl_pdf_sanitize_utf8($titleLine), 0, 500),
            'hierarchy_path' => 'legal/' . $key,
            'canonical_text' => $canonical,
            'content_hash' => $hash,
            'sort_order' => $sort,
            'page_start' => null,
            'page_end' => null,
            'legal_state' => $legalState,
            'amendment_notes' => $split['notes'] !== '' ? mb_substr(rl_pdf_sanitize_utf8($split['notes']), 0, 65000) : null,
        ];
        $sort++;
    }

    if ($articles === []) {
        throw new RuntimeException('Article markers matched but no non-empty article bodies were extracted');
    }

    return rl_pdf_dedupe_parsed_articles($articles);
}

/**
 * @param list<array<string, mixed>> $articles
 */
function rl_pdf_insert_staging_articles(PDO $pdo, int $editionId, int $batchId, array $articles): int
{
    $pdo->prepare('DELETE FROM resource_library_pdf_articles_staging WHERE batch_id = ?')->execute([$batchId]);
    $ins = $pdo->prepare('
        INSERT INTO resource_library_pdf_articles_staging (
            batch_id, edition_id, article_key, article_title, hierarchy_path, canonical_text,
            content_hash, sort_order, page_start, page_end, legal_state, amendment_notes
        ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
    ');
    $articles = rl_pdf_dedupe_parsed_articles($articles);

    $n = 0;
    foreach ($articles as $a) {
        if (!is_array($a)) {
            continue;
        }
        $key = (string) $a['article_key'];
        if ($key === '') {
            continue;
        }
        $ins->execute([
            $batchId,
            $editionId,
            $key,
            $a['article_title'],
            $a['hierarchy_path'],
            (string) $a['canonical_text'],
            (string) $a['content_hash'],
            (int) $a['sort_order'],
            $a['page_start'],
            $a['page_end'],
            (string) $a['legal_state'],
            $a['amendment_notes'],
        ]);
        $n++;
    }

    return $n;
}

/**
 * Build diff rows vs last published batch for this edition.
 *
 * @return array{new: int, changed: int, removed: int, unchanged: int}
 */
function rl_pdf_write_article_diffs(PDO $pdo, int $editionId, int $batchId): array
{
    $pdo->prepare('DELETE FROM resource_library_pdf_article_diffs WHERE batch_id = ?')->execute([$batchId]);

    $published = rl_pdf_fetch_published_batch($pdo, $editionId);
    $oldByKey = [];
    if ($published !== null) {
        $pubId = (int) ($published['id'] ?? 0);
        $st = $pdo->prepare('
            SELECT article_key, content_hash, canonical_text
            FROM resource_library_pdf_articles_staging
            WHERE batch_id = ?
        ');
        $st->execute([$pubId]);
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            if (!is_array($r)) {
                continue;
            }
            $k = (string) ($r['article_key'] ?? '');
            if ($k !== '') {
                $oldByKey[$k] = $r;
            }
        }
    }

    $stNew = $pdo->prepare('
        SELECT article_key, content_hash, canonical_text
        FROM resource_library_pdf_articles_staging
        WHERE batch_id = ?
    ');
    $stNew->execute([$batchId]);
    $newByKey = [];
    while ($r = $stNew->fetch(PDO::FETCH_ASSOC)) {
        if (!is_array($r)) {
            continue;
        }
        $k = (string) ($r['article_key'] ?? '');
        if ($k !== '') {
            $newByKey[$k] = $r;
        }
    }

    $ins = $pdo->prepare('
        INSERT INTO resource_library_pdf_article_diffs (
            batch_id, edition_id, article_key, change_type,
            old_content_hash, new_content_hash, old_excerpt, new_excerpt
        ) VALUES (?,?,?,?,?,?,?,?)
    ');

    $counts = ['new' => 0, 'changed' => 0, 'removed' => 0, 'unchanged' => 0];

    foreach ($newByKey as $key => $row) {
        $newHash = (string) ($row['content_hash'] ?? '');
        $newCanon = (string) ($row['canonical_text'] ?? '');
        if (!isset($oldByKey[$key])) {
            $type = 'new';
            $counts['new']++;
            $oldHash = null;
            $oldEx = null;
        } elseif ((string) ($oldByKey[$key]['content_hash'] ?? '') !== $newHash) {
            $type = 'changed';
            $counts['changed']++;
            $oldHash = (string) $oldByKey[$key]['content_hash'];
            $oldEx = rl_pdf_excerpt((string) ($oldByKey[$key]['canonical_text'] ?? ''), RL_PDF_DIFF_EXCERPT_LEN);
        } else {
            $type = 'unchanged';
            $counts['unchanged']++;
            $oldHash = (string) $oldByKey[$key]['content_hash'];
            $oldEx = rl_pdf_excerpt((string) ($oldByKey[$key]['canonical_text'] ?? ''), RL_PDF_DIFF_EXCERPT_LEN);
        }
        $ins->execute([
            $batchId,
            $editionId,
            $key,
            $type,
            $oldHash ?? null,
            $newHash,
            $oldEx,
            rl_pdf_excerpt($newCanon, RL_PDF_DIFF_EXCERPT_LEN),
        ]);
    }

    foreach ($oldByKey as $key => $row) {
        if (isset($newByKey[$key])) {
            continue;
        }
        $counts['removed']++;
        $ins->execute([
            $batchId,
            $editionId,
            $key,
            'removed',
            (string) ($row['content_hash'] ?? ''),
            null,
            rl_pdf_excerpt((string) ($row['canonical_text'] ?? ''), RL_PDF_DIFF_EXCERPT_LEN),
            null,
        ]);
    }

    return $counts;
}

/**
 * Full parse pipeline for one batch.
 *
 * @return array{articles: int, diff: array<string, int>}
 */
function rl_pdf_parse_batch(PDO $pdo, int $batchId): array
{
    if (!rl_pdf_tables_ok($pdo)) {
        throw new RuntimeException('Apply scripts/sql/resource_library_pdf_crawler.sql first');
    }
    $st = $pdo->prepare('SELECT * FROM resource_library_pdf_batches WHERE id = ? LIMIT 1');
    $st->execute([$batchId]);
    $batch = $st->fetch(PDO::FETCH_ASSOC);
    if (!is_array($batch)) {
        throw new RuntimeException('Batch not found');
    }
    $editionId = (int) ($batch['edition_id'] ?? 0);
    if ($editionId <= 0) {
        throw new RuntimeException('Invalid edition on batch');
    }

    $pdo->prepare("
        UPDATE resource_library_pdf_batches SET
            status = 'extracting',
            error_message = NULL,
            parse_started_at = UTC_TIMESTAMP(),
            parse_finished_at = NULL,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = ?
    ")->execute([$batchId]);

    try {
        $val = rl_pdf_validate_batch_pdf($editionId, $batchId);
        if (!$val['readable']) {
            throw new RuntimeException('Stored PDF is missing or not readable');
        }
        $text = rl_pdf_extract_text_from_file($val['absolute']);
        rl_pdf_store_extracted_text($editionId, $batchId, $text);
        $articles = rl_pdf_parse_justel_articles($text);
        $n = rl_pdf_insert_staging_articles($pdo, $editionId, $batchId, $articles);
        $diff = rl_pdf_write_article_diffs($pdo, $editionId, $batchId);

        $pdo->prepare("
            UPDATE resource_library_pdf_batches SET
                status = 'ready_for_review',
                article_count = ?,
                new_article_count = ?,
                changed_article_count = ?,
                removed_article_count = ?,
                error_message = NULL,
                parse_finished_at = UTC_TIMESTAMP(),
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ")->execute([
            $n,
            $diff['new'],
            $diff['changed'],
            $diff['removed'],
            $batchId,
        ]);

        return ['articles' => $n, 'diff' => $diff];
    } catch (Throwable $e) {
        $pdo->prepare("
            UPDATE resource_library_pdf_batches SET
                status = 'failed',
                error_message = ?,
                parse_finished_at = UTC_TIMESTAMP(),
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ")->execute([$e->getMessage(), $batchId]);
        throw $e;
    }
}

/**
 * Download (if needed) + parse for an edition; updates monitor state in extra_config_json.
 *
 * @return array<string, mixed>
 */
function rl_pdf_check_now(PDO $pdo, int $editionId, bool $forceDownload = false): array
{
    $row = rl_catalog_fetch_edition($pdo, $editionId);
    if (!is_array($row) || rl_catalog_normalize_resource_type((string) ($row['resource_type'] ?? '')) !== RL_RESOURCE_PDF_BOOK) {
        throw new RuntimeException('PDF source edition not found');
    }
    $extra = rl_catalog_decode_extra(isset($row['extra_config_json']) ? (string) $row['extra_config_json'] : null);
    $url = trim((string) ($extra['official_pdf_url'] ?? ''));
    if ($url === '') {
        throw new RuntimeException('official_pdf_url is not configured');
    }

    $nowIso = gmdate('Y-m-d\TH:i:s\Z');
    $mon = is_array($extra['pdf_monitor_state'] ?? null) ? $extra['pdf_monitor_state'] : [];
    $mon['last_checked_at'] = $nowIso;

    try {
        $result = rl_pdf_download_and_create_batch($pdo, $editionId, $url, !$forceDownload);
        $mon['last_downloaded_at'] = $nowIso;
        $mon['last_error'] = null;
        if ($result['unchanged']) {
            $mon['change_detected'] = false;
            $extra['pdf_monitor_state'] = $mon;
            $pdo->prepare('UPDATE resource_library_editions SET extra_config_json = ? WHERE id = ?')
                ->execute([rl_catalog_encode_extra($extra), $editionId]);

            return [
                'ok' => true,
                'unchanged' => true,
                'batch_id' => $result['batch_id'],
                'sha256' => $result['sha256'],
                'message' => 'PDF unchanged (same SHA-256 as an existing batch for this source).',
            ];
        }

        $mon['change_detected'] = true;
        $mon['last_file_sha256'] = $result['sha256'];
        $parse = rl_pdf_parse_batch($pdo, $result['batch_id']);
        $extra['pdf_monitor_state'] = $mon;
        if (isset($extra['source_verify_state']) && is_array($extra['source_verify_state'])) {
            $extra['source_verify_state']['change_detected'] = true;
            $extra['source_verify_state']['change_detected_at'] = $nowIso;
        }
        $pdo->prepare('UPDATE resource_library_editions SET extra_config_json = ? WHERE id = ?')
            ->execute([rl_catalog_encode_extra($extra), $editionId]);

        return [
            'ok' => true,
            'unchanged' => false,
            'batch_id' => $result['batch_id'],
            'sha256' => $result['sha256'],
            'articles' => $parse['articles'],
            'diff' => $parse['diff'],
            'message' => 'New PDF downloaded, parsed, and marked ready for review.',
        ];
    } catch (Throwable $e) {
        $mon['last_error'] = $e->getMessage();
        $extra['pdf_monitor_state'] = $mon;
        $pdo->prepare('UPDATE resource_library_editions SET extra_config_json = ? WHERE id = ?')
            ->execute([rl_catalog_encode_extra($extra), $editionId]);
        throw $e;
    }
}

/**
 * Publish an approved batch (does not set edition live — caller may set status separately).
 *
 * @return array{blocks: int, sha256: string}
 */
function rl_pdf_publish_batch(PDO $pdo, int $batchId, int $userId): array
{
    $st = $pdo->prepare('SELECT * FROM resource_library_pdf_batches WHERE id = ? LIMIT 1');
    $st->execute([$batchId]);
    $batch = $st->fetch(PDO::FETCH_ASSOC);
    if (!is_array($batch)) {
        throw new RuntimeException('Batch not found');
    }
    $batchStatus = (string) ($batch['status'] ?? '');
    if ($batchStatus !== 'ready_for_review') {
        throw new RuntimeException('Only batches in ready_for_review status can be published');
    }
    $editionId = (int) ($batch['edition_id'] ?? 0);
    $row = rl_catalog_fetch_edition($pdo, $editionId);
    if (!is_array($row)) {
        throw new RuntimeException('Edition not found');
    }
    $extra = rl_catalog_decode_extra(isset($row['extra_config_json']) ? (string) $row['extra_config_json'] : null);
    $url = trim((string) ($extra['official_pdf_url'] ?? ''));
    $tags = rl_pdf_normalize_applicability_tags($extra['applicability_tags'] ?? []);

    $pub = rl_pdf_publish_batch_to_blocks($pdo, $editionId, $batchId, $url, $tags);

    $pdo->prepare("
        UPDATE resource_library_pdf_batches SET
            status = 'published',
            published_at = UTC_TIMESTAMP(),
            published_by_user_id = ?,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = ?
    ")->execute([$userId > 0 ? $userId : null, $batchId]);

    $sha = (string) ($batch['file_sha256'] ?? '');
    $extra['published_file_sha256'] = $sha;
    if (isset($extra['pdf_monitor_state']) && is_array($extra['pdf_monitor_state'])) {
        $extra['pdf_monitor_state']['change_detected'] = false;
    }
    if (isset($extra['source_verify_state']) && is_array($extra['source_verify_state'])) {
        $extra['source_verify_state']['change_detected'] = false;
    }
    $pdo->prepare('UPDATE resource_library_editions SET extra_config_json = ? WHERE id = ?')
        ->execute([rl_catalog_encode_extra($extra), $editionId]);

    return ['blocks' => $pub['blocks'], 'sha256' => $sha];
}

function rl_pdf_reject_batch(PDO $pdo, int $batchId, int $userId): void
{
    $st = $pdo->prepare('SELECT id, status FROM resource_library_pdf_batches WHERE id = ? LIMIT 1');
    $st->execute([$batchId]);
    $batch = $st->fetch(PDO::FETCH_ASSOC);
    if (!is_array($batch)) {
        throw new RuntimeException('Batch not found');
    }
    $status = (string) ($batch['status'] ?? '');
    if ($status !== 'ready_for_review' && $status !== 'downloaded' && $status !== 'extracting' && $status !== 'failed') {
        throw new RuntimeException('This batch cannot be rejected in its current status');
    }
    $pdo->prepare("
        UPDATE resource_library_pdf_batches SET
            status = 'rejected',
            rejected_at = UTC_TIMESTAMP(),
            rejected_by_user_id = ?,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = ?
    ")->execute([$userId > 0 ? $userId : null, $batchId]);
}
