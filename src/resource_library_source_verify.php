<?php
declare(strict_types=1);

/**
 * Per-edition HTTP checks for official source pages (AIM HTML, PHAK catalog, etc.).
 * Settings live in resource_library_editions.extra_config_json:
 * - source_verify_url (optional; crawlers may fall back to allowed_url_prefix)
 * - source_verify_interval: off | daily | weekly | monthly
 * - source_verify_state: server-managed probe results (etag, last check, change flag)
 */

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
 * Lightweight HEAD (or GET fallback) with response headers for change detection.
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
function rl_source_verify_http_probe(string $url, int $timeoutSec = 20): array
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
            // Servers that reject HEAD: request a single byte to keep headers + minimal body.
            $opts[CURLOPT_HTTPHEADER] = ['Range: bytes=0-0'];
        }
        curl_setopt_array($ch, $opts);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $final = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $cerr = curl_error($ch);
        curl_close($ch);

        return ['code' => $code, 'final' => $final !== '' ? $final : $url, 'cerr' => $cerr];
    };

    $hdr = [];
    $first = $run(true);
    // Some servers return 405/501 for HEAD; retry once with GET (no body via range not universally supported).
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
        $url = trim((string) $input['source_verify_url']);
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
