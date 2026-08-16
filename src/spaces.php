<?php
declare(strict_types=1);

/**
 * Minimal S3-compatible PUT uploader for DigitalOcean Spaces (AWS SigV4).
 * Uploads a binary string to: s3://{bucket}/{key}
 * and sets ACL to public-read so CDN URLs work without signed URLs.
 */

function cw_spaces_config(): array {
    $key = getenv('CW_SPACES_KEY') ?: '';
    $secret = getenv('CW_SPACES_SECRET') ?: '';
    $region = getenv('CW_SPACES_REGION') ?: 'us-east-1'; // safest default for Spaces signing
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
 * IMPORTANT: sets x-amz-acl: public-read so objects are publicly retrievable.
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
    $acl = 'public-read';

    $headers = [
        'content-type' => $contentType,
        'host' => $host,
        'x-amz-acl' => $acl,
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
        "" . "\n" .
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
        "x-amz-acl: {$acl}",
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
 * Stream a private object to Spaces. Does not set x-amz-acl.
 *
 * @param resource $stream
 */
function cw_spaces_put_private_stream(string $objectKey, $stream, int $byteSize, string $contentType): void
{
    if (!is_resource($stream)) {
        throw new RuntimeException('A readable stream is required.');
    }
    $byteSize = max(0, $byteSize);
    if ($byteSize < 1) {
        throw new RuntimeException('Upload is empty.');
    }
    $cfg = cw_spaces_config();
    $method = 'PUT';
    $service = 's3';
    $host = $cfg['bucket'] . '.' . $cfg['endpoint'];
    $canonicalUri = '/' . str_replace('%2F', '/', cw_spaces_uri_encode(ltrim($objectKey, '/')));
    $amzDate = gmdate('Ymd\THis\Z');
    $date = substr($amzDate, 0, 8);
    $payloadHash = 'UNSIGNED-PAYLOAD';
    $contentType = strtolower(trim($contentType));
    $signedHeaders = 'content-type;host;x-amz-content-sha256;x-amz-date';
    $canonicalHeaders =
        'content-type:' . $contentType . "\n" .
        'host:' . $host . "\n" .
        'x-amz-content-sha256:' . $payloadHash . "\n" .
        'x-amz-date:' . $amzDate . "\n";
    $canonicalRequest =
        $method . "\n" .
        $canonicalUri . "\n" .
        '' . "\n" .
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
        'AWS4-HMAC-SHA256 ' .
        'Credential=' . $cfg['key'] . '/' . $credentialScope . ', ' .
        'SignedHeaders=' . $signedHeaders . ', ' .
        'Signature=' . $signature;

    $ch = curl_init('https://' . $host . $canonicalUri);
    curl_setopt($ch, CURLOPT_UPLOAD, true);
    curl_setopt($ch, CURLOPT_INFILESIZE, $byteSize);
    curl_setopt($ch, CURLOPT_READFUNCTION, static function ($curl, $fd, int $length) use ($stream): string {
        $chunk = fread($stream, $length);
        return $chunk === false ? '' : $chunk;
    });
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Authorization: ' . $authorization,
        'Content-Type: ' . $contentType,
        'x-amz-content-sha256: ' . $payloadHash,
        'x-amz-date: ' . $amzDate,
        'Content-Length: ' . $byteSize,
        'Host: ' . $host,
    ));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 7200);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
    $resp = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($resp === false || $code < 200 || $code >= 300) {
        throw new RuntimeException('Private Spaces upload failed HTTP ' . $code . '. ' . $err . ' ' . substr((string)$resp, 0, 300));
    }
}

/**
 * Private SigV4 query-string URL. Does not set x-amz-acl; objects stay private.
 *
 * @param array<string,string> $extraHeaders lowercase header names to sign (e.g. content-type)
 */
