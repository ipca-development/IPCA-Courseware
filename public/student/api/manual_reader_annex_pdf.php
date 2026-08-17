<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/publishing/ControlledPublishingReaderService.php';
require_once __DIR__ . '/../../../src/publishing/ControlledPublishingReaderAccessService.php';

function annex_pdf_fail(int $status, string $message): never
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    echo $message;
    exit;
}

function annex_pdf_filename(string $title): string
{
    $safe = preg_replace('/[^A-Za-z0-9._-]+/', '-', trim($title)) ?: 'Annex';
    return trim($safe, '-.') . '.pdf';
}

try {
    $user = cw_current_user($pdo);
    if (!is_array($user) || (int)($user['id'] ?? 0) <= 0) {
        annex_pdf_fail(401, 'Login required.');
    }

    $access = new ControlledPublishingReaderAccessService();
    if (!$access->canReadManuals($user)) {
        annex_pdf_fail(403, 'Manual access required.');
    }

    $bookKey = strtoupper(trim((string)($_GET['book'] ?? '')));
    $versionId = (int)($_GET['version_id'] ?? 0);
    $sectionId = (int)($_GET['section_id'] ?? 0);
    if (!preg_match('/^[A-Z0-9][A-Z0-9_-]{1,95}$/', $bookKey)
        || $versionId <= 0
        || $sectionId <= 0) {
        annex_pdf_fail(400, 'Invalid annex PDF request.');
    }

    $canPreview = $access->canPreviewDraftManuals($user);
    $reader = new ControlledPublishingReaderService($pdo);
    $version = $reader->resolveReaderVersion($bookKey, $versionId, $canPreview);
    if ((int)($version['id'] ?? 0) !== $versionId) {
        annex_pdf_fail(404, 'Manual version not found.');
    }

    $sectionStmt = $pdo->prepare(
        'SELECT id, title, section_key
         FROM ipca_publishing_book_sections
         WHERE id = ? AND book_version_id = ? LIMIT 1'
    );
    $sectionStmt->execute(array($sectionId, $versionId));
    $section = $sectionStmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($section)
        || !str_starts_with((string)($section['section_key'] ?? ''), 'annexes_annex_')) {
        annex_pdf_fail(404, 'Annex not found.');
    }

    $toc = $reader->loadReaderTocWithPages($version, $canPreview);
    $sectionPages = is_array($toc['section_page_index'] ?? null)
        ? $toc['section_page_index']
        : array();
    $startPage = (int)($sectionPages[(string)$sectionId] ?? $sectionPages[$sectionId] ?? 0);
    if ($startPage <= 0) {
        annex_pdf_fail(409, 'The authoritative page map does not contain this annex.');
    }

    $map = $reader->loadReaderPageMap($version, $canPreview);
    $pageCount = (int)($map['page_count'] ?? count((array)($map['pages'] ?? array())));
    $nextStart = $pageCount + 1;
    foreach ($sectionPages as $candidate) {
        $pageNumber = (int)$candidate;
        if ($pageNumber > $startPage && $pageNumber < $nextStart) {
            $nextStart = $pageNumber;
        }
    }

    $pages = array();
    for ($pageNumber = $startPage; $pageNumber < $nextStart; $pageNumber++) {
        $page = $reader->loadReaderPage($version, $pageNumber, $canPreview);
        $html = (string)($page['page_html'] ?? '');
        if ($html !== '') {
            $pages[] = $html;
        }
    }
    if ($pages === array()) {
        annex_pdf_fail(409, 'No authoritative annex pages are available.');
    }

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

    $tempBase = tempnam(sys_get_temp_dir(), 'ipca-annex-');
    if ($tempBase === false) {
        throw new RuntimeException('Unable to create annex export files.');
    }
    $inputPath = $tempBase . '.json';
    $outputPath = $tempBase . '.pdf';
    @unlink($tempBase);
    file_put_contents($inputPath, json_encode(array(
        'base_url' => 'https://ipca.training/',
        'width' => $width,
        'height' => $height,
        'css' => $css,
        'pages' => $pages,
    ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

    $script = dirname(__DIR__, 3) . '/scripts/render_annex_pdf.cjs';
    $command = 'timeout 60s node ' . escapeshellarg($script) . ' '
        . escapeshellarg($inputPath) . ' ' . escapeshellarg($outputPath) . ' 2>&1';
    exec($command, $output, $exitCode);
    @unlink($inputPath);
    if ($exitCode !== 0 || !is_file($outputPath) || filesize($outputPath) === 0) {
        @unlink($outputPath);
        throw new RuntimeException('Annex PDF generation failed: ' . implode("\n", $output));
    }

    $bytes = file_get_contents($outputPath);
    @unlink($outputPath);
    if (!is_string($bytes) || $bytes === '') {
        throw new RuntimeException('Annex PDF generation returned no data.');
    }

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . annex_pdf_filename((string)$section['title']) . '"');
    header('Content-Length: ' . strlen($bytes));
    header('Cache-Control: private, max-age=0, must-revalidate');
    echo $bytes;
} catch (Throwable $e) {
    annex_pdf_fail(400, 'Annex PDF export failed: ' . $e->getMessage());
}
