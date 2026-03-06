<?php
require_once __DIR__ . '/../../../src/bootstrap.php';

cw_require_login();
header('Content-Type: application/json; charset=utf-8');

if (!function_exists('http_response_code')) {
    function http_response_code($code = null) {
        static $http_code = 200;
        if ($code !== null) {
            $http_code = (int)$code;
            header('X-PHP-Response-Code: ' . $http_code, true, $http_code);
        }
        return $http_code;
    }
}

function json_out(array $x): void {
    echo json_encode($x, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_fail(int $code, string $msg, array $extra = array()): void {
    http_response_code($code);
    $out = array('ok' => false, 'error' => $msg);
    foreach ($extra as $k => $v) $out[$k] = $v;
    echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function env_required(string $key): string {
    $v = getenv($key);
    if ($v === false || $v === '') {
        throw new RuntimeException('Missing environment variable: ' . $key);
    }
    return (string)$v;
}

function read_json_input(): array {
    $raw = file_get_contents('php://input');
    $j = json_decode($raw, true);
    return is_array($j) ? $j : array();
}

function rfc3986_query(array $params): string {
    ksort($params, SORT_STRING);
    $pairs = array();
    foreach ($params as $k => $v) {
        $pairs[] = rawurlencode((string)$k) . '=' . rawurlencode((string)$v);
    }
    return implode('&', $pairs);
}

function sigv4_key(string $secret, string $date, string $region, string $service) {
    $kDate    = hash_hmac('sha256', $date, 'AWS4' . $secret, true);
    $kRegion  = hash_hmac('sha256', $region, $kDate, true);
    $kService = hash_hmac('sha256', $service, $kRegion, true);
    return hash_hmac('sha256', 'aws4_request', $kService, true);
}

function clean_ext(string $ext, string $default): string {
    $ext = strtolower(trim($ext));
    $ext = preg_replace('~[^a-z0-9]~', '', $ext);
    if ($ext === '') $ext = $default;
    return $ext;
}

function build_spaces_public_url(string $base, string $key): string {
    return rtrim($base, '/') . '/' . str_replace('%2F', '/', rawurlencode($key));
}

try {
    $u = cw_current_user($pdo);
    $role = (string)($u['role'] ?? '');

    if ($role !== 'student' && $role !== 'admin') {
        json_fail(403, 'Forbidden');
    }

    $in = read_json_input();

    $testId = (int)($in['test_id'] ?? 0);
    $kind   = trim((string)($in['kind'] ?? ''));
    $itemId = (int)($in['item_id'] ?? 0);
    $idx    = (int)($in['idx'] ?? 0);
    $ext    = clean_ext((string)($in['ext'] ?? ''), 'mp3');

    if ($testId <= 0) json_fail(400, 'Missing test_id');
    if ($kind === '') json_fail(400, 'Missing kind');

    $userId = (int)($u['id'] ?? 0);

    if ($role === 'student') {
        $own = $pdo->prepare("
            SELECT 1
            FROM progress_tests_v2
            WHERE id=? AND user_id=?
            LIMIT 1
        ");
        $own->execute(array($testId, $userId));
        if (!$own->fetchColumn()) {
            json_fail(403, 'Forbidden');
        }
    }

    $spacesKey      = env_required('SPACES_KEY');
    $spacesSecret   = env_required('SPACES_SECRET');
    $spacesBucket   = env_required('SPACES_BUCKET');
    $spacesRegion   = env_required('SPACES_REGION');
    $spacesEndpoint = env_required('SPACES_ENDPOINT');
    $spacesCdn      = env_required('SPACES_CDN');

    $service    = 's3';
    $host       = $spacesBucket . '.' . $spacesRegion . '.digitaloceanspaces.com';
    $amzdate    = gmdate('Ymd\THis\Z');
    $datestamp  = gmdate('Ymd');
    $algorithm  = 'AWS4-HMAC-SHA256';
    $expiresSec = 900;
    $credential = $spacesKey . '/' . $datestamp . '/' . $spacesRegion . '/' . $service . '/aws4_request';

    $key = '';
    if ($kind === 'intro') {
        $ext = clean_ext($ext, 'mp3');
        $key = 'progress_tests_v2/' . $testId . '/intro.' . $ext;

    } elseif ($kind === 'result') {
        $ext = clean_ext($ext, 'mp3');
        $key = 'progress_tests_v2/' . $testId . '/result.' . $ext;

    } elseif ($kind === 'question') {
        $ext = clean_ext($ext, 'mp3');
        if ($itemId <= 0) json_fail(400, 'Missing item_id for question');

        $chk = $pdo->prepare("
            SELECT 1
            FROM progress_test_items_v2
            WHERE id=? AND test_id=?
            LIMIT 1
        ");
        $chk->execute(array($itemId, $testId));
        if (!$chk->fetchColumn()) json_fail(404, 'Question item not found');

        $key = 'progress_tests_v2/' . $testId . '/q_' . $itemId . '.' . $ext;

    } elseif ($kind === 'answer') {
        $ext = clean_ext($ext, 'webm');
        if ($idx <= 0) json_fail(400, 'Missing idx for answer');

        $chk = $pdo->prepare("
            SELECT id
            FROM progress_test_items_v2
            WHERE test_id=? AND idx=?
            LIMIT 1
        ");
        $chk->execute(array($testId, $idx));
        $itemRowId = (int)($chk->fetchColumn() ?: 0);
        if ($itemRowId <= 0) json_fail(404, 'Question item not found');

        $key = 'progress_tests_v2/' . $testId . '/answers/q' . str_pad((string)$idx, 2, '0', STR_PAD_LEFT) . '.' . $ext;

    } else {
        json_fail(400, 'Invalid kind');
    }

    $canonicalUri = '/' . str_replace('%2F', '/', rawurlencode($key));

    $query = array(
        'X-Amz-Algorithm'     => $algorithm,
        'X-Amz-Credential'    => $credential,
        'X-Amz-Date'          => $amzdate,
        'X-Amz-Expires'       => (string)$expiresSec,
        'X-Amz-SignedHeaders' => 'host;x-amz-acl'
    );

    $canonicalQuery   = rfc3986_query($query);
    $canonicalHeaders = "host:" . $host . "\n" . "x-amz-acl:public-read\n";
    $signedHeaders    = 'host;x-amz-acl';
    $payloadHash      = 'UNSIGNED-PAYLOAD';

    $canonicalRequest = implode("\n", array(
        'PUT',
        $canonicalUri,
        $canonicalQuery,
        $canonicalHeaders,
        $signedHeaders,
        $payloadHash
    ));

    $toSign = implode("\n", array(
        $algorithm,
        $amzdate,
        $datestamp . '/' . $spacesRegion . '/s3/aws4_request',
        hash('sha256', $canonicalRequest)
    ));

    $signKey   = sigv4_key($spacesSecret, $datestamp, $spacesRegion, $service);
    $signature = hash_hmac('sha256', $toSign, $signKey);

    if (!$signature) {
        json_fail(500, 'Failed to generate signature');
    }

    $presignedUrl = 'https://' . $host . $canonicalUri . '?' . $canonicalQuery . '&X-Amz-Signature=' . $signature;
    $publicUrl    = build_spaces_public_url($spacesCdn, $key);
    $originUrl    = build_spaces_public_url($spacesEndpoint, $key);

    json_out(array(
        'ok'         => true,
        'method'     => 'PUT',
        'kind'       => $kind,
        'test_id'    => $testId,
        'item_id'    => $itemId,
        'idx'        => $idx,
        'key'        => $key,
        'url'        => $presignedUrl,
        'public_url' => $publicUrl,
        'origin_url' => $originUrl,
        'expires'    => $expiresSec,
        'headers'    => array(
            'x-amz-acl' => 'public-read'
        )
    ));

} catch (Throwable $e) {
    json_fail(500, $e->getMessage());
}