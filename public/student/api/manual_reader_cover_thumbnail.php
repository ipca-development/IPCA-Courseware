<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/publishing/ControlledPublishingReaderService.php';
require_once __DIR__ . '/../../../src/publishing/ControlledPublishingReaderAccessService.php';
require_once __DIR__ . '/../../../src/publishing/BooksManualsReaderPolicyService.php';

function reader_cover_fail(int $status, string $message): never
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    echo $message;
    exit;
}

try {
    $user = cw_current_user($pdo);
    $access = new ControlledPublishingReaderAccessService();
    if (!is_array($user)
        || (int)($user['id'] ?? 0) <= 0
        || !$access->canReadManuals($user)) {
        reader_cover_fail(401, 'Login required.');
    }

    $bookKey = strtoupper(trim((string)($_GET['book'] ?? '')));
    $versionId = (int)($_GET['version_id'] ?? 0);
    if (!preg_match('/^[A-Z0-9][A-Z0-9_-]{1,95}$/', $bookKey) || $versionId <= 0) {
        reader_cover_fail(400, 'Invalid cover request.');
    }

    $canPreview = $access->canPreviewDraftManuals($user);
    $reader = new ControlledPublishingReaderService($pdo);
    $version = $reader->resolveReaderVersion($bookKey, $versionId, $canPreview);
    $policy = new BooksManualsReaderPolicyService($pdo, $access);
    if (!$policy->canReadVersion($version, $user)) {
        reader_cover_fail(403, 'Manual access required.');
    }
    $map = $reader->loadReaderPageMap($version, $canPreview);
    $firstPageNumber = (int)($map['pages'][0]['page_number'] ?? 1);
    $page = $reader->loadReaderPage($version, $firstPageNumber, $canPreview);
    $pageHTML = (string)($page['page_html'] ?? '');
    if ($pageHTML === '') {
        reader_cover_fail(404, 'Cover page is unavailable.');
    }

    $mapHash = preg_replace('/[^a-f0-9]/i', '', (string)($map['page_map_hash'] ?? ''));
    if ($mapHash === '') {
        $mapHash = hash('sha256', $pageHTML);
    }
    $cacheDir = dirname(__DIR__, 3) . '/storage/manual_reader_cover_thumbnails';
    if (!is_dir($cacheDir) && !mkdir($cacheDir, 0775, true) && !is_dir($cacheDir)) {
        throw new RuntimeException('Unable to create cover thumbnail cache.');
    }
    $outputPath = $cacheDir . '/v' . $versionId . '-' . substr($mapHash, 0, 24) . '.png';

    if (!is_file($outputPath) || filesize($outputPath) === 0) {
        $layout = is_array($map['layout'] ?? null) ? $map['layout'] : array();
        $width = (float)($layout['page_width_px'] ?? $layout['page_width'] ?? 794);
        $height = (float)($layout['page_height_px'] ?? $layout['page_height'] ?? 1123);
        $css = '';
        foreach (array(
            dirname(__DIR__, 2) . '/assets/controlled_book_editor.css',
            dirname(__DIR__, 2) . '/assets/manual_reader_content.css',
        ) as $cssPath) {
            $value = is_file($cssPath) ? file_get_contents($cssPath) : false;
            if (is_string($value)) {
                $css .= "\n" . $value;
            }
        }

        $inputPath = tempnam(sys_get_temp_dir(), 'ipca-cover-');
        if ($inputPath === false) {
            throw new RuntimeException('Unable to prepare cover thumbnail.');
        }
        file_put_contents($inputPath, json_encode(array(
            'base_url' => 'https://ipca.training/',
            'width' => $width,
            'height' => $height,
            'css' => $css,
            'pages' => array($pageHTML),
        ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $script = dirname(__DIR__, 3) . '/scripts/render_annex_pdf.cjs';
        $command = 'timeout 45s node ' . escapeshellarg($script) . ' '
            . escapeshellarg($inputPath) . ' ' . escapeshellarg($outputPath) . ' 2>&1';
        exec($command, $output, $exitCode);
        @unlink($inputPath);
        if ($exitCode !== 0 || !is_file($outputPath) || filesize($outputPath) === 0) {
            @unlink($outputPath);
            throw new RuntimeException('Cover rendering failed: ' . implode("\n", $output));
        }
    }

    $etag = '"' . substr($mapHash, 0, 32) . '"';
    header('ETag: ' . $etag);
    header('Cache-Control: private, max-age=0, must-revalidate');
    if (trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
        http_response_code(304);
        exit;
    }
    header('Content-Type: image/png');
    header('Content-Length: ' . (string)filesize($outputPath));
    readfile($outputPath);
} catch (Throwable $e) {
    reader_cover_fail(400, 'Cover thumbnail failed: ' . $e->getMessage());
}
