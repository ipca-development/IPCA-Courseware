<?php
declare(strict_types=1);

require_once __DIR__ . '/easa_erules_storage.php';

/** Max stored outer XML fragment when we only store a synthetic opening tag (streaming mode). */
const EASA_ERULES_XML_FRAGMENT_MAX = 65535;

const EASA_ERULES_PROGRESS_INTERVAL = 80;

/**
 * @return list<string>
 */
function easa_erules_row_candidate_local_names(): array
{
    return ['document', 'frontmatter', 'toc', 'heading', 'topic', 'backmatter'];
}

function easa_erules_normalize_ws(string $s): string
{
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s) ?? $s;

    return trim(preg_replace('/\s+/u', ' ', $s) ?? $s);
}

/**
 * @return array<string, string>
 */
function easa_erules_dom_element_attributes(DOMElement $el): array
{
    $out = [];
    if ($el->hasAttributes()) {
        foreach ($el->attributes ?? [] as $a) {
            if ($a instanceof DOMAttr) {
                $out[$a->name] = $a->value;
            }
        }
    }

    return $out;
}

/**
 * @return array<string, string>
 */
function easa_erules_reader_collect_attributes(XMLReader $r): array
{
    $attrs = [];
    if ($r->attributeCount > 0) {
        while ($r->moveToNextAttribute()) {
            $attrs[$r->localName] = $r->value;
        }
        $r->moveToElement();
    }

    return $attrs;
}

function easa_erules_pick_title_attrs_only(array $attrs): string
{
    foreach (['title', 'source-title', 'source_title', 'Title', 'sourceTitle'] as $k) {
        if (isset($attrs[$k]) && trim((string) $attrs[$k]) !== '') {
            return mb_substr(trim((string) $attrs[$k]), 0, 2000);
        }
    }

    return '';
}

function easa_erules_pick_title(array $attrs, string $plain, ?DOMElement $el): string
{
    $t = easa_erules_pick_title_attrs_only($attrs);
    if ($t !== '') {
        return $t;
    }
    if ($el instanceof DOMElement) {
        $hn = $el->getElementsByTagNameNS('*', 'title')->item(0);
        if ($hn instanceof DOMElement) {
            $t2 = easa_erules_normalize_ws($hn->textContent);

            return mb_substr($t2, 0, 2000);
        }
    }
    $line = explode("\n", $plain, 2)[0];

    return mb_substr(easa_erules_normalize_ws($line), 0, 400);
}

function easa_erules_element_is_candidate_attrs(string $localName, array $attrs): bool
{
    $l = strtolower($localName);
    if (in_array($l, easa_erules_row_candidate_local_names(), true)) {
        return true;
    }

    return isset($attrs['ERulesId']) || isset($attrs['guid']);
}

function easa_erules_element_is_candidate(DOMElement $el): bool
{
    return easa_erules_element_is_candidate_attrs($el->localName, easa_erules_dom_element_attributes($el));
}

function easa_erules_batch_progress_available(PDO $pdo): bool
{
    try {
        $st = $pdo->query("SHOW COLUMNS FROM easa_erules_import_batches LIKE 'parse_phase'");

        return $st instanceof PDOStatement && $st->rowCount() > 0;
    } catch (Throwable) {
        return false;
    }
}

/**
 * @param array<string, string|null> $detailReplacements
 */
