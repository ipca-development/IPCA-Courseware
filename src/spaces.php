<?php
declare(strict_types=1);

/**
 * Minimal S3-compatible PUT uploader for DigitalOcean Spaces (AWS SigV4).
 * Uploads a binary string to: s3://{bucket}/{key}
 */

function cw_spaces_config(): array {
    $key = getenv('CW_SPACES_KEY') ?: '';
    $secret = getenv('CW_SPACES_SECRET') ?: '';
    $region = getenv('CW_SPACES_REGION') ?: 'nyc3';
    $bucket = getenv('CW_SPACES_BUCKET') ?: '';
    $endpoint = getenv('CW_SPACES_ENDPOINT') ?: 'nyc3.digitaloceanspaces.com';
    $cdnBase = rtrim(getenv('CW_SPACES_CDN_BASE') ?: '', '/');

    if ($key === '' || $secret === '' || $bucket === '' || $cdnBase === '') {
        throw new RuntimeException("Spaces env vars missing (CW_SPACES_KEY/SECRET/BUCKET/CDN_BASE).");
    }

    return [
        'key' => $key,
        'secret' => $secret,
        'region' => $region,
        'bucket' => $bucket,
        'endpoint' => $endpoint,
        'cdnBase' => $cdnBase,
    ];
}

function cw_hmac(string $key, string $data, bool $raw = true): string {
    return hash_hmac('sha256', $data, $key, $raw);
}

function cw_sigv4_signing_key(string $secret, string $date, string $region, string $service): string {
    $kDate = cw_hmac('AWS4' . $secret, $date);
    $kRegion = cw_hmac($kDate, $region);
    $kService = cw_hmac($kRegion, $service);
    return cw_hmac($kService, 'aws4_request');
}

/**
 * Upload bytes to Spaces and return the key + CDN URL.
 */
function cw_spaces_put_object(string $objectKey, string $bytes, string $contentType): array {
    $cfg = cw_spaces_config();

    $method = 'PUT';
    $service = 's3';
    $host = $cfg['bucket'] . '.' . $cfg['endpoint'];
    $uri = '/' . ltrim($objectKey, '/');

    $t = time();
    $amzDate = gmdate('Ymd\THis\Z', $t);
    $date = gmdate('Ymd', $t);

    $payloadHash = hash('sha256', $bytes);

    $headers = [
        'content-type' => $contentType,
        'host' => $host,
        'x-amz-content-sha256' => $payloadHash,
        'x-amz-date' => $amzDate,
    ];

    ksort($headers);
    $canonicalHeaders = '';
    $signedHeadersArr = [];
    foreach ($headers as $k => $v) {
        $canonicalHeaders .= $k . ':' . trim((string)$v) . "\n";
        $signedHeadersArr[] = $k;
    }
    $signedHeaders = implode(';', $signedHeadersArr);

    $canonicalRequest =
        $method . "\n" .
        $uri . "\n" .
        "" . "\n" . // query string
        $canonicalHeaders . "\n" .
        $signedHeaders . "\n" .
        $payloadHash;

    $credentialScope = $date . '/' . $cfg['region'] . '/' . $service . '/aws4_request';
    $stringToSign =
        "AWS4-HMAC-SHA256\n" .
        $amzDate . "\n" .
        $credentialScope . "\n" .
        hash('sha256', $canonicalRequest);

    $signingKey = cw_sigv4_signing_key($cfg['secret'], $date, $cfg['region'], $service);
    $signature = hash_hmac('sha256', $stringToSign, $signingKey);

    $authorization =
        "AWS4-HMAC-SHA256 " .
        "Credential=" . $cfg['key'] . "/" . $credentialScope . ", " .
        "SignedHeaders=" . $signedHeaders . ", " .
        "Signature=" . $signature;

    $curlHeaders = [
        "Authorization: {$authorization}",
        "x-amz-date: {$amzDate}",
        "x-amz-content-sha256: {$payloadHash}",
        "Content-Type: {$contentType}",
    ];

    $url = "https://{$host}{$uri}";

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
    curl_setopt($ch, CURLOPT_HTTPHEADER, $curlHeaders);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $bytes);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);

    $resp = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($resp === false || $code < 200 || $code >= 300) {
        throw new RuntimeException("Spaces upload failed HTTP {$code}. {$err} " . substr((string)$resp, 0, 300));
    }

    $cdnUrl = $cfg['cdnBase'] . '/' . ltrim($objectKey, '/');

    return ['key' => $objectKey, 'cdn_url' => $cdnUrl];
}

/**
 * Resolve a background URL from backgrounds.bg_path.
 * - If bg_path starts with http => use as-is
 * - If bg_path starts with / => treat as local path
 * - Else => treat as Spaces key under CW_SPACES_CDN_BASE
 */
function cw_resolve_bg_url(string $bgPath): string {
    $bgPath = trim($bgPath);
    if ($bgPath === '') return '';

    if (preg_match('#^https?://#i', $bgPath)) return $bgPath;

    if (str_starts_with($bgPath, '/')) return $bgPath;

    $cfg = cw_spaces_config();
    return $cfg['cdnBase'] . '/' . ltrim($bgPath, '/');
}