function cw_spaces_presign(string $method, string $objectKey, int $expiresSeconds = 300, array $extraHeaders = []): string
{
    $cfg = cw_spaces_config();
    $method = strtoupper($method);
    $expiresSeconds = max(1, min(3600, $expiresSeconds));
    $service = 's3';
    $host = $cfg['bucket'] . '.' . $cfg['endpoint'];
    $canonicalUri = '/' . str_replace('%2F', '/', cw_spaces_uri_encode(ltrim($objectKey, '/')));
    $amzDate = gmdate('Ymd\THis\Z');
    $date = substr($amzDate, 0, 8);
    $payloadHash = 'UNSIGNED-PAYLOAD';

    $headers = array('host' => $host);
    foreach ($extraHeaders as $name => $value) {
        $headers[strtolower(trim((string)$name))] = trim((string)$value);
    }
    ksort($headers, SORT_STRING);
    $canonicalHeaders = '';
    $signedHeadersArr = array();
    foreach ($headers as $name => $value) {
        $canonicalHeaders .= $name . ':' . $value . "\n";
        $signedHeadersArr[] = $name;
    }
    $signedHeaders = implode(';', $signedHeadersArr);

    $credentialScope = $date . '/' . $cfg['region'] . '/' . $service . '/aws4_request';
    $query = array(
        'X-Amz-Algorithm' => 'AWS4-HMAC-SHA256',
        'X-Amz-Content-Sha256' => $payloadHash,
        'X-Amz-Credential' => $cfg['key'] . '/' . $credentialScope,
        'X-Amz-Date' => $amzDate,
        'X-Amz-Expires' => (string)$expiresSeconds,
        'X-Amz-SignedHeaders' => $signedHeaders,
    );
    ksort($query, SORT_STRING);
    $canonicalQuery = cw_spaces_canonical_query($query);

    $canonicalRequest =
        $method . "\n" .
        $canonicalUri . "\n" .
        $canonicalQuery . "\n" .
        $canonicalHeaders . "\n" .
        $signedHeaders . "\n" .
        $payloadHash;

    $stringToSign =
        "AWS4-HMAC-SHA256\n" .
        $amzDate . "\n" .
        $credentialScope . "\n" .
        hash('sha256', $canonicalRequest);

    $signingKey = cw_sigv4_signing_key($cfg['secret'], $date, $cfg['region'], $service);
    $signature = hash_hmac('sha256', $stringToSign, $signingKey);
    $query['X-Amz-Signature'] = $signature;

    return 'https://' . $host . $canonicalUri . '?' . cw_spaces_canonical_query($query);
}

/**
 * Server-side HEAD. Returns size/type if the private object exists.
 *
 * @return array{byte_size:int,content_type:string}|null
 */
function cw_spaces_head_object(string $objectKey): ?array
{
    $cfg = cw_spaces_config();
    $method = 'HEAD';
    $service = 's3';
    $host = $cfg['bucket'] . '.' . $cfg['endpoint'];
    $canonicalUri = '/' . str_replace('%2F', '/', cw_spaces_uri_encode(ltrim($objectKey, '/')));
    $amzDate = gmdate('Ymd\THis\Z');
    $date = substr($amzDate, 0, 8);
    $payloadHash = 'UNSIGNED-PAYLOAD';
    $signedHeaders = 'host;x-amz-content-sha256;x-amz-date';
    $canonicalHeaders =
        'host:' . $host . "\n" .
        'x-amz-content-sha256:' . $payloadHash . "\n" .
        'x-amz-date:' . $amzDate . "\n";

    $canonicalRequest =
        $method . "\n" .
        $canonicalUri . "\n" .
        '' . "\n" .
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
        'AWS4-HMAC-SHA256 ' .
        'Credential=' . $cfg['key'] . '/' . $credentialScope . ', ' .
        'SignedHeaders=' . $signedHeaders . ', ' .
        'Signature=' . $signature;

    $headerLines = array();
    $ch = curl_init('https://' . $host . $canonicalUri);
    curl_setopt($ch, CURLOPT_NOBODY, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'HEAD');
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Authorization: ' . $authorization,
        'x-amz-date: ' . $amzDate,
        'x-amz-content-sha256: ' . $payloadHash,
        'Host: ' . $host,
    ));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_HEADERFUNCTION, static function ($curl, string $header) use (&$headerLines): int {
        $len = strlen($header);
        $parts = explode(':', $header, 2);
        if (count($parts) === 2) {
            $headerLines[strtolower(trim($parts[0]))] = trim($parts[1]);
        }
        return $len;
    });
    curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code === 404 || $code === 403) {
        return null;
    }
    if ($code < 200 || $code >= 300) {
        return null;
    }
    $size = (int)($headerLines['content-length'] ?? 0);
    $type = (string)($headerLines['content-type'] ?? 'application/octet-stream');
    return array(
        'byte_size' => $size,
        'content_type' => $type,
    );
}

/**
 * Private server-side GET. Returns object bytes or null if missing.
 */
