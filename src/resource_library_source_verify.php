<?php
declare(strict_types=1);

/**
 * Per-edition HTTP checks for official source pages (AIM HTML, PHAK catalog, etc.).
 * Settings live in resource_library_editions.extra_config_json:
 * - source_verify_url (optional; crawlers may fall back to allowed_url_prefix)
 * - source_verify_interval: off | daily | weekly | monthly
 * - source_verify_state: server-managed probe results (etag, last check, change flag,
 *   and when available page_last_updated from the published HTML for document control)
 *
 * After headers succeed, a bounded GET reads HTML and extracts lines such as
 * "Last updated: Friday, November 3, 2023" (e.g. FAA handbook pages) for audit evidence.
 */

/** Maximum HTML bytes to download for on-page "Last updated" extraction (footer may be late in document). */
const RL_SOURCE_VERIFY_HTML_CAP_BYTES = 2097152; // 2 MiB

/**
 * @return list<string>
 */
function rl_source_verify_interval_slugs(): array
{
    return ['off', 'daily', 'weekly', 'monthly'];
}

function rl_source_verify_normalize_interval(?string $raw): string
{
    $s = strtolower(trim((string) $raw));

    return in_array($s, rl_source_verify_interval_slugs(), true) ? $s : 'off';
}

function rl_source_verify_interval_seconds(string $interval): ?int
{
    return match ($interval) {
        'daily' => 86400,
        'weekly' => 604800,
        'monthly' => 2592000, // 30 days
        default => null,
    };
}

/**
 * @param array<string, mixed>|null $state
 */
function rl_source_verify_should_run(?array $state, string $interval, DateTimeImmutable $nowUtc): bool
{
    $sec = rl_source_verify_interval_seconds($interval);
    if ($sec === null) {
        return false;
    }
    if (!is_array($state)) {
        return true;
    }
    $lastStr = trim((string) ($state['checked_at'] ?? ''));
    if ($lastStr === '') {
        return true;
    }
    try {
        $last = new DateTimeImmutable($lastStr, new DateTimeZone('UTC'));
    } catch (Throwable) {
        return true;
    }

    return ($nowUtc->getTimestamp() - $last->getTimestamp()) >= $sec;
}

/**
 * @param array<string, mixed> $extra Decoded extra_config_json
 */
function rl_source_verify_resolve_url(array $extra, string $resourceType): string
{
    $u = trim((string) ($extra['source_verify_url'] ?? ''));
    if ($u !== '') {
        return $u;
    }
    if ($resourceType === RL_RESOURCE_CRAWLER) {
        return trim((string) ($extra['allowed_url_prefix'] ?? ''));
    }
    if ($resourceType === RL_RESOURCE_API) {
        return trim((string) ($extra['api_base_url'] ?? ''));
    }

    return '';
}

function rl_source_verify_validate_https_url(string $url, int $maxLen = 2048): ?string
{
    $url = trim($url);
    if ($url === '') {
        return null;
    }
    if (strlen($url) > $maxLen) {
        return 'URL is too long (max ' . $maxLen . ' characters).';
    }
    if (!preg_match('#^https://#i', $url)) {
        return 'URL must start with https://';
    }

    return null;
}

/**
 * Normalize pasted verify URLs (common paste sources use http:// or stray Unicode spaces).
 */
function rl_source_verify_sanitize_verify_url_input(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }
    $url = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $url) ?? $url;
    $url = trim($url);
    if (preg_match('#^http://#i', $url)) {
        $url = 'https://' . substr($url, 7);
    }

    return $url;
}

/**
 * Extract published "last updated" / similar editorial lines from HTML (document control).
 * Tuned for FAA handbook-style pages, e.g. "Last updated: Friday, November 3, 2023".
 */
