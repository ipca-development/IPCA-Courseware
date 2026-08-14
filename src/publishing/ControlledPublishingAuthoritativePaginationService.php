<?php
declare(strict_types=1);

require_once __DIR__ . '/ControlledPublishingReaderService.php';

/**
 * Runs the one authoritative, browser-measured pagination engine.
 *
 * The worker consumes canonical MODE_READ HTML and the exact publication CSS.
 * There is deliberately no heuristic fallback: an unavailable or invalid
 * browser engine must block generation rather than create a competing page map.
 */
final class ControlledPublishingAuthoritativePaginationService
{
    public const ENGINE_VERSION = 'authoritative-browser-pagination-v1';

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
            if (!is_array($result) || !is_array($result['pages'] ?? null)) {
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
            $this->root
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