function cw_spaces_get_object(string $objectKey, int $maxBytes = 20971520): ?string
{
    $cfg = cw_spaces_config();
    $method = 'GET';
    $service = 's3';
    $host = $cfg['bucket'] . '.' . $cfg['endpoint'];
    $canonicalUri = '/' . str_replace('%2F', '/', cw_spaces_uri_encode(ltrim($objectKey, '/')));
    $amzDate = gmdate('Ymd\THis\Z');
    $date = substr($amzDate, 0, 8);
    $payloadHash = 'UNSIGNED-PAYLOAD';
    $signedHeaders = 'host;x-amz-content-sha256;x-amz-date';
    $canonicalHeaders =
        'host:' . $host . "\n" .
        'x-amz-content-sha256:' . $payloadHash . "\n" .
        'x-amz-date:' . $amzDate . "\n";

    $canonicalRequest =
        $method . "\n" .
        $canonicalUri . "\n" .
        '' . "\n" .
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
        'AWS4-HMAC-SHA256 ' .
        'Credential=' . $cfg['key'] . '/' . $credentialScope . ', ' .
        'SignedHeaders=' . $signedHeaders . ', ' .
        'Signature=' . $signature;

    $ch = curl_init('https://' . $host . $canonicalUri);
    curl_setopt($ch, CURLOPT_HTTPGET, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Authorization: ' . $authorization,
        'x-amz-date: ' . $amzDate,
        'x-amz-content-sha256: ' . $payloadHash,
        'Host: ' . $host,
    ));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 120);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $size = (int)curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
    curl_close($ch);
    if ($code === 404 || $code === 403) {
        return null;
    }
    if ($resp === false || $code < 200 || $code >= 300) {
        return null;
    }
    if (!is_string($resp)) {
        return null;
    }
    if ($maxBytes > 0 && strlen($resp) > $maxBytes) {
        throw new RuntimeException('Private Spaces object exceeds the allowed download size.');
    }
    unset($size);
    return $resp;
}

function cw_spaces_public_url(string $objectKey): string
{
    $cfg = cw_spaces_config();
    return rtrim((string)$cfg['cdnBase'], '/') . '/' . ltrim($objectKey, '/');
}

/**
 * Make an existing object anonymously readable so the Spaces CDN can serve it.
 */
function cw_spaces_put_acl(string $objectKey, string $acl = 'public-read'): bool
{
    $cfg = cw_spaces_config();
    $method = 'PUT';
    $service = 's3';
    $host = $cfg['bucket'] . '.' . $cfg['endpoint'];
    $canonicalUri = '/' . str_replace('%2F', '/', cw_spaces_uri_encode(ltrim($objectKey, '/')));
    $amzDate = gmdate('Ymd\THis\Z');
    $date = substr($amzDate, 0, 8);
    $payloadHash = 'UNSIGNED-PAYLOAD';
    $acl = trim($acl) !== '' ? trim($acl) : 'public-read';
    $canonicalQuery = 'acl=';
    $signedHeaders = 'host;x-amz-acl;x-amz-content-sha256;x-amz-date';
    $canonicalHeaders =
        'host:' . $host . "\n" .
        'x-amz-acl:' . $acl . "\n" .
        'x-amz-content-sha256:' . $payloadHash . "\n" .
        'x-amz-date:' . $amzDate . "\n";

    $canonicalRequest =
        $method . "\n" .
        $canonicalUri . "\n" .
        $canonicalQuery . "\n" .
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
        'AWS4-HMAC-SHA256 ' .
        'Credential=' . $cfg['key'] . '/' . $credentialScope . ', ' .
        'SignedHeaders=' . $signedHeaders . ', ' .
        'Signature=' . $signature;

    $url = 'https://' . $host . $canonicalUri . '?acl=';
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Authorization: ' . $authorization,
        'x-amz-acl: ' . $acl,
        'x-amz-content-sha256: ' . $payloadHash,
        'x-amz-date: ' . $amzDate,
        'Content-Length: 0',
    ));
    curl_setopt($ch, CURLOPT_POSTFIELDS, '');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code >= 200 && $code < 300;
}

/**
 * @param array<string,string> $params
 */
function cw_spaces_canonical_query(array $params): string
{
    ksort($params, SORT_STRING);
    $pairs = array();
    foreach ($params as $k => $v) {
        $pairs[] = cw_spaces_uri_encode((string)$k) . '=' . cw_spaces_uri_encode((string)$v);
    }
    return implode('&', $pairs);
}

/**
 * RFC3986-style encoding for SigV4 query (AWS: URI-encode each byte except unreserved).
 */
function cw_spaces_uri_encode(string $s): string
{
    return str_replace('%7E', '~', rawurlencode($s));
}

/**
 * One ListObjectsV2 page (S3-compatible GET). Uses same credentials as uploads.
 *
 * @return array{keys: list<string>, is_truncated: bool, next_continuation_token: ?string, http_code: int, body_snippet: string}
 */
