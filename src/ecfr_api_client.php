<?php
declare(strict_types=1);

/**
 * Minimal client for the public eCFR Versioner API (v1).
 *
 * @see https://www.ecfr.gov/developers/documentation/api/v1
 * @see https://www.ecfr.gov/reader-aids/ecfr-developer-resources/rest-api-interactive-documentation (interactive endpoint reference)
 *
 * No API key is required. Use a descriptive User-Agent per eCFR guidance.
 * Programmatic access may be rate-limited; follow official API documentation and site terms.
 */
final class EcfrApiClient
{
    /** Official eCFR REST API (v1) documentation — canonical reference for endpoints this client uses. */
    public const DOCUMENTATION_V1_URL = 'https://www.ecfr.gov/developers/documentation/api/v1';

    private const DEFAULT_BASE_ORIGIN = 'https://www.ecfr.gov';

    private const DEFAULT_UA = 'IPCA-Courseware/1.0 (+' . self::DOCUMENTATION_V1_URL . ')';

    /** @var array<string,mixed>|null */
    private ?array $titlesCache = null;

    private readonly string $baseOrigin;

    /**
     * @param string $apiBaseUrl Full HTTPS URL or origin only (e.g. https://www.ecfr.gov or https://www.ecfr.gov/). Path segments are ignored; only scheme, host, and port are used.
     */
    public function __construct(
        string $apiBaseUrl = self::DEFAULT_BASE_ORIGIN,
        private readonly string $userAgent = self::DEFAULT_UA
    ) {
        $this->baseOrigin = self::normalizeOrigin($apiBaseUrl);
    }

    public function getBaseOrigin(): string
    {
        return $this->baseOrigin;
    }

    /**
     * Reduce a pasted URL to scheme + host (+ port) for eCFR versioner paths rooted at /api/versioner/v1/.
     */
    public static function normalizeOrigin(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return self::DEFAULT_BASE_ORIGIN;
        }
        $p = parse_url($url);
        if (!is_array($p) || empty($p['scheme']) || empty($p['host'])) {
            return self::DEFAULT_BASE_ORIGIN;
        }
        $scheme = strtolower((string) $p['scheme']);
        if ($scheme !== 'https' && $scheme !== 'http') {
            return self::DEFAULT_BASE_ORIGIN;
        }
        if ($scheme === 'http') {
            $scheme = 'https';
        }
        $host = (string) $p['host'];
        $origin = $scheme . '://' . $host;
        if (!empty($p['port'])) {
            $origin .= ':' . (int) $p['port'];
        }

