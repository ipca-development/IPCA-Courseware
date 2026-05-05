<?php
declare(strict_types=1);

/**
 * One-shot FAA AIM HTML bootstrap: discover pages under the edition prefix, extract
 * h4.paragraph-title anchors, upsert resource_library_aim_paragraphs, write a JSON manifest.
 *
 * CLI wrapper: scripts/aim_bootstrap_import.php
 */

require_once __DIR__ . '/resource_library_catalog.php';
require_once __DIR__ . '/resource_library_aim.php';

final class AimBootstrapImporter
{
    private const USER_AGENT = 'IPCA-Courseware/AIM-Bootstrap-Importer/1.0';

    public function __construct(
        private readonly PDO $pdo,
        private readonly int $editionId,
        private readonly string $allowedPrefix,
        private readonly string $indexUrl,
        private readonly bool $dryRun,
        private readonly bool $replace,
        private readonly bool $syncEditionMeta,
        private readonly string $snapshotDir,
        private readonly int $maxPages,
        private readonly int $sleepMsBetweenRequests,
    ) {
    }

    /**
     * @return array{ok: bool, error?: string, pages?: int, paragraphs?: int, manifest_path?: string, run_id?: int}
     */
    public function run(): array
    {
        if (!rl_aim_tables_present($this->pdo)) {
            return ['ok' => false, 'error' => 'AIM tables missing; apply scripts/sql/resource_library_aim_crawl.sql'];
        }
        if ($this->editionId <= 0) {
            return ['ok' => false, 'error' => 'Invalid edition id'];
        }

        $prefix = self::normalizePrefix($this->allowedPrefix);
        if ($prefix === '' || !preg_match('#^https://#i', $prefix)) {
            return ['ok' => false, 'error' => 'allowed_url_prefix must be an https:// URL'];
        }

        if (!is_dir($this->snapshotDir) && !@mkdir($this->snapshotDir, 0755, true)) {
            return ['ok' => false, 'error' => 'Could not create snapshot directory: ' . $this->snapshotDir];
        }

        $indexHtml = self::httpGet($this->indexUrl);
        if ($indexHtml['code'] < 200 || $indexHtml['code'] >= 400 || $indexHtml['body'] === '') {
            return ['ok' => false, 'error' => 'Index fetch failed HTTP ' . $indexHtml['code']];
        }

        $meta = self::parseIndexMeta($indexHtml['body']);
        $effective = $meta['effective_date'];
        $changeLabel = $meta['change'];

        $pageBodies = $this->discoverPageBodies($this->indexUrl, $prefix, $indexHtml['body']);
        $pages = array_keys($pageBodies);

        $allParagraphs = [];
        foreach ($pageBodies as $pageUrl => $html) {
            $extracted = self::extractParagraphsFromPage($html, $pageUrl);
            foreach ($extracted as $row) {
                $allParagraphs[] = $row;
            }
        }

        $runId = 0;
        if (!$this->dryRun) {
            $runId = $this->startRun();
        }

        try {
            if (!$this->dryRun && $this->replace) {
                $this->deleteEditionParagraphs();
            }
            if (!$this->dryRun) {
                $this->upsertParagraphs($allParagraphs, $effective, $changeLabel);
            }
            if (!$this->dryRun && $this->syncEditionMeta && ($effective !== null || $changeLabel !== null)) {
                $this->updateEditionMeta($effective, $changeLabel);
            }
        } catch (Throwable $e) {
            if ($runId > 0) {
                $this->finishRun($runId, 'failed', count($pages), count($allParagraphs), $e->getMessage());
            }

            return ['ok' => false, 'error' => $e->getMessage()];
        }

        $manifestRows = array_map(static fn (array $p): array => [
            'stable_key' => (string) $p['stable_key'],
            'content_hash' => (string) $p['content_hash'],
        ], $allParagraphs);
        $manifestHash = self::manifestContentHash($manifestRows);
        $generatedAt = gmdate('Y-m-d\TH:i:s\Z');
        $manifest = [
            'source' => 'FAA AIM HTML',
            'index_url' => $this->indexUrl,
            'edition_id' => $this->editionId,
            'effective_date' => $effective,
            'change' => $changeLabel,
            'generated_at' => $generatedAt,
            'page_count' => count($pages),
            'paragraph_count' => count($allParagraphs),
            'content_hash' => $manifestHash,
        ];

        $safeTs = gmdate('Ymd\THis\Z');
        $manifestPath = rtrim($this->snapshotDir, '/') . '/aim_bootstrap_' . $this->editionId . '_' . $safeTs . '.json';
        $json = json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false) {
            if ($runId > 0) {
                $this->finishRun($runId, 'partial', count($pages), count($allParagraphs), 'JSON encode failed');
            }

            return ['ok' => false, 'error' => 'Could not encode manifest JSON'];
        }
        if (file_put_contents($manifestPath, $json . "\n") === false) {
            if ($runId > 0) {
                $this->finishRun($runId, 'partial', count($pages), count($allParagraphs), 'Manifest write failed');
            }

            return ['ok' => false, 'error' => 'Could not write manifest: ' . $manifestPath];
        }