function easa_erules_batch_update_progress(
    PDO $pdo,
    int $batchId,
    int $rowsSoFar,
    string $phase,
    string $lastNodeType,
    string $detailTemplate,
    array $detailReplacements = []
): void {
    if (!easa_erules_batch_progress_available($pdo)) {
        return;
    }
    $detail = $detailTemplate;
    foreach ($detailReplacements as $k => $v) {
        $detail = str_replace($k, (string) $v, $detail);
    }
    if (strlen($detail) > 500) {
        $detail = substr($detail, 0, 497) . '…';
    }
    $stmt = $pdo->prepare('
        UPDATE easa_erules_import_batches SET
            parse_rows_so_far = ?,
            parse_phase = ?,
            parse_last_node_type = ?,
            parse_detail = ?,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = ?
    ');
    $stmt->execute([$rowsSoFar, $phase, mb_substr($lastNodeType, 0, 128), $detail, $batchId]);
}

function easa_erules_merge_text_chunks(array $chunks): string
{
    $parts = [];
    foreach ($chunks as $c) {
        $c = (string) $c;
        if ($c === '') {
            continue;
        }
        $parts[] = $c;
    }

    return easa_erules_normalize_ws(implode(' ', $parts));
}

function easa_erules_parent_sort_key(?string $parentCandidateUid): string
{
    return $parentCandidateUid ?? '';
}

/**
 * Streaming import: memory scales with tree depth + largest text buffers, not whole-file DOM.
 *
 * @return array{imported: int, publication_meta: array<string, mixed>|null}
 */
function easa_erules_import_batch_xml_to_staging(PDO $pdo, int $batchId): array
{
    if ($batchId <= 0) {
        throw new InvalidArgumentException('Invalid batch id');
    }

    $st = $pdo->prepare('SELECT id, storage_relpath, status FROM easa_erules_import_batches WHERE id = ? LIMIT 1');
    $st->execute([$batchId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        throw new RuntimeException('Batch not found');
    }

    $rel = trim((string) ($row['storage_relpath'] ?? ''));
    if ($rel === '') {
        throw new RuntimeException('Batch has no storage path');
    }

    $abs = rl_project_root() . '/' . str_replace('\\', '/', $rel);
    if (!is_file($abs) || !is_readable($abs)) {
        throw new RuntimeException('XML file missing on disk: ' . $rel);
    }

    $fi = @stat($abs);
    $size = is_array($fi) ? (int) ($fi['size'] ?? 0) : 0;
    if ($size > 450 * 1024 * 1024) {
        throw new RuntimeException('XML file exceeds 450 MB safety limit; split the export or raise the limit with ops approval.');
    }

    if (easa_erules_batch_progress_available($pdo)) {
        $pdo->prepare('
            UPDATE easa_erules_import_batches SET
                parse_started_at = UTC_TIMESTAMP(),
                parse_finished_at = NULL,
                parse_rows_so_far = 0,
                parse_phase = \'streaming\',
                parse_last_node_type = NULL,
                parse_detail = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ')->execute(['Reading XML stream (file ' . round($size / 1048576, 1) . ' MiB)…', $batchId]);
    }

    $pdo->prepare('DELETE FROM easa_erules_import_nodes_staging WHERE batch_id = ?')->execute([$batchId]);

    $ins = $pdo->prepare('
        INSERT INTO easa_erules_import_nodes_staging (
            batch_id, node_uid, source_erules_id, node_type, parent_node_uid, sort_order, depth,
            path, breadcrumb, title, source_title, plain_text, xml_fragment, metadata_json, content_hash
        ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
    ');

    $reader = new XMLReader();
    if (!$reader->open($abs, null, LIBXML_PARSEHUGE | LIBXML_NONET | LIBXML_COMPACT)) {
        throw new RuntimeException('Could not open XML for streaming read');
    }

    /** Light stack frames (mixed content order preserved via chunks). */
    $stack = [];
    /** @var list<string> */
    $candidateUidStack = [];
    /** @var array<string, string> preliminary titles from attributes */
    $titleByUid = [];

    $siblingCount = [];
    $seq = 0;
    $imported = 0;
    $publicationMeta = null;

    $flushProgress = static function (string $lastType, string $detail) use ($pdo, $batchId, &$imported): void {
        if ($imported > 0 && ($imported % EASA_ERULES_PROGRESS_INTERVAL) === 0) {
            easa_erules_batch_update_progress($pdo, $batchId, $imported, 'inserting', $lastType, $detail);
        }
    };

    $popFrame = static function () use (&$stack, &$candidateUidStack, &$titleByUid, &$siblingCount, &$seq, &$imported, &$publicationMeta, $pdo, $batchId, $ins, $flushProgress): void {
        /** @var array{frame: object, chunks: array} $p */
        $p = array_pop($stack);
        if (!is_array($p)) {
            return;
        }
        $frame = $p['frame'];
        $chunks = $p['chunks'];
        $plain = easa_erules_merge_text_chunks($chunks);

        if ($frame->isCandidate && $frame->candidateUid !== null) {
            array_pop($candidateUidStack);

            $attrs = $frame->attrs;
            $local = $frame->localName;
            $uid = $frame->candidateUid;
            $parentUid = $frame->parentCandidateUid;

            $title = easa_erules_pick_title($attrs, $plain, null);
            if ($title !== '') {
                $titleByUid[$uid] = $title;
            }

            $sourceTitle = isset($attrs['source-title']) ? trim((string) $attrs['source-title']) : (isset($attrs['source_title']) ? trim((string) $attrs['source_title']) : null);

            $erulesId = isset($attrs['ERulesId']) ? trim((string) $attrs['ERulesId']) : null;
            if ($erulesId === '') {
                $erulesId = null;
            }

            $sortOrder = (int) ($frame->sortOrder ?? 0);

            $crumbParts = [];
            foreach ($candidateUidStack as $ancestorUid) {
                if (isset($titleByUid[$ancestorUid])) {
                    $crumbParts[] = mb_substr($titleByUid[$ancestorUid], 0, 180);
                }
            }
            $breadcrumb = implode(' › ', $crumbParts);
            if ($title !== '') {
                $breadcrumb = $breadcrumb !== '' ? ($breadcrumb . ' › ' . mb_substr($title, 0, 180)) : mb_substr($title, 0, 250);
            }

            $pathParts = $crumbParts;
            $pathParts[] = $erulesId ?? mb_substr(preg_replace('#\s+#u', '_', $title) ?: $title, 0, 120);
            $pathStr = implode('/', array_filter($pathParts, static function ($x) {
                return $x !== '';
            }));

            $openTag = '<' . htmlspecialchars($local, ENT_XML1 | ENT_QUOTES, 'UTF-8');
            foreach ($attrs as $ak => $av) {
                $openTag .= ' ' . htmlspecialchars((string) $ak, ENT_XML1 | ENT_QUOTES, 'UTF-8')
                    . '="' . htmlspecialchars((string) $av, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '"';
            }
            $openTag .= '>';
            $frag = strlen($openTag) > EASA_ERULES_XML_FRAGMENT_MAX
                ? substr($openTag, 0, EASA_ERULES_XML_FRAGMENT_MAX) . "\n<!-- … -->"
                : $openTag;

            $meta = [
                'localName' => $local,
                'namespaceURI' => $frame->namespaceUri,
                'attributes' => $attrs,
                'import_mode' => 'xmlreader_stream',
            ];

            $hash = hash('sha256', $plain . "\n|\n" . ($erulesId ?? '') . "\n|\n" . $local);

            $ins->execute([
                $batchId,
                $uid,
                $erulesId,
                $local,
                $parentUid,
                $sortOrder,
                $frame->xmlDepth,
                $pathStr !== '' ? mb_substr($pathStr, 0, 4000) : null,
                $breadcrumb !== '' ? $breadcrumb : null,
                $title !== '' ? $title : null,
                $sourceTitle !== null && $sourceTitle !== '' ? mb_substr($sourceTitle, 0, 2000) : null,
                $plain,
                $frag,
                json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
                $hash,
            ]);
            $imported++;
            $flushProgress($local, 'Inserted ' . $imported . ' nodes · last: <' . $local . '>');

            if (strtolower($local) === 'document') {
                $publicationMeta = [
                    'element' => 'document',
                    'attributes' => $attrs,
                    'namespaceURI' => $frame->namespaceUri,
                ];
            }

            if ($stack) {
                $top = count($stack) - 1;
                $stack[$top]['chunks'][] = $plain;
            }

            return;
        }

        if ($stack) {
            $top = count($stack) - 1;
            if ($plain !== '') {
                $stack[$top]['chunks'][] = $plain;
            }
        }
    };

    try {
        while ($reader->read()) {
            $type = $reader->nodeType;
            if ($type === XMLReader::ELEMENT) {
                $attrs = easa_erules_reader_collect_attributes($reader);
                $ln = $reader->localName;
                $ns = $reader->namespaceURI ?? '';
                $isCand = easa_erules_element_is_candidate_attrs($ln, $attrs);
                $depthIdx = count($stack);

                $parentCandUid = null;
                if ($isCand && count($candidateUidStack) > 0) {
                    $parentCandUid = $candidateUidStack[count($candidateUidStack) - 1];
                }

                $sortOrder = 0;
                if ($isCand) {
                    $pk = easa_erules_parent_sort_key($parentCandUid);
                    $sortOrder = $siblingCount[$pk] ?? 0;
                    $siblingCount[$pk] = $sortOrder + 1;
                    $seq++;
                    $candUid = 'b' . $batchId . '_n' . $seq;
                    $candidateUidStack[] = $candUid;
                    $pre = easa_erules_pick_title_attrs_only($attrs);
                    if ($pre !== '') {
                        $titleByUid[$candUid] = $pre;
                    }
                } else {
                    $candUid = null;
                }

                $frame = (object) [
                    'localName' => $ln,
                    'attrs' => $attrs,
                    'namespaceUri' => is_string($ns) ? $ns : '',
                    'isCandidate' => $isCand,
                    'candidateUid' => $candUid,
                    'parentCandidateUid' => $parentCandUid,
                    'sortOrder' => $sortOrder,
                    'xmlDepth' => $depthIdx + 1,
                ];

                $stack[] = ['frame' => $frame, 'chunks' => []];

                if ($reader->isEmptyElement) {
                    $popFrame();
                }
            } elseif ($type === XMLReader::TEXT || $type === XMLReader::CDATA) {
                $v = $reader->value;
                if ($v !== '' && count($stack) > 0) {
                    $ti = count($stack) - 1;
                    $stack[$ti]['chunks'][] = $v;
                }
            } elseif ($type === XMLReader::END_ELEMENT) {
                $popFrame();
            }
        }
    } finally {
        $reader->close();
    }

    return [
        'imported' => $imported,
        'publication_meta' => $publicationMeta,
    ];
}

/**
 * Persist successful parse outcome on the batch row (call after easa_erules_import_batch_xml_to_staging).
 *
 * @param array<string, mixed>|null $publicationMeta
 */
function easa_erules_import_finalize_success(PDO $pdo, int $batchId, int $imported, ?array $publicationMeta): void
{
    $pubJson = $publicationMeta !== null
        ? json_encode($publicationMeta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        : null;

    if (easa_erules_batch_progress_available($pdo)) {
        $pdo->prepare('
            UPDATE easa_erules_import_batches SET
                status = \'ready_for_review\',
                rows_detected = ?,
                publication_meta_json = COALESCE(?, publication_meta_json),
                error_message = NULL,
                parse_finished_at = UTC_TIMESTAMP(),
                parse_phase = \'completed\',
                parse_rows_so_far = ?,
                parse_detail = ?,
                parse_last_node_type = NULL,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ')->execute([
            $imported,
            $pubJson,
            $imported,
            'Completed: ' . $imported . ' regulatory nodes in staging.',
            $batchId,
        ]);

        return;
    }

    $pdo->prepare('
        UPDATE easa_erules_import_batches SET
            status = \'ready_for_review\',
            rows_detected = ?,
            publication_meta_json = COALESCE(?, publication_meta_json),
            error_message = NULL,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = ?
    ')->execute([$imported, $pubJson, $batchId]);
}

function easa_erules_import_finalize_failure(PDO $pdo, int $batchId, string $message): void
{
    $detail = mb_substr($message, 0, 500);

    if (easa_erules_batch_progress_available($pdo)) {
        $pdo->prepare('
            UPDATE easa_erules_import_batches SET
                status = \'failed\',
                error_message = ?,
                parse_finished_at = UTC_TIMESTAMP(),
                parse_phase = \'failed\',
                parse_detail = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ')->execute([$message, $detail, $batchId]);

        return;
    }

    $pdo->prepare('UPDATE easa_erules_import_batches SET status = \'failed\', error_message = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$message, $batchId]);
}

/**
 * Strip Word TOC / merge-field noise often embedded in Easy Access XML text nodes.
 */
function easa_erules_sanitize_display_text(string $s): string
{
    if ($s === '') {
        return '';
    }
    $s = preg_replace('/\\\\\*+\s*MERGEFORMAT\s*/iu', '', $s) ?? $s;
    $s = preg_replace('/DATE\s*\\\\@[^"\s]*\s*"[^"]*"\s*(?:\\\\\*+\s*MERGEFORMAT\s*)?[^\s"\\\\]*/iu', '', $s) ?? $s;
    $s = preg_replace('/PAGEREF\s+_[A-Za-z0-9]+\s*\\\\[a-z]+\s*\d*/iu', '', $s) ?? $s;
    $s = preg_replace('/HYPERLINK\s+\\\\l\s+"[^"]*"\s*/iu', '', $s) ?? $s;
    $s = preg_replace('/\\s{2,}/u', ' ', $s) ?? $s;

    return trim($s);
}

/**
 * Short label for tree rows (sanitized, max length).
 */
function easa_erules_short_tree_label(array $n): string
{
    $raw = trim((string) ($n['title'] ?? ''));
    if ($raw === '') {
        $raw = trim((string) ($n['source_erules_id'] ?? ''));
    }
    if ($raw === '') {
        $raw = trim((string) ($n['source_title'] ?? ''));
    }
    if ($raw === '') {
        $raw = (string) ($n['node_uid'] ?? '');
    }
    $s = easa_erules_sanitize_display_text($raw);
    if ($s === '') {
        $s = (string) ($n['node_type'] ?? '—');
    }
    if (strlen($s) > 180) {
        $s = substr($s, 0, 177) . '…';
    }

    return $s;
}

/**
 * UI colour band aligned with EASA Easy Access: IR (blue), AMC (amber), GM (green), wrappers (slate).
 *
 * @return 'ir'|'amc'|'gm'|'neu'
 */
function easa_erules_classify_display_band(?string $nodeType, ?string $title, ?string $sourceTitle, ?string $erulesId): string
{
    $nt = strtolower(trim((string) $nodeType));
    if (in_array($nt, ['document', 'frontmatter', 'toc', 'backmatter'], true)) {
        return 'neu';
    }
    $blob = trim((string) $title . "\n" . (string) $sourceTitle . "\n" . (string) $erulesId);
    $blobOneLine = preg_replace('/\s+/u', ' ', $blob) ?? $blob;
    if (preg_match('/^\s*AMC\d*\b/iu', $blobOneLine)) {
        return 'amc';
    }
    if (preg_match('/^\s*GM\d*\b/iu', $blobOneLine)) {
        return 'gm';
    }
    if (preg_match('/(?i)\bacceptable\s+means\s+of\s+compliance\b/', $blobOneLine)) {
        return 'amc';
    }
    if (preg_match('/(?i)\bguidance\s+material\b/', $blobOneLine) && preg_match('/(?i)\bGM\d*\b/', $blobOneLine)) {
        return 'gm';
    }

    return 'ir';
}

/**
 * @return bool
 */
function easa_erules_staging_tables_ok(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT 1 FROM easa_erules_import_nodes_staging LIMIT 1');

        return true;
    } catch (Throwable) {
        return false;
    }
}
