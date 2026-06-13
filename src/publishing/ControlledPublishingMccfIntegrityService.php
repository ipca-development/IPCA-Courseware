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
     * @return array{score:int,label:string,tone:string,breakdown:array<string,int>}
     */
    public function scoreRequirement(array $requirement, array $linkedExcerpts, array $regulationLinks): array
    {
        $applicable = strtoupper(trim((string)($requirement['applicable'] ?? '')));
        $isApplicable = $applicable === '' || $applicable === 'YES';
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
            return $this->pack(85, 'N/A — not applicable', 'muted', $breakdown);
        }

        if ($unlinkable && $isApplicable) {
            return $this->pack(25, 'Header / scope item', 'warn', $breakdown);
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
            return $this->pack($score, 'Strong coverage', 'ok', $breakdown);
        }
        if ($score >= 55) {
            return $this->pack($score, 'Partial coverage', 'warn', $breakdown);
        }

        return $this->pack($score, 'Gap — review required', 'bad', $breakdown);
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
     * @return array{score:int,label:string,tone:string,breakdown:array<string,int>}
     */
    private function pack(int $score, string $label, string $tone, array $breakdown): array
    {
        return array(
            'score' => max(0, min(100, $score)),
            'label' => $label,
            'tone' => $tone,
            'breakdown' => $breakdown,
        );
    }
}