function rl_source_verify_parse_page_last_updated(string $html): ?string
{
    // Prefer blocks that close before the next tag (FAA often wraps the date in inline markup).
    $patterns = [
        '/Last\s+updated\s*:\s*(.+?)<\/(?:p|div|h\d|li|span|td|section)/isu',
        '/Page\s+last\s+updated\s*:\s*(.+?)<\/(?:p|div|li|span|td)/isu',
        '/Date\s+last\s+updated\s*:\s*(.+?)<\/(?:p|div|li|span|td)/isu',
        '/Last\s+revised\s*:\s*(.+?)<\/(?:p|div|li|span|td)/isu',
        '/Last\s+updated\s*:\s*([^<\n\r]+)/iu',
        '/Page\s+last\s+updated\s*:\s*([^<\n\r]+)/iu',
        '/Date\s+last\s+updated\s*:\s*([^<\n\r]+)/iu',
        '/Last\s+revised\s*:\s*([^<\n\r]+)/iu',
    ];
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $html, $m)) {
            $t = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $t = preg_replace('/\s+/u', ' ', $t) ?? $t;
            if ($t !== '' && strlen($t) <= 512) {
                return $t;
            }
        }
    }

    return null;
}

/**
 * GET up to $maxBytes of HTML from URL (follow redirects) for on-page date extraction.
 *
 * @return array{ok: bool, http_code: int, final_url: string, body: string, error?: string}
 */
function rl_source_verify_fetch_html_snippet(string $url, int $maxBytes, int $timeoutSec): array
{
    $url = trim($url);
    if ($url === '' || !function_exists('curl_init')) {
        return ['ok' => false, 'http_code' => 0, 'final_url' => $url, 'body' => '', 'error' => 'Missing URL or cURL'];
    }

    $buf = '';
    $writer = static function ($ch, string $data) use (&$buf): int {
        $buf .= $data;

        return strlen($data);
    };
    $ch = curl_init($url);
    if ($ch === false) {
        return ['ok' => false, 'http_code' => 0, 'final_url' => $url, 'body' => '', 'error' => 'curl_init failed'];
    }
    $opts = [
        CURLOPT_HTTPGET => true,
        CURLOPT_HEADER => false,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => $timeoutSec,
        CURLOPT_CONNECTTIMEOUT => min(12, $timeoutSec),
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT => 'IPCA-ResourceLibrary/1.2 (source verify; HTML snippet)',
        CURLOPT_WRITEFUNCTION => $writer,
    ];
    if (!defined('CURLOPT_MAXFILESIZE')) {
        $opts[CURLOPT_HTTPHEADER] = ['Range: bytes=0-' . (string) ($maxBytes - 1)];
    }
    curl_setopt_array($ch, $opts);
    if (defined('CURLOPT_MAXFILESIZE')) {
        curl_setopt($ch, CURLOPT_MAXFILESIZE, $maxBytes);
    }
    $execOk = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $final = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    $cerr = curl_error($ch);
    $final = $final !== '' ? $final : $url;
    if ($code === 0 && $buf === '') {
        return ['ok' => false, 'http_code' => 0, 'final_url' => $final, 'body' => '', 'error' => $cerr !== '' ? $cerr : 'No HTTP response'];
    }
    $truncated = !$execOk && $buf !== '' && str_contains(strtolower($cerr), 'maximum file size exceeded');
    $ok = $code >= 200 && $code < 400 && ($execOk || $truncated);
    if (!$ok && $buf === '') {
        return [
            'ok' => false,
            'http_code' => $code,
            'final_url' => $final,
            'body' => '',
            'error' => 'HTTP ' . $code . ($cerr !== '' ? ' · ' . $cerr : ''),
        ];
    }

    return [
        'ok' => true,
        'http_code' => $code,
        'final_url' => $final,
        'body' => $buf,
        'error' => null,
    ];
}

/**
 * HEAD (or minimal GET) with response headers only.
 *
 * @return array{
 *   ok: bool,
 *   http_code: int,
 *   final_url: string,
 *   etag?: string,
 *   last_modified?: string,
 *   content_length?: string,
 *   error?: string
 * }
 */
