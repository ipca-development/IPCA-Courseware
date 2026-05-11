<?php
declare(strict_types=1);

/**
 * Per-user EASA bookmarks + text highlights.
 *
 * Single underlying table `easa_user_bookmarks` with a `kind` ENUM('bookmark','highlight')
 * discriminator. Categories live in `easa_user_bookmark_categories`. Everything is
 * scoped by `user_id` — callers MUST resolve the user via cw_current_user() and pass
 * the integer id in; helpers never read $_SESSION themselves.
 *
 * Schema: scripts/sql/resource_library_easa_user_bookmarks.sql
 *
 * Public helpers (all return arrays):
 *   easa_bookmarks_tables_ok(PDO)
 *   easa_bookmarks_category_list(PDO, int $userId)
 *   easa_bookmarks_category_create(PDO, int $userId, string $name, ?string $colorHex)
 *   easa_bookmarks_category_rename(PDO, int $userId, int $id, string $name)
 *   easa_bookmarks_category_delete(PDO, int $userId, int $id)
 *   easa_bookmarks_list(PDO, int $userId, ?int $categoryId)
 *   easa_bookmarks_save(PDO, int $userId, int $batchId, string $nodeUid,
 *                       ?int $categoryId, ?string $annotation)
 *   easa_bookmarks_delete(PDO, int $userId, int $id)
 *   easa_bookmarks_node_snapshot(PDO, int $batchId, string $nodeUid)
 *   easa_highlights_list(PDO, int $userId, int $batchId, string $nodeUid)
 *   easa_highlights_save(PDO, int $userId, int $batchId, string $nodeUid,
 *                        array $selection, string $colorHex, ?string $annotation)
 *   easa_highlights_update_note(PDO, int $userId, int $id, ?string $annotation)
 *   easa_highlights_delete(PDO, int $userId, int $id)
 */

function easa_bookmarks_tables_ok(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT 1 FROM easa_user_bookmark_categories LIMIT 1');
        $pdo->query('SELECT 1 FROM easa_user_bookmarks LIMIT 1');
        return true;
    } catch (Throwable) {
        return false;
    }
}

/**
 * Snapshot the rule title / breadcrumb / FCL id at the time a bookmark or highlight
 * is saved. We pull from `easa_erules_import_nodes_staging` so the bookmark stays
 * meaningful even if a later re-import shifts node UIDs or removes the row.
 *
 * @return array{title: ?string, breadcrumb: ?string, erules_id: ?string}
 */
function easa_bookmarks_node_snapshot(PDO $pdo, int $batchId, string $nodeUid): array
{
    $out = ['title' => null, 'breadcrumb' => null, 'erules_id' => null];
    if ($batchId <= 0 || $nodeUid === '') {
        return $out;
    }
    try {
        $st = $pdo->prepare(
            'SELECT title, breadcrumb, source_erules_id
             FROM easa_erules_import_nodes_staging
             WHERE batch_id = ? AND node_uid = ?
             LIMIT 1'
        );
        $st->execute([$batchId, $nodeUid]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $title = trim((string) ($row['title'] ?? ''));
            $crumb = trim((string) ($row['breadcrumb'] ?? ''));
            $eid   = trim((string) ($row['source_erules_id'] ?? ''));
            $out['title']      = $title !== '' ? mb_substr($title, 0, 500) : null;
            $out['breadcrumb'] = $crumb !== '' ? $crumb : null;
            $out['erules_id']  = $eid !== '' ? mb_substr($eid, 0, 64) : null;
        }
    } catch (Throwable) {
        // ignore — snapshot is best-effort
    }
    return $out;
}

function easa_bookmarks_normalize_color(?string $hex): ?string
{
    if ($hex === null) {
        return null;
    }
    $hex = strtolower(trim($hex));
    if ($hex === '') {
        return null;
    }
    if (!str_starts_with($hex, '#')) {
        $hex = '#' . $hex;
    }
    if (!preg_match('/^#[0-9a-f]{6}$/', $hex)) {
        return null;
    }
    return $hex;
}

// ─── Categories ────────────────────────────────────────────────────────────