        if ($runId > 0) {
            $this->finishRun($runId, 'success', count($pages), count($allParagraphs), null, $manifestPath, $manifestHash);
        }

        return [
            'ok' => true,
            'pages' => count($pages),
            'paragraphs' => count($allParagraphs),
            'manifest_path' => $manifestPath,
            'run_id' => $runId,
        ];
    }

    public static function normalizePrefix(string $p): string
    {
        $p = trim($p);

        return rtrim($p, '/') . '/';
    }

    /**
     * @return array{code: int, body: string, final_url: string}
     */
    public static function httpGet(string $url): array
    {
        if (!function_exists('curl_init')) {
            return ['code' => 0, 'body' => '', 'final_url' => ''];
        }
        $ch = curl_init($url);
        if ($ch === false) {
            return ['code' => 0, 'body' => '', 'final_url' => ''];
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 12,
            CURLOPT_TIMEOUT => 90,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml;q=0.9,*/*;q=0.8'],
            CURLOPT_USERAGENT => self::USER_AGENT,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $final = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        // No explicit close: curl handles are cleaned up automatically and curl_close()
        // is deprecated in PHP 8.5 because it has been a no-op since PHP 8.0.

        return [
            'code' => $code,
            'body' => is_string($body) ? $body : '',
            'final_url' => $final !== '' ? $final : $url,
        ];
    }

    /**
     * @return array{effective_date: ?string, change: ?string}
     */
    public static function parseIndexMeta(string $html): array
    {
        $out = ['effective_date' => null, 'change' => null];
        if (preg_match('/Effective:\s*([0-9]{1,2}\/[0-9]{1,2}\/[0-9]{2,4})/i', $html, $m)) {
            $out['effective_date'] = self::parseUsDateToYmd($m[1]);
        }
        if (preg_match('/Change:\s*([^\n<]+)/i', $html, $m)) {
            $out['change'] = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        return $out;
    }

    public static function parseUsDateToYmd(string $mdy): ?string
    {
        $mdy = trim($mdy);
        if (!preg_match('#^(\d{1,2})/(\d{1,2})/(\d{2,4})$#', $mdy, $m)) {
            return null;
        }
        $mo = (int) $m[1];
        $dy = (int) $m[2];
        $yr = (int) $m[3];
        if ($yr < 100) {
            $yr += ($yr >= 70 ? 1900 : 2000);
        }
        if ($mo < 1 || $mo > 12 || $dy < 1 || $dy > 31) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $yr, $mo, $dy);
    }

    /**
     * BFS crawl under allowed prefix; returns each page body once (no duplicate fetches for extraction).
     *
     * @return array<string, string> normalized URL => HTML
     */
    private function discoverPageBodies(string $indexUrl, string $prefixNorm, string $indexBody): array
    {
        $prefixNorm = self::normalizePrefix($prefixNorm);
        $norm = static function (string $u): string {
            $u = trim($u);
            $u = preg_replace('#^http://#i', 'https://', $u) ?? $u;

            return strtok($u, '#') ?: $u;
        };

        $normIndex = $norm($indexUrl);
        $visited = [];
        $queued = [];
        $queue = [$normIndex];
        $queued[$normIndex] = true;
        $bodies = [];

        while ($queue !== []) {
            if (count($visited) >= $this->maxPages) {
                break;
            }
            $u = $norm(array_shift($queue));
            if ($u === '' || isset($visited[$u])) {
                continue;
            }
            if (!self::urlAllowedUnderPrefix($u, $prefixNorm)) {
                continue;
            }

            if ($u === $normIndex) {
                $body = $indexBody;
            } else {
                if ($this->sleepMsBetweenRequests > 0) {
                    usleep($this->sleepMsBetweenRequests * 1000);
                }
                $r = self::httpGet($u);
                $body = ($r['code'] >= 200 && $r['code'] < 400) ? $r['body'] : '';
            }
            if ($body === '') {
                continue;
            }

            $visited[$u] = true;
            $bodies[$u] = $body;

            $baseForLinks = $u;
            self::collectLinksFromHtml($body, $baseForLinks, static function (string $abs) use (&$queue, &$queued, $norm, $prefixNorm): void {
                $v = $norm($abs);
                if ($v === '' || !self::urlAllowedUnderPrefix($v, $prefixNorm)) {
                    return;
                }
                if (isset($queued[$v])) {
                    return;
                }
                $queued[$v] = true;
                $queue[] = $v;
            });
        }

        return $bodies;
    }

    private static function urlAllowedUnderPrefix(string $abs, string $prefixNorm): bool
    {
        $abs = preg_replace('#^http://#i', 'https://', trim($abs)) ?? trim($abs);
        if (!str_starts_with($abs, 'https://')) {
            return false;
        }
        if (str_contains($abs, '/assets/')) {
            return false;
        }
        $pref = rtrim($prefixNorm, '/');
        if (strlen($abs) < strlen($pref) || strncasecmp($abs, $pref, strlen($pref)) !== 0) {
            return false;
        }
        $path = (string) (parse_url($abs, PHP_URL_PATH) ?? '');

        return $path !== '' && str_ends_with(strtolower($path), '.html');
    }

    /**
     * @param callable(string): void $enqueue
     */
    public static function collectLinksFromHtml(string $html, string $baseUrl, callable $enqueue): void
    {
        $dom = new DOMDocument();
        @$dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $xp = new DOMXPath($dom);
        foreach ($xp->query('//a[@href]') ?: [] as $a) {
            if (!$a instanceof DOMElement) {
                continue;
            }
            $href = trim($a->getAttribute('href'));
            if ($href === '' || str_starts_with($href, 'javascript:')) {
                continue;
            }
            $abs = self::resolveUrl($baseUrl, $href);
            if ($abs !== null) {
                $enqueue($abs);
            }
        }
    }

    public static function resolveUrl(string $base, string $href): ?string
    {
        $href = trim($href);
        if ($href === '' || str_starts_with($href, '#')) {
            return null;
        }
        if (preg_match('#^https?://#i', $href)) {
            return (string) (preg_replace('#^http://#i', 'https://', $href) ?? $href);
        }
        $baseParts = parse_url($base);
        if (!is_array($baseParts) || empty($baseParts['scheme']) || empty($baseParts['host'])) {
            return null;
        }
        $scheme = $baseParts['scheme'];
        $host = $baseParts['host'];
        $port = isset($baseParts['port']) ? ':' . $baseParts['port'] : '';
        $path = $baseParts['path'] ?? '/';
        if (!str_ends_with($path, '/')) {
            $path = dirname($path);
            if ($path === '\\' || $path === '.') {
                $path = '/';
            }
        }
        if ($href[0] === '/') {
            return $scheme . '://' . $host . $port . $href;
        }

        return $scheme . '://' . $host . $port . rtrim($path, '/') . '/' . $href;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function extractParagraphsFromPage(string $html, string $pageUrl): array
    {
        $dom = new DOMDocument();
        @$dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $xp = new DOMXPath($dom);
        $main = $xp->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' main-content ')]")->item(0);
        if (!$main instanceof DOMElement) {
            return [];
        }

        $pageTitle = '';
        $tit = $dom->getElementsByTagName('title')->item(0);
        if ($tit) {
            $pageTitle = trim(html_entity_decode($tit->textContent, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        $basePage = strtok($pageUrl, '#') ?: $pageUrl;
        $rows = [];
        /** @var DOMNodeList<DOMElement> $heads */
        $heads = $xp->query('.//h4[contains(concat(" ", normalize-space(@class), " "), " paragraph-title ")]', $main);
        foreach ($heads as $h4) {
            if (!$h4 instanceof DOMElement) {
                continue;
            }
            $fragment = trim($h4->getAttribute('id'));
            $titleText = trim(html_entity_decode($h4->textContent, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($titleText === '') {
                continue;
            }
            $stableKey = $fragment !== '' ? 'p:' . $fragment : 'p:u:' . hash('sha256', $basePage . "\n" . $titleText);

            [$chapter, $section, $paraNum] = self::splitAimNumbers($fragment);

            [$bodyText, $bodyHtml] = self::collectFollowingUntilNextParagraph($h4);

            $norm = self::normalizeBodyText($bodyText);
            $hash = hash('sha256', $norm);

            $canonical = $fragment !== '' ? $basePage . '#' . $fragment : $basePage;

            $rows[] = [
                'stable_key' => $stableKey,
                'chapter_number' => $chapter,
                'section_number' => $section,
                'paragraph_number' => $paraNum,
                'display_title' => $titleText,
                'page_title' => $pageTitle,
                'source_url' => $basePage,
                'canonical_url' => $canonical,
                'fragment' => $fragment !== '' ? $fragment : null,
                'content_hash' => $hash,
                'body_text' => $norm,
                'body_html' => $bodyHtml,
            ];
        }

        return $rows;
    }

    /**
     * @return array{0: ?string, 1: ?string, 2: ?string}
     */
    public static function splitAimNumbers(string $fragment): array
    {
        if (preg_match('/^(\d+)-(\d+)-(\d+)$/', $fragment, $m)) {
            $ch = $m[1];
            $sec = $m[1] . '-' . $m[2];
            $p = $m[0];

            return [$ch, $sec, $p];
        }

        return [null, null, null];
    }

    /**
     * @return array{0: string, 1: string}
     */
    public static function collectFollowingUntilNextParagraph(DOMElement $h4): array
    {
        $htmlParts = [];
        $n = $h4->nextSibling;
        while ($n !== null) {
            if ($n instanceof DOMElement && strtolower($n->tagName) === 'h4') {
                $cls = ' ' . strtolower($n->getAttribute('class')) . ' ';
                if (str_contains($cls, ' paragraph-title ')) {
                    break;
                }
            }
            if ($n instanceof DOMText) {
                $t = trim($n->textContent);
                if ($t !== '') {
                    $htmlParts[] = '<p>' . htmlspecialchars($t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
                }
            } elseif ($n instanceof DOMElement) {
                $htmlParts[] = $n->ownerDocument?->saveHTML($n) ?? '';
            }
            $n = $n->nextSibling;
        }

        $bodyHtml = trim(implode("\n", $htmlParts));
        if (strlen($bodyHtml) > 500000) {
            $bodyHtml = substr($bodyHtml, 0, 500000) . "\n<!-- truncated -->";
        }
        $withBreaks = preg_replace('#<br\s*/?>#i', "\n", $bodyHtml) ?? $bodyHtml;
        $plain = strip_tags($withBreaks);
        $bodyText = self::normalizeBodyText(html_entity_decode($plain, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return [$bodyText, $bodyHtml];
    }

    public static function normalizeBodyText(string $s): string
    {
        $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $s = preg_replace('/[ \t]+/u', ' ', $s) ?? $s;
        $s = preg_replace("/\n{3,}/u", "\n\n", $s) ?? $s;

        return trim($s);
    }

    private static function clip(string $s, int $maxBytes): string
    {
        if (strlen($s) <= $maxBytes) {
            return $s;
        }

        return substr($s, 0, $maxBytes);
    }

    /**
     * @param list<array{stable_key: string, content_hash: string}> $rows
     */
    public static function manifestContentHash(array $rows): string
    {
        usort($rows, static fn (array $a, array $b): int => strcmp($a['stable_key'], $b['stable_key']));
        $buf = '';
        foreach ($rows as $r) {
            $buf .= $r['stable_key'] . "\t" . $r['content_hash'] . "\n";
        }

        return hash('sha256', $buf);
    }

    private function startRun(): int
    {
        $col = rl_aim_runs_fk_column($this->pdo);
        $meta = json_encode([
            'importer' => 'aim_bootstrap_importer',
            'version' => 1,
            'index_url' => $this->indexUrl,
            'allowed_prefix' => $this->allowedPrefix,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $stmt = $this->pdo->prepare("
            INSERT INTO resource_library_crawler_runs ({$col}, run_status, pages_discovered, paragraphs_upserted, meta_json)
            VALUES (?, 'running', 0, 0, ?)
        ");
        $stmt->execute([$this->editionId, $meta]);

        return (int) $this->pdo->lastInsertId();
    }

    private function finishRun(int $runId, string $status, int $pages, int $paras, ?string $err, ?string $manifestPath = null, ?string $manifestHash = null): void
    {
        $sql = '
            UPDATE resource_library_crawler_runs
            SET completed_at = CURRENT_TIMESTAMP,
                run_status = ?,
                pages_discovered = ?,
                paragraphs_upserted = ?,
                error_message = ?
            WHERE id = ?
        ';
        $this->pdo->prepare($sql)->execute([
            $status,
            $pages,
            $paras,
            $err,
            $runId,
        ]);
        if ($manifestPath !== null || $manifestHash !== null) {
            $st = $this->pdo->prepare('SELECT meta_json FROM resource_library_crawler_runs WHERE id = ?');
            $st->execute([$runId]);
            $raw = $st->fetchColumn();
            $meta = [];
            if (is_string($raw) && $raw !== '') {
                try {
                    $d = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                    $meta = is_array($d) ? $d : [];
                } catch (Throwable) {
                    $meta = [];
                }
            }
            if ($manifestPath !== null) {
                $meta['manifest_path'] = $manifestPath;
            }
            if ($manifestHash !== null) {
                $meta['manifest_content_hash'] = $manifestHash;
            }
            $enc = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($enc !== false) {
                $this->pdo->prepare('UPDATE resource_library_crawler_runs SET meta_json = ? WHERE id = ?')->execute([$enc, $runId]);
            }
        }
    }

    private function deleteEditionParagraphs(): void
    {
        $fk = rl_aim_paragraphs_fk_column($this->pdo);
        $this->pdo->prepare("DELETE FROM resource_library_aim_paragraphs WHERE {$fk} = ?")->execute([$this->editionId]);
    }

    /**
     * @param list<array<string, mixed>> $paragraphs
     */
    private function upsertParagraphs(array $paragraphs, ?string $effectiveDate, ?string $changeNumber): void
    {
        $fk = rl_aim_paragraphs_fk_column($this->pdo);
        $sql = "
            INSERT INTO resource_library_aim_paragraphs (
                {$fk}, parent_id, node_type, chapter_number, section_number, paragraph_number,
                display_title, page_title, source_url, canonical_url, fragment,
                effective_date, change_number, crawled_at, content_hash, body_text, body_html,
                citation_status, stable_key
            ) VALUES (
                ?, NULL, 'paragraph', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, ?, ?, ?, 'active', ?
            )
            ON DUPLICATE KEY UPDATE
                display_title = VALUES(display_title),
                page_title = VALUES(page_title),
                source_url = VALUES(source_url),
                canonical_url = VALUES(canonical_url),
                fragment = VALUES(fragment),
                effective_date = VALUES(effective_date),
                change_number = VALUES(change_number),
                crawled_at = VALUES(crawled_at),
                content_hash = VALUES(content_hash),
                body_text = VALUES(body_text),
                body_html = VALUES(body_html),
                citation_status = 'active'
        ";
        $stmt = $this->pdo->prepare($sql);
        $effDb = $effectiveDate !== null ? self::clip($effectiveDate, 10) : null;
        $chgDb = $changeNumber !== null ? self::clip($changeNumber, 64) : null;
        foreach ($paragraphs as $p) {
            $stmt->execute([
                $this->editionId,
                $p['chapter_number'] !== null ? self::clip((string) $p['chapter_number'], 32) : null,
                $p['section_number'] !== null ? self::clip((string) $p['section_number'], 32) : null,
                $p['paragraph_number'] !== null ? self::clip((string) $p['paragraph_number'], 64) : null,
                self::clip((string) $p['display_title'], 512),
                self::clip((string) $p['page_title'], 512),
                self::clip((string) $p['source_url'], 2048),
                self::clip((string) $p['canonical_url'], 2048),
                $p['fragment'] !== null ? self::clip((string) $p['fragment'], 256) : null,
                $effDb,
                $chgDb,
                $p['content_hash'],
                (string) $p['body_text'],
                (string) $p['body_html'],
                self::clip((string) $p['stable_key'], 192),
            ]);
        }
    }

    private function updateEditionMeta(?string $effective, ?string $change): void
    {
        if (!rl_catalog_has_resource_type_column($this->pdo)) {
            return;
        }
        $row = rl_catalog_fetch_edition($this->pdo, $this->editionId);
        if (!is_array($row)) {
            return;
        }
        $title = (string) ($row['title'] ?? 'FAA Aeronautical Information Manual (AIM)');
        $existingCode = trim((string) ($row['revision_code'] ?? ''));
        $revCode = ($change !== null && $change !== '') ? $change : ($existingCode !== '' ? $existingCode : 'AIM');
        $stmt = $this->pdo->prepare('
            UPDATE resource_library_editions
            SET title = ?, revision_code = ?, revision_date = COALESCE(?, revision_date)
            WHERE id = ? AND resource_type = ?
        ');
        $stmt->execute([
            $title,
            $revCode,
            $effective,
            $this->editionId,
            RL_RESOURCE_CRAWLER,
        ]);
    }
}
