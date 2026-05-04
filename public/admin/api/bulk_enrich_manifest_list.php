<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/bulk_enrich_video_manifest.php';

cw_require_admin();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

echo json_encode([
    'ok' => true,
    'files' => bec_list_video_manifest_candidates(),
    'default' => 'kings_videos_manifest.json',
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
