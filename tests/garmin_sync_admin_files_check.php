<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$pagePath = $root . '/public/admin/garmin_sync_files.php';
$enrollmentPath = $root . '/public/admin/garmin_sync_enrollment.php';

if (!is_file($pagePath)) {
    fwrite(STDERR, "Missing Garmin Sync uploaded-files admin page.\n");
    exit(1);
}

$page = file_get_contents($pagePath);
$enrollment = file_get_contents($enrollmentPath);
if (!is_string($page) || !is_string($enrollment)) {
    fwrite(STDERR, "Could not read Garmin Sync admin sources.\n");
    exit(1);
}

$required = [
    'admin authentication' => 'cw_require_admin();',
    'organization scope' => 's.organization_id = ?',
    'isolated upload sessions' => 'ipca_garmin_sync_upload_sessions',
    'isolated archive files' => 'ipca_garmin_sync_archive_files',
    'isolated device names' => 'ipca_garmin_sync_devices',
    'verified unique-file count' => 'COUNT(DISTINCT archive_file_id)',
    'status filter allowlist' => "'receiving', 'verified', 'duplicate', 'error'",
    'bounded pagination' => '$perPage = 100;',
    'escaped output' => "h((string)\$row['original_filename'])",
    'read-only description' => 'Read-only view of uploader sessions',
];

foreach ($required as $label => $needle) {
    if (!str_contains($page, $needle)) {
        fwrite(STDERR, "Missing Garmin Sync files-page assertion: {$label}\n");
        exit(1);
    }
}

foreach (['INSERT INTO', 'UPDATE ipca_garmin_sync_', 'DELETE FROM ipca_garmin_sync_'] as $forbidden) {
    if (str_contains($page, $forbidden)) {
        fwrite(STDERR, "Garmin Sync files page must remain read-only: {$forbidden}\n");
        exit(1);
    }
}

if (!str_contains($enrollment, '/admin/garmin_sync_files.php')) {
    fwrite(STDERR, "Garmin Sync enrollment page does not link to uploaded files.\n");
    exit(1);
}

echo "Garmin Sync uploaded-files admin check passed.\n";
