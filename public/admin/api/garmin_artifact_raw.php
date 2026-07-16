<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';

cw_require_admin();

$trackArtifactId = (int)($_GET['track_artifact_id'] ?? 0);
if ($trackArtifactId <= 0) {
    http_response_code(400);
    echo 'Missing track artifact id.';
    exit;
}

$stmt = $pdo->prepare('SELECT storage_path FROM ipca_garmin_normalized_track_artifacts WHERE id = ? LIMIT 1');
$stmt->execute(array($trackArtifactId));
$path = trim((string)$stmt->fetchColumn());
if ($path === '') {
    http_response_code(404);
    echo 'Artifact not found.';
    exit;
}

$candidates = str_starts_with($path, '/')
    ? array($path)
    : array(dirname(__DIR__, 3) . '/' . ltrim($path, '/'), dirname(__DIR__, 3) . '/storage/cvr/' . ltrim($path, '/'));

$file = '';
foreach ($candidates as $candidate) {
    if (is_file($candidate)) {
        $file = $candidate;
        break;
    }
}
if ($file === '') {
    http_response_code(404);
    echo 'Stored artifact file is missing.';
    exit;
}

header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: inline; filename="garmin-track-' . $trackArtifactId . '.json"');
readfile($file);