function cw_spaces_list_objects_v2_page(string $prefix, ?string $continuationToken = null, int $maxKeys = 1000): array
{
    $cfg = cw_spaces_config();

    $params = [
        'list-type' => '2',
        'max-keys' => (string) min(1000, max(1, $maxKeys)),
        'prefix' => $prefix,
    ];
    if ($continuationToken !== null && $continuationToken !== '') {
        $params['continuation-token'] = $continuationToken;
    }
    ksort($params);
    $pairs = [];
    foreach ($params as $k => $v) {
        $pairs[] = cw_spaces_uri_encode((string) $k) . '=' . cw_spaces_uri_encode((string) $v);
    }
    $canonicalQueryString = implode('&', $pairs);

    $method = 'GET';
    $service = 's3';
    $amzDate = gmdate('Ymd\THis\Z');
    $dateStamp = substr($amzDate, 0, 8);
    $host = $cfg['bucket'] . '.' . $cfg['endpoint'];
    $canonicalUri = '/';
    $payloadHash = 'UNSIGNED-PAYLOAD';
    $signedHeaders = 'host;x-amz-content-sha256;x-amz-date';
    $canonicalHeaders =
        'host:' . $host . "\n" .
        'x-amz-content-sha256:' . $payloadHash . "\n" .
        'x-amz-date:' . $amzDate . "\n";

    $canonicalRequest =
        $method . "\n" .
        $canonicalUri . "\n" .
        $canonicalQueryString . "\n" .
        $canonicalHeaders . "\n" .
        $signedHeaders . "\n" .
        $payloadHash;

    $credentialScope = $dateStamp . '/' . $cfg['region'] . '/' . $service . '/aws4_request';
    $stringToSign =
        "AWS4-HMAC-SHA256\n" .
        $amzDate . "\n" .
        $credentialScope . "\n" .
        hash('sha256', $canonicalRequest);

    $signingKey = cw_sigv4_signing_key($cfg['secret'], $dateStamp, $cfg['region'], $service);
    $signature = hash_hmac('sha256', $stringToSign, $signingKey);

    $authorization =
        'AWS4-HMAC-SHA256 ' .
        'Credential=' . $cfg['key'] . '/' . $credentialScope . ', ' .
        'SignedHeaders=' . $signedHeaders . ', ' .
        'Signature=' . $signature;

    $url = 'https://' . $host . '/?' . $canonicalQueryString;

    $curlHeaders = [
        'Authorization: ' . $authorization,
        'x-amz-date: ' . $amzDate,
        'x-amz-content-sha256: ' . $payloadHash,
        'Host: ' . $host,
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPGET, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $curlHeaders);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 120);

    $resp = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $out = [
        'keys' => [],
        'is_truncated' => false,
        'next_continuation_token' => null,
        'http_code' => $code,
        'body_snippet' => is_string($resp) ? substr($resp, 0, 400) : '',
    ];

    if ($resp === false || $code < 200 || $code >= 300) {
        return $out;
    }

    $sx = @simplexml_load_string((string) $resp);
    if ($sx === false) {
        return $out;
    }

    if (isset($sx->Contents)) {
        foreach ($sx->Contents as $c) {
            $k = (string) ($c->Key ?? '');
            if ($k !== '') {
                $out['keys'][] = $k;
            }
        }
    }
    $out['is_truncated'] = isset($sx->IsTruncated) && strtolower((string) $sx->IsTruncated) === 'true';
    if (isset($sx->NextContinuationToken) && (string) $sx->NextContinuationToken !== '') {
        $out['next_continuation_token'] = (string) $sx->NextContinuationToken;
    }

    return $out;
}

/**
 * List all object keys under a prefix (paginated).
 *
 * @return list<string>
 */
function cw_spaces_list_all_keys_under_prefix(string $prefix): array
{
    $all = [];
    $token = null;
    do {
        $page = cw_spaces_list_objects_v2_page($prefix, $token);
        if ($page['http_code'] < 200 || $page['http_code'] >= 300) {
            throw new RuntimeException(
                'Spaces ListObjectsV2 failed HTTP ' . $page['http_code'] . '. Body: ' . $page['body_snippet']
            );
        }
        foreach ($page['keys'] as $k) {
            $all[] = $k;
        }
        if (!$page['is_truncated']) {
            break;
        }
        $token = $page['next_continuation_token'];
        if ($token === null || $token === '') {
            break;
        }
    } while (true);

    return $all;
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