function rl_source_verify_http_probe_headers(string $url, int $timeoutSec = 20): array
{
    $url = trim($url);
    if ($url === '') {
        return ['ok' => false, 'http_code' => 0, 'final_url' => '', 'error' => 'Empty URL'];
    }
    $err = rl_source_verify_validate_https_url($url);
    if ($err !== null) {
        return ['ok' => false, 'http_code' => 0, 'final_url' => '', 'error' => $err];
    }

    if (!function_exists('curl_init')) {
        return ['ok' => false, 'http_code' => 0, 'final_url' => $url, 'error' => 'cURL extension required for source verify'];
    }

    /** @var array<string, string> $hdr */
    $hdr = [];
    $onHeader = static function ($ch, string $line) use (&$hdr): int {
        $line = rtrim($line, "\r\n");
        $n = strlen($line);
        if ($n === 0 || str_starts_with($line, 'HTTP/')) {
            return $n;
        }
        $pos = strpos($line, ':');
        if ($pos === false) {
            return $n;
        }
        $k = strtolower(trim(substr($line, 0, $pos)));
        $v = trim(substr($line, $pos + 1));
        if ($k !== '') {
            $hdr[$k] = $v;
        }

        return $n;
    };

    $run = static function (bool $nobody) use ($url, $timeoutSec, $onHeader): array {
        $ch = curl_init($url);
        if ($ch === false) {
            return ['ok' => false, 'http_code' => 0, 'final_url' => '', 'error' => 'curl_init failed'];
        }
        $opts = [
            CURLOPT_NOBODY => $nobody,
            CURLOPT_HEADER => false,
            CURLOPT_HEADERFUNCTION => $onHeader,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => $timeoutSec,
            CURLOPT_CONNECTTIMEOUT => min(12, $timeoutSec),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => $nobody
                ? 'IPCA-ResourceLibrary/1.1 (source verify; HEAD)'
                : 'IPCA-ResourceLibrary/1.1 (source verify; GET)',
        ];
        if (!$nobody) {
            $opts[CURLOPT_HTTPHEADER] = ['Range: bytes=0-0'];
        }
        curl_setopt_array($ch, $opts);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $final = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $cerr = curl_error($ch);

        return ['code' => $code, 'final' => $final !== '' ? $final : $url, 'cerr' => $cerr];
    };

    $hdr = [];
    $first = $run(true);
    if ($first['code'] === 405 || $first['code'] === 501 || $first['code'] === 400) {
        $hdr = [];
        $first = $run(false);
    }

    $code = $first['code'];
    $final = $first['final'];
    $cerr = $first['cerr'];
    if ($code === 0) {
        return ['ok' => false, 'http_code' => 0, 'final_url' => $final, 'error' => $cerr !== '' ? $cerr : 'No HTTP response'];
    }

    $etag = isset($hdr['etag']) ? trim((string) $hdr['etag']) : '';
    $lastMod = isset($hdr['last-modified']) ? trim((string) $hdr['last-modified']) : '';
    $cl = isset($hdr['content-length']) ? trim((string) $hdr['content-length']) : '';

    $ok = $code >= 200 && $code < 400;
    $out = [
        'ok' => $ok,
        'http_code' => $code,
        'final_url' => $final,
    ];
    if ($etag !== '') {
        $out['etag'] = $etag;
    }
    if ($lastMod !== '') {
        $out['last_modified'] = $lastMod;
    }
    if ($cl !== '') {
        $out['content_length'] = $cl;
    }
    if (!$ok) {
        $out['error'] = 'HTTP ' . $code . ($cerr !== '' ? ' · ' . $cerr : '');
    }

    return $out;
}

/**
 * Headers plus bounded HTML read to capture on-page "Last updated" (document control / compliance).
 *
 * @return array{
 *   ok: bool,
 *   http_code: int,
 *   final_url: string,
 *   etag?: string,
 *   last_modified?: string,
 *   content_length?: string,
 *   page_last_updated?: string,
 *   page_body_fetch_error?: string,
 *   error?: string
 * }
 */
function rl_source_verify_http_probe(string $url, int $timeoutSec = 20): array
{
    $url = rl_source_verify_sanitize_verify_url_input(trim($url));
    $out = rl_source_verify_http_probe_headers($url, $timeoutSec);
    if (!($out['ok'] ?? false)) {
        return $out;
    }

    $getUrl = (string) ($out['final_url'] ?? $url);
    $snippet = rl_source_verify_fetch_html_snippet($getUrl, RL_SOURCE_VERIFY_HTML_CAP_BYTES, $timeoutSec);
    if (!($snippet['ok'] ?? false)) {
        $out['page_body_fetch_error'] = (string) ($snippet['error'] ?? 'HTML snippet fetch failed');

        return $out;
    }

    $html = (string) ($snippet['body'] ?? '');
    $parsed = $html !== '' ? rl_source_verify_parse_page_last_updated($html) : null;
    $out['page_last_updated'] = $parsed ?? '';

    return $out;
}

