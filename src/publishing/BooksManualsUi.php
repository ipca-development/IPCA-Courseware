<?php
declare(strict_types=1);

require_once __DIR__ . '/../compliance/ComplianceUi.php';

function books_manuals_page_open(array $options): void
{
    $cssVersion = @filemtime(dirname(__DIR__, 2) . '/public/assets/books-manuals.css') ?: time();
    echo '<link rel="stylesheet" href="/assets/books-manuals.css?v=' . (int)$cssVersion . '">';
    $options['overline'] = (string)($options['overline'] ?? 'Books & Manuals');
    compliance_page_open($options);
}

function books_manuals_phase_pill(string $label, string $tone): string
{
    return '<span class="cmp-pill bm-phase bm-phase--'
        . htmlspecialchars($tone, ENT_QUOTES, 'UTF-8')
        . '">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>';
}

function books_manuals_approval_label(string $route, ?string $authority): string
{
    if ($route !== 'authority') {
        return 'Internal approval';
    }
    $authority = trim((string)$authority);
    return $authority !== '' ? $authority . ' approval' : 'Authority approval';
}

function books_manuals_update_label(?string $code): string
{
    $code = trim((string)$code);
    return $code !== '' ? $code : 'Pending workflow setup';
}
