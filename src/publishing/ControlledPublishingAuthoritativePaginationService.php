<?php
declare(strict_types=1);

require_once __DIR__ . '/ControlledPublishingReaderService.php';

/**
 * First-class pagination validation result. Must not persist a page map.
 */
final class ControlledPublishingPaginationValidationException extends RuntimeException
{
    /**
     * @param array<string,mixed> $payload
     */
    public function __construct(private array $payload)
    {
        parent::__construct((string)($payload['message'] ?? 'Pagination validation failed.'));
    }

    /**
     * @return array<string,mixed>
     */
    public function payload(): array
    {
        return $this->payload;
    }

    public function codeName(): string
    {
        return (string)($this->payload['code'] ?? '');
    }
}

/**
 * Runs the one authoritative, browser-measured pagination engine.
 *
 * The worker consumes canonical MODE_READ HTML and the exact publication CSS.
 * There is deliberately no heuristic fallback: an unavailable or invalid
 * browser engine must block generation rather than create a competing page map.
 */
final class ControlledPublishingAuthoritativePaginationService
{
    public const ENGINE_VERSION = 'live-authoritative-flow-v1';

    private string $root;

    public function __construct(
        private ControlledPublishingReaderService $reader,
        ?string $root = null
    ) {
        $this->root = $root ?? dirname(__DIR__, 2);
    }

    /**
     * @param array<string,mixed> $source
     * @param array<string,mixed> $version
     * @return array{pages:list<array<string,mixed>>,generation:array<string,mixed>}
     */
    public function generate(array $source, array $version): array
    {
        $generationLock = $this->acquireGenerationLock($version);
        try {
            return $this->generateLocked($source, $version);
        } finally {
            flock($generationLock, LOCK_UN);
            fclose($generationLock);
        }
    }

