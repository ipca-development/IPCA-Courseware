<?php
declare(strict_types=1);

/**
 * Parse BCAA MCCF manual_section_ref values, including multi-line
 * "Headers Parts …" scope lines followed by specific Part/Ch targets.
 */
final class ControlledPublishingMccfManualRefService
{
    /**
     * @return list<array{part:int,section:string}>
     */
    public static function parseSectionTargets(string $manualSectionRef): array
    {
        $manualSectionRef = trim($manualSectionRef);
        if ($manualSectionRef === '') {
            return array();
        }

        $refs = array();
        foreach (preg_split('/\R+/u', $manualSectionRef) ?: array() as $line) {
            $line = trim((string)$line);
            if ($line === '' || stripos($line, 'Headers Parts') === 0) {
                continue;
            }
            if (preg_match(
                '/Part\s*(\d+)\s*[–—-]\s*(?:Ch\.?\s*|Ch\s+)?([0-9]+(?:\.[0-9]+)*)/iu',
                $line,
                $m
            )) {
                $refs[] = array(
                    'part' => (int)$m[1],
                    'section' => self::normalizeSectionRef((string)$m[2]),
                );
            }
        }

        return $refs;
    }

    public static function hasSpecificSectionTarget(string $manualSectionRef): bool
    {
        return self::parseSectionTargets($manualSectionRef) !== array();
    }

    public static function isHeadersPartsScope(string $manualSectionRef): bool
    {
        return stripos(trim($manualSectionRef), 'Headers Parts') === 0;
    }

    /**
     * True when the row cannot be scored against live manual content.
     *
     * @param list<array<string,mixed>> $linkedSections
     */
    public static function isUnlinkableManualRef(string $manualSectionRef, array $linkedSections = array()): bool
    {
        $manualSectionRef = trim($manualSectionRef);
        if ($manualSectionRef === '') {
            return true;
        }
        if (strcasecmp($manualSectionRef, 'No procedure') === 0) {
            return true;
        }
        if ($linkedSections !== array()) {
            return false;
        }
        if (self::isHeadersPartsScope($manualSectionRef)) {
            return !self::hasSpecificSectionTarget($manualSectionRef);
        }

        return false;
    }

    /**
     * Section refs to load from the live book: explicit links first, then parsed MCCF lines.
     *
     * @param array<string,mixed> $requirement
     * @param list<array<string,mixed>> $linkedSections
     * @return list<string>
     */
    public static function collectSectionRefsForScoring(array $requirement, array $linkedSections): array
    {
        $refs = array();
        foreach ($linkedSections as $section) {
            $ref = self::normalizeSectionRef((string)($section['section_ref'] ?? ''));
            if ($ref !== '') {
                $refs[$ref] = true;
            }
        }
        if ($refs !== array()) {
            return array_keys($refs);
        }

        foreach (self::parseSectionTargets((string)($requirement['manual_section_ref'] ?? '')) as $target) {
            $ref = self::normalizeSectionRef((string)$target['section']);
            if ($ref !== '') {
                $refs[$ref] = true;
            }
        }

        return array_keys($refs);
    }

    public static function normalizeSectionRef(string $sectionRef): string
    {
        $sectionRef = trim($sectionRef);
        $sectionRef = preg_replace('/\s+/u', '', $sectionRef) ?? $sectionRef;

        return rtrim($sectionRef, '.');
    }
}
