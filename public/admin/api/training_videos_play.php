<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/communication/CommunicationKernel.php';

cw_require_admin();

function training_videos_play_fail(int $status, string $message): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(array('ok' => false, 'error' => $message), JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Same-origin Range proxy. The browser never sees a Spaces URL.
 *
 * @param list<string> $requestHeaders
 */
function training_videos_proxy_origin(string $url, string $mimeType, array $requestHeaders): void
{
    @set_time_limit(0);
    ignore_user_abort(true);
    $sent = false;
    $headerLines = array();
    $ch = curl_init($url);
    if ($ch === false) {
        training_videos_play_fail(502, 'That video could not be opened.');
    }
    curl_setopt_array($ch, array(
        CURLOPT_HTTPGET => true,
        CURLOPT_HTTPHEADER => $requestHeaders,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HEADERFUNCTION => static function ($ch, string $header) use (&$headerLines): int {
            $headerLines[] = $header;
            return strlen($header);
        },
        CURLOPT_WRITEFUNCTION => static function ($ch, string $data) use (&$sent, &$headerLines, $mimeType): int {
            if (!$sent) {
                $status = 200;
                foreach ($headerLines as $line) {
                    if (preg_match('#^HTTP/\S+\s+(\d+)#', $line, $match) === 1) {
                        $status = (int)$match[1];
                    }
                }
                if ($status !== 200 && $status !== 206) {
                    return 0;
                }
                http_response_code($status);
                header('Content-Type: ' . $mimeType);
                header('Accept-Ranges: bytes');
                header('Cache-Control: no-store');
                header('X-Content-Type-Options: nosniff');
                foreach ($headerLines as $line) {
                    $trimmed = trim($line);
                    if (stripos($trimmed, 'Content-Range:') === 0
                        || stripos($trimmed, 'Content-Length:') === 0
                    ) {
                        header($trimmed);
                    }
                }
                $sent = true;
            }
            echo $data;
            return strlen($data);
        },
        CURLOPT_TIMEOUT => 0,
        CURLOPT_CONNECTTIMEOUT => 30,
    ));
    $ok = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($ok === false || ($code !== 200 && $code !== 206)) {
        if (!$sent) {
            training_videos_play_fail($code >= 400 ? $code : 502, 'That video could not be opened.');
        }
    }
    exit;
}

try {
    $kernel = new CommunicationKernel($pdo);
    $origin = $kernel->trainingVideos->adminVideoOrigin((string)($_GET['video_uuid'] ?? ''));
    $headers = array('Accept: */*');
    $range = trim((string)($_SERVER['HTTP_RANGE'] ?? ''));
    if ($range !== '') {
        $headers[] = 'Range: ' . $range;
    }
    training_videos_proxy_origin((string)$origin['url'], (string)$origin['mime_type'], $headers);
} catch (CommunicationException $e) {
    training_videos_play_fail($e->httpStatus, $e->getMessage());
} catch (Throwable $e) {
    training_videos_play_fail(500, 'That video could not be opened.');
}
