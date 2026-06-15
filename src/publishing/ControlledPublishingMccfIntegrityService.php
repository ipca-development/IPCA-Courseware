<?php
declare(strict_types=1);

/**
 * Deterministic integrity score for MCCF requirement coverage (manual + regulation).
 */
final class ControlledPublishingMccfIntegrityService
{
    /**
     * @param array<string,mixed> $requirement
     * @param list<array<string,mixed>> $linkedExcerpts
     * @param list<array<string,mixed>> $regulationLinks
     * @return array{score:int,label:string,tone:string,breakdown:array<string,int>,reasons:list<string>}
     */
    public function scoreRequirement(array $requirement, array $linkedExcerpts, array $regulationLinks): array
    {
        $applicable = strtoupper(trim((string)($requirement['applicable'] ?? '')));
        $isApplicable = ($applicable === '' || $applicable === 'YES' || $applicable === 'Y');
        $manualRef = trim((string)($requirement['manual_section_ref'] ?? ''));
        $unlinkable = $manualRef === ''
            || strcasecmp($manualRef, 'No procedure') === 0
            || stripos($manualRef, 'Headers Parts') === 0;

        $breakdown = array(
            'manual_link' => 0,
            'manual_content' => 0,
            'regulation_ref' => 0,
            'regulation_resolved' => 0,
        );

        if ($unlinkable && !$isApplicable) {
            return $this->pack(85, 'N/A — not applicable', 'muted', $breakdown, $requirement, $linkedExcerpts, $regulationLinks);
        }

        if ($unlinkable && $isApplicable) {
            return $this->pack(25, 'Header / scope item', 'warn', $breakdown, $requirement, $linkedExcerpts, $regulationLinks);
        }

        if ($linkedExcerpts !== array()) {
            $breakdown['manual_link'] = 35;
            $bestLen = 0;
            foreach ($linkedExcerpts as $excerpt) {
                $len = strlen(trim((string)($excerpt['body_text'] ?? $excerpt['excerpt_preview'] ?? '')));
                $bestLen = max($bestLen, $len);
            }
            if ($bestLen >= 400) {
                $breakdown['manual_content'] = 25;
            } elseif ($bestLen >= 80) {
                $breakdown['manual_content'] = 15;
            } elseif ($bestLen > 0) {
                $breakdown['manual_content'] = 8;
            }
        }

        $regRef = trim((string)($requirement['regulation_ref'] ?? ''));
        if ($regRef !== '') {
            $breakdown['regulation_ref'] = 15;
            $resolved = 0;
            foreach ($regulationLinks as $link) {
                if (($link['match_confidence'] ?? '') !== 'UNRESOLVED'
                    && trim((string)($link['target_node_uid'] ?? '')) !== '') {
                    $resolved++;
                }
            }
            if ($resolved > 0) {
                $breakdown['regulation_resolved'] = min(25, 10 + ($resolved * 5));
            }
        }

        $score = min(100, array_sum($breakdown));
        if (!$isApplicable) {
            $score = max($score, 70);
        }

        if ($score >= 80) {
            return $this->pack($score, 'Strong coverage', 'ok', $breakdown, $requirement, $linkedExcerpts, $regulationLinks);
        }
        if ($score >= 55) {
            return $this->pack($score, 'Partial coverage', 'warn', $breakdown, $requirement, $linkedExcerpts, $regulationLinks);
        }

        return $this->pack($score, 'Gap — review required', 'bad', $breakdown, $requirement, $linkedExcerpts, $regulationLinks);
    }

    public static function barClass(string $tone): string
    {
        return match ($tone) {
            'ok' => 'mccf-bar-fill--ok',
            'warn' => 'mccf-bar-fill--warn',
            'bad' => 'mccf-bar-fill--bad',
            default => 'mccf-bar-fill--muted',
        };
    }

    /**
     * @param array<string,int> $breakdown
     * @param array<string,mixed> $requirement
     * @param list<array<string,mixed>> $linkedExcerpts
     * @param list<array<string,mixed>> $regulationLinks
     * @return array{score:int,label:string,tone:string,breakdown:array<string,int>,reasons:list<string>}
     */
    private function pack(
        int $score,
        string $label,
        string $tone,
        array $breakdown,
        array $requirement,
        array $linkedExcerpts,
        array $regulationLinks
    ): array {
        return array(
            'score' => max(0, min(100, $score)),
            'label' => $label,
            'tone' => $tone,
            'breakdown' => $breakdown,
            'reasons' => $this->buildReasons($breakdown, $requirement, $linkedExcerpts, $regulationLinks, $score),
        );
    }

    /**
     * @param array<string,int> $breakdown
     * @param array<string,mixed> $requirement
     * @param list<array<string,mixed>> $linkedExcerpts
     * @param list<array<string,mixed>> $regulationLinks
     * @return list<string>
     */
    private function buildReasons(
        array $breakdown,
        array $requirement,
        array $linkedExcerpts,
        array $regulationLinks,
        int $score
    ): array {
        $reasons = array();
        $applicable = strtoupper(trim((string)($requirement['applicable'] ?? '')));
        $isApplicable = ($applicable === '' || $applicable === 'YES' || $applicable === 'Y');
        $manualRef = trim((string)($requirement['manual_section_ref'] ?? ''));
        $unlinkable = $manualRef === ''
            || strcasecmp($manualRef, 'No procedure') === 0
            || stripos($manualRef, 'Headers Parts') === 0;

        if (!$isApplicable) {
            $reasons[] = 'Marked not applicable on the MCCF checklist.';
        }
        if ($unlinkable && $isApplicable) {
            $reasons[] = 'Scope/header row without a linkable OM section reference.';
        }
        if ($breakdown['manual_link'] === 0 && $isApplicable && !$unlinkable) {
            $reasons[] = 'No active link from this requirement to an OM excerpt.';
        } elseif ($breakdown['manual_link'] > 0 && $breakdown['manual_content'] <= 8) {
            $reasons[] = 'Linked OM excerpt exists but contains very little text.';
        } elseif ($breakdown['manual_link'] > 0 && $breakdown['manual_content'] === 15) {
            $reasons[] = 'Linked OM excerpt is present with moderate content depth.';
        } elseif ($breakdown['manual_link'] > 0 && $breakdown['manual_content'] >= 25) {
            $reasons[] = 'Linked OM excerpt contains substantial coverage text.';
        }
        if ($breakdown['regulation_ref'] === 0 && trim((string)($requirement['regulation_ref'] ?? '')) === '') {
            $reasons[] = 'No regulation reference recorded on this MCCF row.';
        } elseif ($breakdown['regulation_ref'] > 0 && $breakdown['regulation_resolved'] === 0) {
            $reasons[] = 'Regulation is cited but not matched to an EASA Resource Library node.';
        } elseif ($breakdown['regulation_resolved'] > 0) {
            $reasons[] = 'Regulation reference resolves to EASA Resource Library content.';
        }
        if ($linkedExcerpts === array() && $regulationLinks === array() && $isApplicable && !$unlinkable) {
            $reasons[] = 'Neither manual nor regulation sources are linked yet.';
        }
        if ($score >= 80 && count($reasons) <= 2) {
            $reasons[] = 'Overall coverage looks strong for audit review.';
        } elseif ($score < 55) {
            $reasons[] = 'Low score — manual and/or regulation coverage likely needs attention before audit.';
        }

        return array_values(array_unique($reasons));
    }
}
