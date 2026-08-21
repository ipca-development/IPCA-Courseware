<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/layout.php';
require_once __DIR__ . '/../../../src/theory_studio/TheoryContentStudioService.php';
require_once __DIR__ . '/../../../src/theory_studio/TheoryLessonEnhancementStatusService.php';
require_once __DIR__ . '/../../../src/theory_studio/TheoryStudioUi.php';
require_once __DIR__ . '/../../../src/theory_studio/TheoryStudioIsolation.php';

cw_require_admin();

function theory_studio_csrf_token(): string
{
    if (empty($_SESSION['theory_studio_csrf']) || !is_string($_SESSION['theory_studio_csrf'])) {
        $_SESSION['theory_studio_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['theory_studio_csrf'];
}

function theory_studio_emit_assets(): void
{
    $cssPath = __DIR__ . '/../../assets/theory-studio.css';
    $jsPath = __DIR__ . '/../../assets/theory-studio.js';
    $cssV = is_file($cssPath) ? (string)filemtime($cssPath) : '1';
    $jsV = is_file($jsPath) ? (string)filemtime($jsPath) : '1';
    echo '<link rel="stylesheet" href="/assets/theory-studio.css?v=' . htmlspecialchars($cssV, ENT_QUOTES, 'UTF-8') . '">';
    echo '<script src="/assets/theory-studio.js?v=' . htmlspecialchars($jsV, ENT_QUOTES, 'UTF-8') . '" defer></script>';
}

function theory_studio_cover_url(?string $path): string
{
    $path = trim((string)$path);
    if ($path === '') {
        return '';
    }
    $base = rtrim((string)(getenv('CW_CDN_BASE') ?: ''), '/');
    if ($base === '') {
        $base = 'https://ipca-media.nyc3.cdn.digitaloceanspaces.com';
    }
    return cdn_url($base, $path);
}