        return $origin;
    }

    /**
     * @return array{titles: list<array<string,mixed>>, meta?: array<string,mixed>}
     */
    public function fetchTitles(): array
    {
        if ($this->titlesCache !== null) {
            return $this->titlesCache;
        }

        $url = $this->baseOrigin . '/api/versioner/v1/titles';
        $raw = $this->httpGet($url);
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !isset($decoded['titles']) || !is_array($decoded['titles'])) {
            throw new RuntimeException('eCFR titles response was not valid JSON');
        }

        $this->titlesCache = $decoded;

        return $decoded;
    }

    /**
     * Latest “up to date as of” date string (YYYY-MM-DD) for a CFR title number.
     */
    public function resolveTitleSnapshotDate(int $titleNumber): string
    {
        $data = $this->fetchTitles();
        foreach ($data['titles'] as $t) {
            if (!is_array($t)) {
                continue;
            }
            if ((int)($t['number'] ?? 0) === $titleNumber) {
                $d = trim((string)($t['up_to_date_as_of'] ?? ''));
                if ($d !== '') {
                    return $d;
                }
            }
        }

        $meta = isset($data['meta']) && is_array($data['meta']) ? $data['meta'] : [];
        $fallback = trim((string)($meta['date'] ?? ''));
        if ($fallback !== '') {
            return $fallback;
        }

        return gmdate('Y-m-d');
    }

    /**
     * Fetch a single section as processed XML (small payload when ?section= is used).
     *
     * @param string $sectionId Section identifier as used by eCFR, e.g. "61.105" for 14 CFR 61.105.
     */
    public function fetchSectionXml(int $titleNumber, string $sectionId, ?string $snapshotDate = null): string
    {
        $sectionId = trim($sectionId);
        if ($sectionId === '') {
            throw new RuntimeException('eCFR section id is empty');
        }
        if ($titleNumber <= 0) {
            throw new RuntimeException('Invalid CFR title number');
        }

        $date = $snapshotDate !== null && trim($snapshotDate) !== ''
            ? trim($snapshotDate)
            : $this->resolveTitleSnapshotDate($titleNumber);

        $url = sprintf(
            '%s/api/versioner/v1/full/%s/title-%d.xml?section=%s',
            $this->baseOrigin,
            rawurlencode($date),
            $titleNumber,
            rawurlencode($sectionId)
        );

        return $this->httpGet($url);
    }

    /**
     * Human browse URL for a section (uses /current/ resolver).
     */
    public function sectionBrowseUrl(int $titleNumber, string $sectionId): string
    {
        return sprintf(
            '%s/current/title-%d/section-%s',
            $this->baseOrigin,
            $titleNumber,
            rawurlencode(trim($sectionId))
        );
    }

    /**
     * Convert eCFR processed section XML (DIV8 …) to simple HTML safe for mPDF.
     */
    public function sectionXmlToHtml(string $xml): string
    {
        $xml = trim($xml);
        if ($xml === '') {
            return '';
        }

        $dom = new DOMDocument();
        if (!@$dom->loadXML($xml)) {
            return '';
        }

        $root = $dom->documentElement;
        if (!$root || strtolower($root->nodeName) !== 'div8') {
            return '';
        }

        $out = [];
        foreach ($root->childNodes as $child) {
            if ($child->nodeType !== XML_ELEMENT_NODE) {
                continue;
            }
            $name = strtoupper($child->nodeName);
            if ($name === 'HEAD') {
                $out[] = '<h3 class="ecfr-head">' . $this->escapeHtml($child->textContent) . '</h3>';
                continue;
            }
            if ($name === 'P') {
                $out[] = '<p class="ecfr-p">' . $this->innerHtmlFromNode($dom, $child) . '</p>';
                continue;
            }
            if ($name === 'CITA') {
                $out[] = '<p class="ecfr-cita"><em>' . $this->escapeHtml(trim($child->textContent)) . '</em></p>';
            }
        }

        return implode('', $out);
    }

    private function innerHtmlFromNode(DOMDocument $dom, DOMElement $el): string
    {
        $html = '';
        foreach ($el->childNodes as $n) {
            if ($n->nodeType === XML_TEXT_NODE) {
                $html .= $this->escapeHtml($n->textContent);
                continue;
            }
            if ($n->nodeType === XML_ELEMENT_NODE && $n instanceof DOMElement) {
                $tag = strtoupper($n->nodeName);
                if ($tag === 'I' || $tag === 'E') {
                    $html .= '<em>' . $this->escapeHtml($n->textContent) . '</em>';
                    continue;
                }
                $html .= $this->escapeHtml($n->textContent);
            }
        }

        return $html;
    }

    private function escapeHtml(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function httpGet(string $url): string
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: application/xml, text/xml;q=0.9, */*;q=0.8',
        ]);
        curl_setopt($ch, CURLOPT_USERAGENT, $this->userAgent);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 25);

        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);

        if ($body === false) {
            throw new RuntimeException('eCFR HTTP request failed: ' . $err);
        }
        if ($code < 200 || $code >= 300) {
            throw new RuntimeException('eCFR HTTP ' . $code . ' for ' . $url);
        }

        return (string)$body;
    }
}