function easa_bookmarks_category_list(PDO $pdo, int $userId): array
{
    if ($userId <= 0) {
        return [];
    }
    $st = $pdo->prepare(
        'SELECT c.id, c.name, c.color_hex, c.sort_order, c.created_at, c.updated_at,
                COALESCE(b.bookmark_count, 0) AS bookmark_count
         FROM easa_user_bookmark_categories c
         LEFT JOIN (
            SELECT category_id, COUNT(*) AS bookmark_count
            FROM easa_user_bookmarks
            WHERE user_id = ? AND kind = ' . "'bookmark'" . '
            GROUP BY category_id
         ) b ON b.category_id = c.id
         WHERE c.user_id = ?
         ORDER BY c.sort_order ASC, c.name ASC'
    );
    $st->execute([$userId, $userId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as &$r) {
        $r['id']             = (int) $r['id'];
        $r['sort_order']     = (int) $r['sort_order'];
        $r['bookmark_count'] = (int) $r['bookmark_count'];
    }
    unset($r);
    return $rows;
}

/**
 * @return array{ok: bool, id?: int, error?: string}
 */
function easa_bookmarks_category_create(PDO $pdo, int $userId, string $name, ?string $colorHex): array
{
    if ($userId <= 0) {
        return ['ok' => false, 'error' => 'not_signed_in'];
    }
    $name = trim($name);
    if ($name === '' || mb_strlen($name) > 80) {
        return ['ok' => false, 'error' => 'name_required'];
    }
    $colorHex = easa_bookmarks_normalize_color($colorHex);
    try {
        $st = $pdo->prepare(
            'INSERT INTO easa_user_bookmark_categories (user_id, name, color_hex, sort_order, created_at, updated_at)
             VALUES (?, ?, ?, 0, NOW(), NOW())'
        );
        $st->execute([$userId, $name, $colorHex]);
        return ['ok' => true, 'id' => (int) $pdo->lastInsertId()];
    } catch (PDOException $e) {
        if (str_contains($e->getMessage(), 'uniq_user_name') || (string) $e->getCode() === '23000') {
            return ['ok' => false, 'error' => 'duplicate_name'];
        }
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

function easa_bookmarks_category_rename(PDO $pdo, int $userId, int $id, string $name): array
{
    if ($userId <= 0) {
        return ['ok' => false, 'error' => 'not_signed_in'];
    }
    $name = trim($name);
    if ($id <= 0 || $name === '' || mb_strlen($name) > 80) {
        return ['ok' => false, 'error' => 'invalid'];
    }
    try {
        $st = $pdo->prepare(
            'UPDATE easa_user_bookmark_categories
             SET name = ?, updated_at = NOW()
             WHERE id = ? AND user_id = ?'
        );
        $st->execute([$name, $id, $userId]);
        return ['ok' => $st->rowCount() >= 0];
    } catch (PDOException $e) {
        if (str_contains($e->getMessage(), 'uniq_user_name') || (string) $e->getCode() === '23000') {
            return ['ok' => false, 'error' => 'duplicate_name'];
        }
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/** Deleting a category nulls `category_id` on its bookmarks (FK ON DELETE SET NULL). */
function easa_bookmarks_category_delete(PDO $pdo, int $userId, int $id): array
{
    if ($userId <= 0) {
        return ['ok' => false, 'error' => 'not_signed_in'];
    }
    if ($id <= 0) {
        return ['ok' => false, 'error' => 'invalid'];
    }
    $st = $pdo->prepare('DELETE FROM easa_user_bookmark_categories WHERE id = ? AND user_id = ?');
    $st->execute([$id, $userId]);
    return ['ok' => true, 'deleted' => $st->rowCount()];
}

// ─── Bookmarks ─────────────────────────────────────────────────────────────

function easa_bookmarks_list(PDO $pdo, int $userId, ?int $categoryId): array
{
    if ($userId <= 0) {
        return [];
    }
    $sql = "SELECT b.id, b.category_id, b.batch_id, b.node_uid,
                   b.title_snapshot, b.breadcrumb_snapshot, b.erules_id_snapshot,
                   b.annotation, b.created_at, b.updated_at,
                   c.name AS category_name, c.color_hex AS category_color_hex
            FROM easa_user_bookmarks b
            LEFT JOIN easa_user_bookmark_categories c
                   ON c.id = b.category_id AND c.user_id = b.user_id
            WHERE b.user_id = ? AND b.kind = 'bookmark'";
    $params = [$userId];
    if ($categoryId === 0) {
        // Explicit "Uncategorized" filter (category_id IS NULL)
        $sql .= ' AND b.category_id IS NULL';
    } elseif ($categoryId !== null) {
        $sql .= ' AND b.category_id = ?';
        $params[] = $categoryId;
    }
    $sql .= ' ORDER BY b.updated_at DESC, b.id DESC';
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as &$r) {
        $r['id']          = (int) $r['id'];
        $r['category_id'] = isset($r['category_id']) ? (int) $r['category_id'] : null;
        $r['batch_id']    = (int) $r['batch_id'];
    }
    unset($r);
    return $rows;
}

/**
 * Upsert a bookmark for (user_id, batch_id, node_uid). The bookmark uniqueness
 * constraint is at the application layer so highlights (same table) can have
 * many rows per node.
 *
 * @return array{ok: bool, id?: int, created?: bool, error?: string}
 */
function easa_bookmarks_save(
    PDO $pdo,
    int $userId,
    int $batchId,
    string $nodeUid,
    ?int $categoryId,
    ?string $annotation
): array {
    if ($userId <= 0) {
        return ['ok' => false, 'error' => 'not_signed_in'];
    }
    if ($batchId <= 0 || $nodeUid === '') {
        return ['ok' => false, 'error' => 'invalid_node'];
    }
    if ($categoryId !== null && $categoryId <= 0) {
        $categoryId = null;
    }
    $ann = $annotation !== null ? trim($annotation) : null;
    if ($ann === '') {
        $ann = null;
    }

    if ($categoryId !== null) {
        $chk = $pdo->prepare('SELECT 1 FROM easa_user_bookmark_categories WHERE id = ? AND user_id = ? LIMIT 1');
        $chk->execute([$categoryId, $userId]);
        if (!$chk->fetchColumn()) {
            return ['ok' => false, 'error' => 'category_not_found'];
        }
    }

    $snap = easa_bookmarks_node_snapshot($pdo, $batchId, $nodeUid);

    $findSt = $pdo->prepare(
        "SELECT id FROM easa_user_bookmarks
         WHERE user_id = ? AND kind = 'bookmark' AND batch_id = ? AND node_uid = ?
         LIMIT 1"
    );
    $findSt->execute([$userId, $batchId, $nodeUid]);
    $existingId = (int) ($findSt->fetchColumn() ?: 0);

    if ($existingId > 0) {
        $up = $pdo->prepare(
            'UPDATE easa_user_bookmarks
             SET category_id = ?, annotation = ?,
                 title_snapshot = COALESCE(?, title_snapshot),
                 breadcrumb_snapshot = COALESCE(?, breadcrumb_snapshot),
                 erules_id_snapshot = COALESCE(?, erules_id_snapshot),
                 updated_at = NOW()
             WHERE id = ? AND user_id = ?'
        );
        $up->execute([
            $categoryId,
            $ann,
            $snap['title'],
            $snap['breadcrumb'],
            $snap['erules_id'],
            $existingId,
            $userId,
        ]);
        return ['ok' => true, 'id' => $existingId, 'created' => false];
    }

    $ins = $pdo->prepare(
        "INSERT INTO easa_user_bookmarks
            (user_id, kind, category_id, batch_id, node_uid,
             title_snapshot, breadcrumb_snapshot, erules_id_snapshot,
             annotation, selection_json, color_hex, created_at, updated_at)
         VALUES (?, 'bookmark', ?, ?, ?, ?, ?, ?, ?, NULL, NULL, NOW(), NOW())"
    );
    $ins->execute([
        $userId,
        $categoryId,
        $batchId,
        $nodeUid,
        $snap['title'],
        $snap['breadcrumb'],
        $snap['erules_id'],
        $ann,
    ]);
    return ['ok' => true, 'id' => (int) $pdo->lastInsertId(), 'created' => true];
}

function easa_bookmarks_delete(PDO $pdo, int $userId, int $id): array
{
    if ($userId <= 0) {
        return ['ok' => false, 'error' => 'not_signed_in'];
    }
    if ($id <= 0) {
        return ['ok' => false, 'error' => 'invalid'];
    }
    $st = $pdo->prepare("DELETE FROM easa_user_bookmarks WHERE id = ? AND user_id = ? AND kind = 'bookmark'");
    $st->execute([$id, $userId]);
    return ['ok' => true, 'deleted' => $st->rowCount()];
}

// ─── Highlights ────────────────────────────────────────────────────────────

function easa_highlights_list(PDO $pdo, int $userId, int $batchId, string $nodeUid): array
{
    if ($userId <= 0 || $batchId <= 0 || $nodeUid === '') {
        return [];
    }
    $st = $pdo->prepare(
        "SELECT id, batch_id, node_uid, title_snapshot, breadcrumb_snapshot,
                annotation, selection_json, color_hex, created_at, updated_at
         FROM easa_user_bookmarks
         WHERE user_id = ? AND kind = 'highlight'
           AND batch_id = ? AND node_uid = ?
         ORDER BY id ASC"
    );
    $st->execute([$userId, $batchId, $nodeUid]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as &$r) {
        $r['id']       = (int) $r['id'];
        $r['batch_id'] = (int) $r['batch_id'];
        $sel = $r['selection_json'] ?? null;
        if (is_string($sel) && $sel !== '') {
            $decoded = json_decode($sel, true);
            $r['selection'] = is_array($decoded) ? $decoded : null;
        } else {
            $r['selection'] = null;
        }
        unset($r['selection_json']);
    }
    unset($r);
    return $rows;
}

/**
 * "All highlights for this user" — feeds the modal's Highlights tab. Grouped client-side.
 */
function easa_highlights_list_all(PDO $pdo, int $userId): array
{
    if ($userId <= 0) {
        return [];
    }
    $st = $pdo->prepare(
        "SELECT id, batch_id, node_uid, title_snapshot, breadcrumb_snapshot, erules_id_snapshot,
                annotation, selection_json, color_hex, created_at, updated_at
         FROM easa_user_bookmarks
         WHERE user_id = ? AND kind = 'highlight'
         ORDER BY updated_at DESC, id DESC"
    );
    $st->execute([$userId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as &$r) {
        $r['id']       = (int) $r['id'];
        $r['batch_id'] = (int) $r['batch_id'];
        $sel = $r['selection_json'] ?? null;
        if (is_string($sel) && $sel !== '') {
            $decoded = json_decode($sel, true);
            $r['selection'] = is_array($decoded) ? $decoded : null;
        } else {
            $r['selection'] = null;
        }
        unset($r['selection_json']);
    }
    unset($r);
    return $rows;
}

/**
 * @param array{text:string,prefix?:string,suffix?:string} $selection
 */
function easa_highlights_save(
    PDO $pdo,
    int $userId,
    int $batchId,
    string $nodeUid,
    array $selection,
    string $colorHex,
    ?string $annotation
): array {
    if ($userId <= 0) {
        return ['ok' => false, 'error' => 'not_signed_in'];
    }
    if ($batchId <= 0 || $nodeUid === '') {
        return ['ok' => false, 'error' => 'invalid_node'];
    }
    $text = trim((string) ($selection['text'] ?? ''));
    if ($text === '') {
        return ['ok' => false, 'error' => 'empty_selection'];
    }
    if (mb_strlen($text) > 4000) {
        $text = mb_substr($text, 0, 4000);
    }
    $prefix = (string) ($selection['prefix'] ?? '');
    $suffix = (string) ($selection['suffix'] ?? '');
    if (mb_strlen($prefix) > 200) {
        $prefix = mb_substr($prefix, -200);
    }
    if (mb_strlen($suffix) > 200) {
        $suffix = mb_substr($suffix, 0, 200);
    }
    $clean = easa_bookmarks_normalize_color($colorHex);
    if ($clean === null) {
        $clean = '#fde68a'; // default yellow
    }
    $ann = $annotation !== null ? trim($annotation) : null;
    if ($ann === '') {
        $ann = null;
    }

    $snap = easa_bookmarks_node_snapshot($pdo, $batchId, $nodeUid);
    $selectionJson = json_encode(
        ['text' => $text, 'prefix' => $prefix, 'suffix' => $suffix],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    if ($selectionJson === false) {
        return ['ok' => false, 'error' => 'selection_encode_failed'];
    }

    $ins = $pdo->prepare(
        "INSERT INTO easa_user_bookmarks
            (user_id, kind, category_id, batch_id, node_uid,
             title_snapshot, breadcrumb_snapshot, erules_id_snapshot,
             annotation, selection_json, color_hex, created_at, updated_at)
         VALUES (?, 'highlight', NULL, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())"
    );
    $ins->execute([
        $userId,
        $batchId,
        $nodeUid,
        $snap['title'],
        $snap['breadcrumb'],
        $snap['erules_id'],
        $ann,
        $selectionJson,
        $clean,
    ]);
    return [
        'ok' => true,
        'id' => (int) $pdo->lastInsertId(),
        'color_hex' => $clean,
    ];
}

function easa_highlights_update_note(PDO $pdo, int $userId, int $id, ?string $annotation): array
{
    if ($userId <= 0) {
        return ['ok' => false, 'error' => 'not_signed_in'];
    }
    if ($id <= 0) {
        return ['ok' => false, 'error' => 'invalid'];
    }
    $ann = $annotation !== null ? trim($annotation) : null;
    if ($ann === '') {
        $ann = null;
    }
    $st = $pdo->prepare(
        "UPDATE easa_user_bookmarks
         SET annotation = ?, updated_at = NOW()
         WHERE id = ? AND user_id = ? AND kind = 'highlight'"
    );
    $st->execute([$ann, $id, $userId]);
    return ['ok' => true];
}

function easa_highlights_delete(PDO $pdo, int $userId, int $id): array
{
    if ($userId <= 0) {
        return ['ok' => false, 'error' => 'not_signed_in'];
    }
    if ($id <= 0) {
        return ['ok' => false, 'error' => 'invalid'];
    }
    $st = $pdo->prepare("DELETE FROM easa_user_bookmarks WHERE id = ? AND user_id = ? AND kind = 'highlight'");
    $st->execute([$id, $userId]);
    return ['ok' => true, 'deleted' => $st->rowCount()];
}
