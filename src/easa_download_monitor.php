<?php
declare(strict_types=1);

require_once __DIR__ . '/resource_library_source_verify.php';

/**
 * @return bool True when easa_download_monitor exists.
 */
function easa_download_monitor_tables_ok(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT 1 FROM easa_download_monitor LIMIT 1');

        return true;
    } catch (Throwable) {
        return false;
    }
}

/**
 * Compact signature for change detection (headers only).
 *
 * @param array<string, mixed> $probe rl_source_verify_http_probe_headers result
 */
function easa_download_monitor_signature_from_probe(array $probe): string
{
    $parts = [
        (string) ($probe['etag'] ?? ''),
        (string) ($probe['last_modified'] ?? ''),
        (string) ($probe['content_length'] ?? ''),
        (string) ($probe['final_url'] ?? ''),
        (string) ($probe['http_code'] ?? ''),
    ];

    return hash('sha256', implode("\x1e", $parts));
}

/**
 * Probe one monitor row and update DB. Respects 429 by recording error without clearing changed_flag arbitrarily.
 *
 * @return array{ok: bool, changed?: bool, error?: string}
 */
function easa_download_monitor_probe_row(PDO $pdo, int $id): array
{
    if ($id <= 0) {
        return ['ok' => false, 'error' => 'bad id'];
    }

    $st = $pdo->prepare('SELECT id, url, etag, last_modified, content_length, content_hash FROM easa_download_monitor WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return ['ok' => false, 'error' => 'not found'];
    }

    $url = trim((string) ($row['url'] ?? ''));
    $probe = rl_source_verify_http_probe_headers($url, 25);
    $code = (int) ($probe['http_code'] ?? 0);

    if ($code === 429) {
        $upd = $pdo->prepare('UPDATE easa_download_monitor SET checked_at = UTC_TIMESTAMP(), http_status = ?, last_error = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
        $upd->execute([429, 'HTTP 429 Too Many Requests — backoff before retry', $id]);

        return ['ok' => false, 'error' => '429 Too Many Requests'];
    }

    $sig = easa_download_monitor_signature_from_probe($probe);
    $prevSig = trim((string) ($row['content_hash'] ?? ''));
    $changed = $prevSig !== '' && $sig !== $prevSig;

    $etag = isset($probe['etag']) ? (string) $probe['etag'] : null;
    $lm = isset($probe['last_modified']) ? (string) $probe['last_modified'] : null;
    $cl = isset($probe['content_length']) ? (int) $probe['content_length'] : null;
    if ($cl !== null && $cl < 0) {
        $cl = null;
    }

    $final = (string) ($probe['final_url'] ?? $url);
    $err = (!$probe['ok'] && isset($probe['error'])) ? (string) $probe['error'] : null;

    $upd = $pdo->prepare('
        UPDATE easa_download_monitor SET
            checked_at = UTC_TIMESTAMP(),
            http_status = ?,
            final_url = ?,
            etag = ?,
            last_modified = ?,
            content_length = ?,
            content_hash = ?,
            changed_flag = CASE WHEN ? = 1 THEN 1 ELSE changed_flag END,
            last_error = ?,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = ?
    ');
    $upd->execute([
        $code,
        $final,
        $etag,
        $lm,
        $cl,
        $sig,
        $changed ? 1 : 0,
        $err,
        $id,
    ]);

    // First successful baseline: no "change" alert until second probe differs.
    if ($prevSig === '' && ($probe['ok'] ?? false)) {
        $pdo->prepare('UPDATE easa_download_monitor SET changed_flag = 0 WHERE id = ?')->execute([$id]);
        $changed = false;
    }

    return ['ok' => true, 'changed' => $changed];
}

/**
 * Probe every registered URL (cron). Sequential to avoid hammering EASA.
 *
 * @return array{probed: int, errors: list<string>}
 */
function easa_download_monitor_probe_all(PDO $pdo): array
{
    if (!easa_download_monitor_tables_ok($pdo)) {
        return ['probed' => 0, 'errors' => ['easa_download_monitor table missing']];
    }

    $ids = $pdo->query('SELECT id FROM easa_download_monitor ORDER BY id ASC');
    $errors = [];
    $n = 0;
    if ($ids instanceof PDOStatement) {
        while (($r = $ids->fetch(PDO::FETCH_ASSOC)) !== false) {
            $id = (int) ($r['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $res = easa_download_monitor_probe_row($pdo, $id);
            $n++;
            if (!($res['ok'] ?? false) && isset($res['error'])) {
                $errors[] = 'id ' . $id . ': ' . $res['error'];
            }
        }
    }

    return ['probed' => $n, 'errors' => $errors];
}
