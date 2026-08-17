<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/src/publishing/ControlledPublishingHtmlSanitizer.php';

$failures = array();
$assert = static function (bool $ok, string $message) use (&$failures): void {
    if (!$ok) {
        $failures[] = $message;
    }
};

$url = 'https://example.com/manual';
$single = ControlledPublishingHtmlSanitizer::sanitizeInline(
    '<p><a class="cpb-external-link" href="' . $url . '">' . $url . '</a>' . $url . '</p>'
);
$assert(
    substr_count($single, $url) === 2,
    'Adjacent plain URL must be removed while preserving one href and one visible label.'
);
$assert(substr_count($single, '<a ') === 1, 'Exactly one hyperlink must remain.');

$duplicateAnchors = ControlledPublishingHtmlSanitizer::sanitizeInline(
    '<p><a href="' . $url . '">' . $url . '</a><a href="' . $url . '">' . $url . '</a></p>'
);
$assert(substr_count($duplicateAnchors, '<a ') === 1, 'Adjacent identical anchors must collapse.');

$intentional = ControlledPublishingHtmlSanitizer::sanitizeInline(
    '<p><a href="' . $url . '">' . $url . '</a> and ' . $url . '</p>'
);
$assert(
    substr_count($intentional, $url) === 3,
    'Separated URL text must remain because it may be intentional prose.'
);

if ($failures !== array()) {
    fwrite(STDERR, "Controlled publishing hyperlink dedup: FAIL\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, " - {$failure}\n");
    }
    exit(1);
}

echo "Controlled publishing hyperlink dedup: PASS\n";
