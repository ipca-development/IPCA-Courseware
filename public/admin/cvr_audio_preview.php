<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/spaces.php';

cw_require_admin();

function cvrt_amz_now(): array
{
    $dt = new DateTime('now', new DateTimeZone('UTC'));
    return [
        'amz_date'  => $dt->format('Ymd\THis\Z'),
        'date_only' => $dt->format('Ymd'),
    ];
}

function cvrt_rawurlencode_path(string $path): string
{
    $parts = explode('/', ltrim($path, '/'));
    $enc = [];
    foreach ($parts as $part) {
        $enc[] = rawurlencode($part);
    }
    return '/' . implode('/', $enc);
}

function cvrt_sign(string $key, string $msg): string
{
    return hash_hmac('sha256', $msg, $key, true);
}

function cvrt_spaces_get_object(string $objectKey): array
{
    $cfg = cw_spaces_config();
    $times = cvrt_amz_now();

    $bucket = (string)$cfg['bucket'];
    $region = (string)$cfg['region'];
    $accessKey = (string)$cfg['key'];
    $secretKey = (string)$cfg['secret'];
    $endpoint = (string)$cfg['endpoint'];

    $canonicalUri = cvrt_rawurlencode_path($objectKey);
    $payloadHash = hash('sha256', '');
    $host = $bucket . '.' . $endpoint;

    $canonicalHeaders =
        'host:' . $host . "\n" .
        'x-amz-content-sha256:' . $payloadHash . "\n" .
        'x-amz-date:' . $times['amz_date'] . "\n";

    $signedHeaders = 'host;x-amz-content-sha256;x-amz-date';

    $canonicalRequest =
        "GET\n" .
        $canonicalUri . "\n" .
        "\n" .
        $canonicalHeaders . "\n" .
        $signedHeaders . "\n" .
        $payloadHash;

    $credentialScope = $times['date_only'] . '/' . $region . '/s3/aws4_request';
    $stringToSign =
        "AWS4-HMAC-SHA256\n" .
        $times['amz_date'] . "\n" .
        $credentialScope . "\n" .
        hash('sha256', $canonicalRequest);

    $kDate = cvrt_sign('AWS4' . $secretKey, $times['date_only']);
    $kRegion = cvrt_sign($kDate, $region);
    $kService = cvrt_sign($kRegion, 's3');
    $kSigning = cvrt_sign($kService, 'aws4_request');
    $signature = hash_hmac('sha256', $stringToSign, $kSigning);

    $authorization =
        'AWS4-HMAC-SHA256 ' .
        'Credential=' . $accessKey . '/' . $credentialScope . ', ' .
        'SignedHeaders=' . $signedHeaders . ', ' .
        'Signature=' . $signature;

    $url = 'https://' . $host . $canonicalUri;

    $headers = [];
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Host: ' . $host,
        'x-amz-content-sha256: ' . $payloadHash,
        'x-amz-date: ' . $times['amz_date'],
        'Authorization: ' . $authorization,
    ]);
    curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $headerLine) use (&$headers) {
        $len = strlen($headerLine);
        $parts = explode(':', $headerLine, 2);
        if (count($parts) === 2) {
            $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
        }
        return $len;
    });
    curl_setopt($ch, CURLOPT_TIMEOUT, 120);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);

    $body = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false) {
        throw new RuntimeException('Spaces preview request failed: ' . $err);
    }

    if ($code < 200 || $code >= 300) {
        throw new RuntimeException('Spaces preview request failed with HTTP ' . $code);
    }

    return [
        'body' => (string)$body,
        'headers' => $headers,
    ];
}

$key = trim((string)($_GET['key'] ?? ''));
if ($key === '') {
    http_response_code(400);
    exit('Missing key');
}

try {
    $obj = cvrt_spaces_get_object($key);

    $contentType = (string)($obj['headers']['content-type'] ?? '');
    if ($contentType === '') {
        $ext = strtolower(pathinfo($key, PATHINFO_EXTENSION));
        if ($ext === 'mp3') {
            $contentType = 'audio/mpeg';
        } elseif ($ext === 'wav') {
            $contentType = 'audio/wav';
        } elseif ($ext === 'm4a') {
            $contentType = 'audio/mp4';
        } else {
            $contentType = 'application/octet-stream';
        }
    }

    header('Content-Type: ' . $contentType);
    header('Content-Length: ' . strlen((string)$obj['body']));
    header('Cache-Control: private, max-age=300');
    header('Accept-Ranges: bytes');

    echo $obj['body'];
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Audio preview failed: ' . $e->getMessage();
    exit;
}