/**
 * Build a compact signature string for change detection.
 *
 * @param array<string, mixed> $probe
 */
function rl_source_verify_signature_from_probe(array $probe): string
{
    $parts = [
        (string) ($probe['etag'] ?? ''),
        (string) ($probe['last_modified'] ?? ''),
        (string) ($probe['content_length'] ?? ''),
        (string) ($probe['final_url'] ?? ''),
        (string) ($probe['http_code'] ?? ''),
        (string) ($probe['page_last_updated'] ?? ''),
    ];

    return implode("\n", $parts);
}

/**
 * @param array<string, mixed> $oldState
 * @param array<string, mixed> $probe
 * @return array<string, mixed>
 */
function rl_source_verify_advance_state(array $oldState, array $probe, DateTimeImmutable $nowUtc): array
{
    $checked = $nowUtc->format('Y-m-d\TH:i:s\Z');
    $next = [
        'checked_at' => $checked,
        'http_code' => $probe['http_code'] ?? 0,
        'final_url' => (string) ($probe['final_url'] ?? ''),
    ];
    if (isset($probe['etag'])) {
        $next['etag'] = (string) $probe['etag'];
    }
    if (isset($probe['last_modified'])) {
        $next['last_modified'] = (string) $probe['last_modified'];
    }
    if (isset($probe['content_length'])) {
        $next['content_length'] = (string) $probe['content_length'];
    }
    if (array_key_exists('page_last_updated', $probe)) {
        $next['page_last_updated'] = (string) $probe['page_last_updated'];
    } elseif (isset($probe['page_body_fetch_error'], $oldState['page_last_updated']) && (string) $oldState['page_last_updated'] !== '') {
        $next['page_last_updated'] = (string) $oldState['page_last_updated'];
    }
    if (isset($probe['page_body_fetch_error'])) {
        $next['page_body_fetch_error'] = (string) $probe['page_body_fetch_error'];
    }

    if (!($probe['ok'] ?? false)) {
        $next['last_error'] = (string) ($probe['error'] ?? 'Probe failed');
        // Keep prior signature fields for comparison on next success
        foreach (['signature', 'change_detected', 'change_detected_at'] as $k) {
            if (isset($oldState[$k])) {
                $next[$k] = $oldState[$k];
            }
        }

        return $next;
    }

    unset($next['last_error']);
    if (!isset($probe['page_body_fetch_error'])) {
        unset($next['page_body_fetch_error']);
    }
    $sig = rl_source_verify_signature_from_probe($probe);
    $next['signature'] = $sig;
    $prevSig = trim((string) ($oldState['signature'] ?? ''));
    $changeDetected = false;
    if ($prevSig !== '' && $sig !== '' && $prevSig !== $sig) {
        $changeDetected = true;
    }
    $next['change_detected'] = $changeDetected || !empty($oldState['change_detected']);
    if ($changeDetected) {
        $next['change_detected_at'] = $checked;
    } elseif (!empty($oldState['change_detected_at'])) {
        $next['change_detected_at'] = (string) $oldState['change_detected_at'];
    }

    return $next;
}

/**
 * Merge user-submitted verify fields into extra; optionally reset state when verify URL changes.
 * Only keys present in $input are applied so other edition saves do not clear verify settings.
 *
 * @param array<string, mixed> $extra
 * @param array<string, mixed> $input Request body keys source_verify_url, source_verify_interval
 * @return array{extra: array<string, mixed>, error?: string}
 */
function rl_source_verify_merge_user_extra(array $extra, array $input): array
{
    $prevUrl = trim((string) ($extra['source_verify_url'] ?? ''));
    if (array_key_exists('source_verify_url', $input)) {
        $url = rl_source_verify_sanitize_verify_url_input((string) $input['source_verify_url']);
        $urlErr = rl_source_verify_validate_https_url($url);
        if ($urlErr !== null) {
            return ['extra' => $extra, 'error' => $urlErr];
        }
        $extra['source_verify_url'] = $url;
        if ($url !== $prevUrl) {
            $extra['source_verify_state'] = [];
        }
    }
    if (array_key_exists('source_verify_interval', $input)) {
        $extra['source_verify_interval'] = rl_source_verify_normalize_interval((string) $input['source_verify_interval']);
    }

    return ['extra' => $extra];
}
