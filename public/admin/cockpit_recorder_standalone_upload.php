<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
require_once __DIR__ . '/../../src/CockpitRecorderService.php';
require_once __DIR__ . '/../../src/StandaloneG3XReplayBuilder.php';

cw_require_admin();

@set_time_limit(0);
@ini_set('memory_limit', '768M');

$error = '';
$result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (empty($_FILES['g3x_csv']) || !is_array($_FILES['g3x_csv'])) {
            throw new RuntimeException('Choose a Garmin G3X CSV file first.');
        }
        $file = $_FILES['g3x_csv'];
        $err = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Upload failed (code ' . $err . ').');
        }
        $tmp = (string)($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new RuntimeException('Invalid upload.');
        }
        $originalName = (string)($file['name'] ?? 'g3x.csv');
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if ($ext !== 'csv') {
            throw new RuntimeException('Only .csv files are supported.');
        }

        $projectRoot = CockpitRecorderService::projectRoot();
        $uploadDir = $projectRoot . '/storage/cockpit_recorder/standalone_g3x';
        $payloadDir = $projectRoot . '/storage/tmp';
        foreach (array($uploadDir, $payloadDir) as $dir) {
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new RuntimeException('Could not create storage directory.');
            }
            if (!is_writable($dir)) {
                throw new RuntimeException('Storage directory is not writable.');
            }
        }

        $base = preg_replace('/[^A-Za-z0-9._-]+/', '-', pathinfo($originalName, PATHINFO_FILENAME));
        $base = trim((string)$base, '.-_');
        if ($base === '') {
            $base = 'g3x';
        }
        $key = 'standalone_g3x_' . gmdate('Ymd_His') . '_' . substr(sha1($originalName . '|' . random_bytes(8)), 0, 10);
        $csvPath = $uploadDir . '/' . $key . '_' . $base . '.csv';
        if (!move_uploaded_file($tmp, $csvPath)) {
            throw new RuntimeException('Could not store uploaded CSV.');
        }

        $payload = (new StandaloneG3XReplayBuilder())->build($csvPath);
        $payloadPath = $payloadDir . '/' . $key . '.json';
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new RuntimeException('Could not encode replay payload.');
        }
        if (file_put_contents($payloadPath, $json . PHP_EOL) === false) {
            throw new RuntimeException('Could not store replay payload.');
        }

        header('Location: /admin/cockpit_recorder_replay.php?standalone=' . urlencode($key));
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

cw_header('Standalone G3X Replay');
?>
<style>
.standalone-card { max-width: 880px; margin: 24px auto; background: #fff; border: 1px solid #e2e8f0; border-radius: 14px; padding: 22px; box-shadow: 0 10px 30px rgba(15,23,42,.08); }
.standalone-card h1 { margin: 0 0 8px; font-size: 24px; }
.standalone-card p { color: #475569; line-height: 1.55; }
.standalone-error { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; border-radius: 10px; padding: 12px; margin: 14px 0; }
.standalone-form { display: grid; gap: 14px; margin-top: 18px; }
.standalone-form label { display: grid; gap: 7px; font-weight: 700; color: #0f172a; }
.standalone-form input[type=file] { border: 1px dashed #94a3b8; border-radius: 10px; padding: 14px; background: #f8fafc; }
.standalone-actions { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
.standalone-button { border: 0; border-radius: 10px; background: #1d4ed8; color: #fff; font-weight: 800; padding: 11px 16px; cursor: pointer; }
.standalone-note { font-size: 13px; color: #64748b; }
</style>

<div class="standalone-card">
  <h1>Standalone G3X Replay</h1>
  <p>
    Upload a complete Garmin G3X CSV to build a server-side replay payload and open it directly in the online replay player.
    This is for golden-source visual tuning and does not modify any cockpit recording.
  </p>
  <?php if ($error !== ''): ?>
    <div class="standalone-error"><?= h($error) ?></div>
  <?php endif; ?>
  <form class="standalone-form" method="post" enctype="multipart/form-data">
    <label>
      Garmin G3X CSV
      <input type="file" name="g3x_csv" accept=".csv,text/csv" required>
    </label>
    <div class="standalone-actions">
      <button class="standalone-button" type="submit">Build Replay Online</button>
      <span class="standalone-note">Large flights can take a few seconds and generate a large replay payload.</span>
    </div>
  </form>
</div>

<?php
cw_footer();
