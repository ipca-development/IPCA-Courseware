<?php
declare(strict_types=1);

/**
 * Enriches manual body text with annex links and external URLs; detects callout prefixes.
 */
final class ControlledPublishingRichTextService
{
    /** @var array<int, array<int, int>> */
    private array $annexMapCache = array();

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return array{callout_type:string,title:string,text:string}|null
     */
    public static function parseLeadingCallout(string $text): ?array
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }

        if (preg_match('/^Note(?:\s+(\d+))?\s*:\s*(.*)$/uis', $text, $matches) === 1) {
            $number = trim((string)($matches[1] ?? ''));
            $body = trim((string)($matches[2] ?? ''));
            return array(
                'callout_type' => 'note',
                'title' => $number !== '' ? 'NOTE ' . $number : 'NOTE',
                'text' => $body,
            );
        }

        if (preg_match('/^WARNING\s*:\s*(.*)$/uis', $text, $matches) === 1) {
            return array(
                'callout_type' => 'warning',
                'title' => 'WARNING',
                'text' => trim((string)($matches[1] ?? '')),
            );
        }

        if (preg_match('/^CAUTION\s*:\s*(.*)$/uis', $text, $matches) === 1) {
            return array(
                'callout_type' => 'caution',
                'title' => 'CAUTION',
                'text' => trim((string)($matches[1] ?? '')),
            );
        }

        if (preg_match('/^INFO\s*:\s*(.*)$/uis', $text, $matches) === 1) {
            return array(
                'callout_type' => 'info',
                'title' => 'INFO',
                'text' => trim((string)($matches[1] ?? '')),
            );
        }

        return null;
    }

    public function bodyParagraphHtml(string $text, int $versionId): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }

        return '<p>' . $this->enrichInlineHtml($text, $versionId) . '</p>';
    }

    public function calloutTextHtml(string $text, int $versionId): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }

        return $this->enrichInlineHtml($text, $versionId);
    }

    public function enrichInlineHtml(string $text, int $versionId): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }

        if (str_contains($text, '<')) {
            return ControlledPublishingHtmlSanitizer::sanitizeInline($text);
        }

        $annexMap = $this->resolveAnnexSectionMap($versionId);
        $pattern = '/('
            . '\b(?:OM|OMM)\s+Annex\s+\d{1,3}(?:\s*[–\-—]\s*[^\.,;\n]+)?'
            . '|\bAnnex\s+\d{1,3}(?:\s*[–\-—]\s*[^\.,;\n]+)?'
            . '|(?:https?:\/\/|www\.)[^\s<>"\'\]]+'
            . ')/iu';

        $parts = preg_split($pattern, $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        if (!is_array($parts) || $parts === array()) {
            return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        $html = '';
        foreach ($parts as $part) {
            $part = (string)$part;
            if ($part === '') {
                continue;
            }
            if (self::looksLikeUrl($part)) {
                $html .= $this->externalLinkHtml($part);
                continue;
            }
            $annexNumber = self::extractAnnexNumber($part);
            if ($annexNumber > 0 && isset($annexMap[$annexNumber])) {
                $html .= $this->annexLinkHtml($part, $annexMap[$annexNumber], $versionId);
                continue;
            }
            $html .= htmlspecialchars($part, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        return $html;
    }

    /**
     * @return array<int, int> annex number => section id
     */
    public function resolveAnnexSectionMap(int $versionId): array
    {
        if (isset($this->annexMapCache[$versionId])) {
            return $this->annexMapCache[$versionId];
        }

        $stmt = $this->pdo->prepare("
            SELECT id
            FROM ipca_publishing_book_sections
            WHERE book_version_id = :version_id
              AND section_key = 'annexes'
            LIMIT 1
        ");
        $stmt->execute(array(':version_id' => $versionId));
        $annexParentId = (int)$stmt->fetchColumn();
        if ($annexParentId <= 0) {
            $this->annexMapCache[$versionId] = array();
            return array();
        }

        $childStmt = $this->pdo->prepare("
            SELECT id, title, sort_order
            FROM ipca_publishing_book_sections
            WHERE book_version_id = :version_id
              AND parent_section_id = :parent_id
            ORDER BY sort_order, id
        ");
        $childStmt->execute(array(
            ':version_id' => $versionId,
            ':parent_id' => $annexParentId,
        ));

        $map = array();
        $index = 1;
        foreach ($childStmt->fetchAll(PDO::FETCH_ASSOC) ?: array() as $row) {
            $sectionId = (int)($row['id'] ?? 0);
            $title = trim((string)($row['title'] ?? ''));
            if ($sectionId <= 0) {
                continue;
            }
            $parsed = self::extractAnnexNumber($title);
            if ($parsed > 0) {
                $map[$parsed] = $sectionId;
            } else {
                $map[$index] = $sectionId;
            }
            $index++;
        }

        $this->annexMapCache[$versionId] = $map;
        return $map;
    }

    private static function extractAnnexNumber(string $text): int
    {
        if (preg_match('/\bAnnex\s+(\d{1,3})\b/i', $text, $matches) !== 1) {
            return 0;
        }

        return (int)$matches[1];
    }

    private static function looksLikeUrl(string $text): bool
    {
        return preg_match('/^(?:https?:\/\/|www\.)\S+$/i', trim($text)) === 1;
    }

    private function externalLinkHtml(string $url): string
    {
        $url = trim($url);
        $href = $url;
        if (preg_match('/^www\./i', $href) === 1) {
            $href = 'https://' . $href;
        }
        if (!preg_match('/^https?:\/\//i', $href)) {
            return htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        return '<a href="' . htmlspecialchars($href, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '" class="cpb-external-link" target="_blank" rel="noopener noreferrer">'
            . htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '</a>';
    }

    private function annexLinkHtml(string $label, int $sectionId, int $versionId): string
    {
        $href = '/admin/compliance/controlled_book_editor.php?version_id=' . $versionId
            . '&section_id=' . $sectionId;

        return '<a href="' . htmlspecialchars($href, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '" class="cpb-annex-link" data-section-id="' . $sectionId . '">'
            . htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '</a>';
    }
}