    /**
     * @param array<string,mixed> $source
     * @param array<string,mixed> $version
     * @return array{pages:list<array<string,mixed>>,generation:array<string,mixed>}
     */
    private function generateLocked(array $source, array $version): array
    {
        $package = $this->reader->paginationPublicationPackage($version, $source);
        $css = (string)($package['css']['content'] ?? '');
        if ($css === '') {
            throw new RuntimeException('Authoritative publication CSS is unavailable.');
        }

        $inputPath = tempnam(sys_get_temp_dir(), 'ipca-pagination-input-');
        $outputPath = tempnam(sys_get_temp_dir(), 'ipca-pagination-output-');
        if ($inputPath === false || $outputPath === false) {
            throw new RuntimeException('Unable to allocate pagination worker files.');
        }

        try {
            $input = array(
                'source' => $source,
                'book_style_css' => $css,
            );
            file_put_contents(
                $inputPath,
                json_encode($input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                LOCK_EX
            );
            $this->runWorker($inputPath, $outputPath);
            $raw = file_get_contents($outputPath);
            $result = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
            if (!is_array($result)) {
                throw new RuntimeException('Pagination worker returned an invalid response.');
            }
            $error = is_array($result['error'] ?? null) ? $result['error'] : null;
            if (is_array($error) && (string)($error['code'] ?? '') === 'MANUAL_BREAK_REQUIRED') {
                throw $this->manualBreakRequired($error, $source, $version);
            }
            if (!is_array($result['pages'] ?? null)) {
                throw new RuntimeException('Pagination worker returned an invalid response.');
            }
            $validation = is_array($result['validation'] ?? null) ? $result['validation'] : array();
            if (empty($validation['is_valid'])) {
                throw new RuntimeException('Authoritative page validation failed.');
            }

            $sectionIndex = is_array($result['section_page_index'] ?? null)
                ? $result['section_page_index']
                : array();
            $pages = array();
            foreach ($result['pages'] as $page) {
                if (!is_array($page)) {
                    continue;
                }
                $pageHtml = $this->injectTocPageNumbers(
                    (string)($page['page_html'] ?? ''),
                    $sectionIndex
                );
                $start = is_array($page['start_location'] ?? null) ? $page['start_location'] : array();
                $official = is_array($start['official_location'] ?? null)
                    ? $start['official_location']
                    : array();
                $pages[] = array(
                    'page_number' => (int)($page['page_number'] ?? 0),
                    'section_id' => isset($page['section_id']) ? (int)$page['section_id'] : null,
                    'stable_anchor' => (string)($official['stable_anchor'] ?? '') ?: null,
                    'page_type' => !empty($page['is_cover']) ? 'cover' : 'content',
                    'is_cover' => !empty($page['is_cover']),
                    'is_section_start' => !empty($page['is_section_start']),
                    'is_major_section_start' => !empty($page['is_major_section_start']),
                    'page_html' => $pageHtml,
                    'thumbnail_html' => null,
                    'metadata' => array(
                        'section_title' => (string)($page['section_title'] ?? ''),
                        'coverage' => $page['coverage'] ?? array(),
                        'metrics' => $page['metrics'] ?? array(),
                        'diagnostics' => $page['diagnostics'] ?? array(),
                    ),
                );
            }
            if ($pages === array()) {
                throw new RuntimeException('Authoritative paginator generated no pages.');
            }
            foreach ($pages as $index => $page) {
                if ((int)$page['page_number'] !== $index + 1) {
                    throw new RuntimeException('Authoritative page numbers are not sequential.');
                }
            }

            $sourceJson = $this->canonicalJson($source);
            $pageMapHash = hash(
                'sha256',
                implode("\n", array_map(
                    static fn(array $page): string => (string)$page['page_html'],
                    $pages
                ))
            );

            return array(
                'pages' => $pages,
                'generation' => array(
                    'engine_version' => self::ENGINE_VERSION,
                    'source_hash' => hash('sha256', $sourceJson),
                    'style_hash' => (string)($package['css']['hash'] ?? hash('sha256', $css)),
                    'manifest_hash' => (string)($package['manifest_hash'] ?? ''),
                    'layout_hash' => (string)($source['layout_hash'] ?? ''),
                    'manual_page_break_hash' => (string)($source['manual_page_breaks']['hash'] ?? ''),
                    'header_footer_hash' => hash(
                        'sha256',
                        (string)($source['header_template_html'] ?? '')
                        . "\n"
                        . (string)($source['footer_template_html'] ?? '')
                    ),
                    'page_map_hash' => $pageMapHash,
                    'page_count' => count($pages),
                    'validation' => $validation,
                    'generated_at' => gmdate('c'),
                ),
            );
        } finally {
            @unlink($inputPath);
            @unlink($outputPath);
        }
    }

    /**
     * A full manual uses one Chromium process and can briefly consume hundreds
     * of megabytes. Reject duplicate requests instead of letting abandoned
     * browser requests exhaust PHP, Chromium, and server memory.
     *
     * @param array<string,mixed> $version
     * @return resource
     */
    private function acquireGenerationLock(array $version)
    {
        $versionId = (int)($version['id'] ?? 0);
        $identity = $versionId > 0
            ? 'version-' . $versionId
            : 'book-' . hash('sha256', (string)($version['book_key'] ?? 'unknown'));
        $path = sys_get_temp_dir() . '/ipca-authoritative-pagination-' . $identity . '.lock';
        $handle = @fopen($path, 'c');
        if ($handle === false) {
            throw new RuntimeException('Unable to allocate the authoritative pagination lock.');
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            throw new RuntimeException(
                'Authoritative pagination is already running for this manual version. '
                . 'Wait for it to finish, then reload the stored preview.'
            );
        }

        return $handle;
    }

    /**
     * Cheap release-time identity check; does not run browser pagination.
     *
     * @param array<string,mixed> $source
     * @param array<string,mixed> $version
     * @return array<string,string>
     */
    public function fingerprint(array $source, array $version): array
    {
        $package = $this->reader->paginationPublicationPackage($version, $source);
        $css = (string)($package['css']['content'] ?? '');

        return array(
            'engine_version' => self::ENGINE_VERSION,
            'source_hash' => hash('sha256', $this->canonicalJson($source)),
            'style_hash' => (string)($package['css']['hash'] ?? hash('sha256', $css)),
            'manifest_hash' => (string)($package['manifest_hash'] ?? ''),
            'layout_hash' => (string)($source['layout_hash'] ?? ''),
            'manual_page_break_hash' => (string)($source['manual_page_breaks']['hash'] ?? ''),
            'header_footer_hash' => hash(
                'sha256',
                (string)($source['header_template_html'] ?? '')
                . "\n"
                . (string)($source['footer_template_html'] ?? '')
            ),
        );
    }

    private function runWorker(string $inputPath, string $outputPath): void
    {
        $node = trim((string)(getenv('CW_PAGINATION_NODE') ?: 'node'));
        $runner = $this->root . '/scripts/authoritative_manual_paginator.cjs';
        if (!is_file($runner)) {
            throw new RuntimeException('Authoritative pagination worker is missing.');
        }
        $pipes = array();
        $process = proc_open(
            array($node, $runner, '--input', $inputPath, '--output', $outputPath),
            array(
                0 => array('pipe', 'r'),
                1 => array('pipe', 'w'),
                2 => array('pipe', 'w'),
            ),
            $pipes,
            $this->root,
            $this->workerEnvironment()
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start authoritative pagination worker.');
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $stderr = '';
        $reportedExitCode = null;
        $deadline = microtime(true) + 300.0;
        do {
            $stdout .= stream_get_contents($pipes[1]) ?: '';
            $stderr .= stream_get_contents($pipes[2]) ?: '';
            $status = proc_get_status($process);
            if (!$status['running']) {
                $reportedExitCode = (int)$status['exitcode'];
                break;
            }
            if (microtime(true) >= $deadline) {
                proc_terminate($process, 9);
                throw new RuntimeException('Authoritative pagination timed out.');
            }
            usleep(50000);
        } while (true);
        $stdout .= stream_get_contents($pipes[1]) ?: '';
        $stderr .= stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $closedExitCode = proc_close($process);
        $exitCode = $closedExitCode >= 0 ? $closedExitCode : ($reportedExitCode ?? -1);
        if ($exitCode !== 0) {
            $detail = trim($stderr !== '' ? $stderr : $stdout);
            throw new RuntimeException(
                'Authoritative pagination failed'
                . ($detail !== '' ? ': ' . mb_substr($detail, 0, 2000) : '.')
            );
        }
    }

    /**
     * @return array<string,string>
     */
    private function workerEnvironment(): array
    {
        $env = getenv();
        if (!is_array($env) || $env === array()) {
            $env = array();
            foreach ($_SERVER as $key => $value) {
                if (is_string($key) && is_string($value) && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key) === 1) {
                    $env[$key] = $value;
                }
            }
        }
        $configured = trim((string)(getenv('CW_PAGINATION_PLAYWRIGHT_BROWSERS_PATH') ?: ''));
        $fallback = '/var/lib/ipca/pagination/playwright-browsers';
        $browsers = $configured !== '' ? $configured : (is_dir($fallback) ? $fallback : '');
        if ($browsers !== '') {
            $env['CW_PAGINATION_PLAYWRIGHT_BROWSERS_PATH'] = $browsers;
            $env['PLAYWRIGHT_BROWSERS_PATH'] = $browsers;
        }
        return $env;
    }

    /**
     * @param array<string,mixed> $error
     * @param array<string,mixed> $source
     * @param array<string,mixed> $version
     */
    private function manualBreakRequired(
        array $error,
        array $source,
        array $version
    ): ControlledPublishingPaginationValidationException {
        $versionId = (int)($source['version_id'] ?? $version['id'] ?? 0);
        $sectionId = (int)($error['section_id'] ?? 0);
        $title = trim((string)($error['before_block_title'] ?? ''));
        $message = trim((string)($error['message'] ?? ''));
        if ($message === '') {
            $message = 'Page content exceeds the available body area.';
            if ($title !== '') {
                $message .= ' A Manual Page Break is required before: “' . $title . '”';
            }
        }
        $editorUrl = '/admin/compliance/controlled_book_editor.php?version_id=' . $versionId;
        if ($sectionId > 0) {
            $editorUrl .= '&section_id=' . $sectionId;
        }

        return new ControlledPublishingPaginationValidationException(array(
            'code' => 'MANUAL_BREAK_REQUIRED',
            'message' => $message,
            'before_block_anchor' => (string)($error['before_block_anchor'] ?? ''),
            'before_block_title' => $title,
            'section_id' => $sectionId > 0 ? $sectionId : null,
            'editor_url' => $editorUrl,
        ));
    }

    /**
     * @param array<string,mixed> $sectionPageIndex
     */
    private function injectTocPageNumbers(string $html, array $sectionPageIndex): string
    {
        if (!str_contains($html, 'cpb-toc-row')) {
            return $html;
        }
        $updated = preg_replace_callback(
            '/<div class="cpb-toc-row[^"]*"[^>]*>.*?<\/div>/s',
            static function (array $match) use ($sectionPageIndex): string {
                $row = $match[0];
                $page = '—';
                if (preg_match('/data-section-id="(\d+)"/', $row, $sectionMatch) === 1) {
                    $resolved = $sectionPageIndex[(string)(int)$sectionMatch[1]]
                        ?? $sectionPageIndex[(int)$sectionMatch[1]]
                        ?? null;
                    if ($resolved !== null && (int)$resolved > 0) {
                        $page = (string)(int)$resolved;
                    }
                }
                $escaped = htmlspecialchars($page, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                return preg_replace(
                    '/(<span class="cpb-toc-page" data-toc-page=")[^"]*(">)[^<]*(<\/span>)/s',
                    '$1' . $escaped . '$2' . $escaped . '$3',
                    $row
                ) ?? $row;
            },
            $html
        );

        return is_string($updated) ? $updated : $html;
    }

    /**
     * @param array<string,mixed> $value
     */
    private function canonicalJson(array $value): string
    {
        $sort = function (mixed $item) use (&$sort): mixed {
            if (!is_array($item)) {
                return $item;
            }
            if (!array_is_list($item)) {
                ksort($item, SORT_STRING);
            }
            foreach ($item as $key => $child) {
                $item[$key] = $sort($child);
            }
            return $item;
        };

        return json_encode(
            $sort($value),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
    }
}
