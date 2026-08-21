<?php
declare(strict_types=1);

require_once __DIR__ . '/TheoryContentStudioService.php';

final class TheoryStudioUi
{
    public static function extraCss(): array
    {
        return array(
            '/assets/compliance.css',
            '/assets/theory-studio.css',
        );
    }

    public static function liveBannerHtml(): string
    {
        return '<div class="ts-banner ts-banner--live" role="status">'
            . htmlspecialchars(TheoryContentStudioService::LIVE_BANNER, ENT_QUOTES, 'UTF-8')
            . '</div>';
    }

    /**
     * @param list<array{label:string,href:?string}> $crumbs
     */
    public static function breadcrumb(array $crumbs): string
    {
        $parts = array();
        $last = count($crumbs) - 1;
        foreach ($crumbs as $i => $crumb) {
            $label = htmlspecialchars((string)($crumb['label'] ?? ''), ENT_QUOTES, 'UTF-8');
            $href = (string)($crumb['href'] ?? '');
            if ($href !== '' && $i !== $last) {
                $parts[] = '<a class="ts-crumb" href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '">' . $label . '</a>';
            } else {
                $parts[] = '<span class="ts-crumb is-current">' . $label . '</span>';
            }
        }
        return '<nav class="ts-breadcrumbs" aria-label="Breadcrumb">' . implode('<span class="ts-crumb-sep">→</span>', $parts) . '</nav>';
    }

    /**
     * @param array<string,mixed> $program
     */
    public static function statusPills(array $program): string
    {
        $html = '';
        $status = strtolower((string)($program['status'] ?? ''));
        if ($status === 'live') {
            $html .= '<span class="cmp-pill cmp-pill-ok">Live</span>';
        } elseif ($status === 'draft') {
            $html .= '<span class="cmp-pill cmp-pill-info">Draft</span>';
        } else {
            $html .= '<span class="cmp-pill cmp-pill-muted">' . htmlspecialchars($status !== '' ? ucfirst($status) : 'Unknown', ENT_QUOTES, 'UTF-8') . '</span>';
        }
        if (!empty($program['in_use']) || (!empty($program['protected']) && $status === 'live')) {
            $html .= '<span class="cmp-pill cmp-pill-warn">Currently in use</span>';
        }
        if (!empty($program['protected'])) {
            $html .= '<span class="cmp-pill cmp-pill-muted">Protected</span>';
        }
        return '<span class="ts-pills">' . $html . '</span>';
    }

    public static function chipClass(string $tone): string
    {
        return match ($tone) {
            'ok' => 'cmp-pill cmp-pill-ok',
            'warn' => 'cmp-pill cmp-pill-warn',
            'bad' => 'cmp-pill cmp-pill-crit',
            default => 'cmp-pill cmp-pill-muted',
        };
    }
}
