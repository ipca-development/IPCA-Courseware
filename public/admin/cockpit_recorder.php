<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
require_once __DIR__ . '/../../src/CockpitRecorderService.php';

cw_require_admin();

function cockpit_admin_fmt_bytes(int $bytes): string
{
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' GB';
    }
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 1) . ' MB';
    }
    if ($bytes >= 1024) {
        return number_format($bytes / 1024, 1) . ' KB';
    }
    return $bytes . ' B';
}

function cockpit_admin_fmt_duration(float $seconds): string
{
    $seconds = max(0, (int)round($seconds));
    $h = intdiv($seconds, 3600);
    $m = intdiv($seconds % 3600, 60);
    $s = $seconds % 60;
    return $h > 0 ? sprintf('%d:%02d:%02d', $h, $m, $s) : sprintf('%d:%02d', $m, $s);
}

$error = '';
$recordings = array();
try {
    $service = new CockpitRecorderService($pdo);
    $recordings = $service->adminRecordings(100);
} catch (Throwable $e) {
    $error = $e->getMessage();
}

cw_header('Cockpit Recorder POC');
?>
<style>
.cockpit-recorder-page { display: grid; gap: 18px; }
.cockpit-card { background: #fff; border: 1px solid rgba(15, 23, 42, .12); border-radius: 14px; padding: 18px; box-shadow: 0 10px 24px rgba(15, 23, 42, .06); }
.cockpit-muted { color: #64748b; font-size: 13px; }
.cockpit-table-wrap { overflow-x: auto; }
.cockpit-table { width: 100%; border-collapse: collapse; min-width: 980px; }
.cockpit-table th, .cockpit-table td { border-bottom: 1px solid #e2e8f0; padding: 10px 8px; text-align: left; vertical-align: top; }
.cockpit-table th { color: #475569; font-size: 12px; text-transform: uppercase; letter-spacing: .04em; }
.cockpit-badge { display: inline-flex; border-radius: 999px; padding: 3px 8px; font-size: 12px; font-weight: 700; background: #e2e8f0; color: #334155; }
.cockpit-badge-ready, .cockpit-badge-uploaded { background: #dcfce7; color: #166534; }
.cockpit-badge-queued, .cockpit-badge-transcribing { background: #dbeafe; color: #1d4ed8; }
.cockpit-badge-failed { background: #fee2e2; color: #991b1b; }
.cockpit-transcript { max-width: 520px; white-space: pre-wrap; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 10px; font-size: 13px; }
.cockpit-error { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; border-radius: 10px; padding: 12px; }
.cockpit-audio { width: 260px; max-width: 100%; }
</style>

<div class="cockpit-recorder-page">
  <section class="cockpit-card">
    <h2 style="margin-top:0">Cockpit Recorder Uploads</h2>
    <p class="cockpit-muted">
      Verification page for the iPad Cockpit Recorder POC. The mobile upload API is intentionally unauthenticated for this test build;
      this admin page remains protected by the normal admin login.
    </p>
    <p class="cockpit-muted">
      Public API: <code>POST /api/recordings/upload</code>, <code>GET /api/recordings/{id}/status</code>,
      <code>GET /api/recordings/{id}/transcript</code>, <code>GET /api/recordings</code>.
    </p>
    <p><a href="/admin/cockpit_recorder_aircraft.php">Manage aircraft / ADS-B devices</a></p>
  </section>

  <?php if ($error !== ''): ?>
    <div class="cockpit-error"><?= h($error) ?></div>
  <?php endif; ?>

  <section class="cockpit-card">
    <div class="cockpit-table-wrap">
      <table class="cockpit-table">
        <thead>
          <tr>
            <th>Date/time</th>
            <th>Recording</th>
            <th>Aircraft</th>
            <th>Duration</th>
            <th>File size</th>
            <th>Language</th>
            <th>Upload</th>
            <th>Transcription</th>
            <th>AHRS</th>
            <th>GPS</th>
            <th>Audio</th>
            <th>Transcript / Error</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$recordings): ?>
          <tr>
            <td colspan="12" class="cockpit-muted">No cockpit recorder uploads yet.</td>
          </tr>
        <?php endif; ?>
        <?php foreach ($recordings as $row): ?>
          <?php
            $id = (int)($row['id'] ?? 0);
            $upload = (string)($row['upload_status'] ?? '');
            $transcription = (string)($row['transcription_status'] ?? '');
            $audioUrl = '/admin/cockpit_recorder_audio.php?id=' . $id;
            $ahrsUrl = '/admin/cockpit_recorder_ahrs.php?id=' . $id;
            $gpsUrl = '/admin/cockpit_recorder_gps.php?id=' . $id;
            $transcript = trim((string)($row['transcript_text'] ?? ''));
            $rowError = trim((string)($row['error_message'] ?? ''));
          ?>
          <tr>
            <td>
              <?= h((string)($row['started_at'] ?? $row['created_at'] ?? '')) ?>
              <div class="cockpit-muted">Uploaded <?= h((string)($row['uploaded_at'] ?? '')) ?></div>
            </td>
            <td>
              <strong><?= h((string)($row['recording_uid'] ?? '')) ?></strong>
              <div class="cockpit-muted"><?= h((string)($row['input_device'] ?? '')) ?></div>
            </td>
            <td>
              <?php if (!empty($row['aircraft_registration']) || !empty($row['aircraft_display_name'])): ?>
                <strong><?= h((string)($row['aircraft_display_name'] ?: $row['aircraft_registration'])) ?></strong>
                <div class="cockpit-muted"><?= h((string)($row['aircraft_registration'] ?? '')) ?></div>
                <?php if (!empty($row['aircraft_type'])): ?>
                  <div class="cockpit-muted"><?= h((string)$row['aircraft_type']) ?></div>
                <?php endif; ?>
                <?php if (!empty($row['aircraft_adsb_hex'])): ?>
                  <div class="cockpit-muted">ADS-B <code><?= h((string)$row['aircraft_adsb_hex']) ?></code></div>
                <?php endif; ?>
              <?php else: ?>
                <span class="cockpit-muted">Not selected</span>
              <?php endif; ?>
            </td>
            <td><?= h(cockpit_admin_fmt_duration((float)($row['duration_seconds'] ?? 0))) ?></td>
            <td><?= h(cockpit_admin_fmt_bytes((int)($row['file_size_bytes'] ?? 0))) ?></td>
            <td><?= h((string)($row['language'] ?? 'en')) ?></td>
            <td><span class="cockpit-badge cockpit-badge-<?= h($upload) ?>"><?= h($upload) ?></span></td>
            <td>
              <span class="cockpit-badge cockpit-badge-<?= h($transcription) ?>"><?= h($transcription) ?></span>
              <div class="cockpit-muted"><?= (int)($row['transcription_progress'] ?? 0) ?>%</div>
            </td>
            <td>
              <?php if (!empty($row['ahrs_storage_path'])): ?>
                <span class="cockpit-badge cockpit-badge-ready">Saved</span>
                <div class="cockpit-muted"><?= (int)($row['ahrs_sample_count'] ?? 0) ?> samples</div>
                <div class="cockpit-muted"><?= h(cockpit_admin_fmt_bytes((int)($row['ahrs_file_size_bytes'] ?? 0))) ?></div>
                <div><a href="<?= h($ahrsUrl) ?>">Download AHRS JSON</a></div>
              <?php else: ?>
                <span class="cockpit-muted">No AHRS</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if (!empty($row['gps_storage_path'])): ?>
                <span class="cockpit-badge cockpit-badge-ready">Saved</span>
                <div class="cockpit-muted"><?= (int)($row['gps_sample_count'] ?? 0) ?> samples</div>
                <div class="cockpit-muted"><?= h(cockpit_admin_fmt_bytes((int)($row['gps_file_size_bytes'] ?? 0))) ?></div>
                <div><a href="<?= h($gpsUrl) ?>">Download GPS JSON</a></div>
              <?php else: ?>
                <span class="cockpit-badge">Missing</span>
                <div class="cockpit-muted">No GPS JSON uploaded</div>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($id > 0 && !empty($row['storage_path'])): ?>
                <audio class="cockpit-audio" controls preload="none" src="<?= h($audioUrl) ?>"></audio>
                <div><a href="<?= h($audioUrl) ?>&download=1">Download audio</a></div>
              <?php else: ?>
                <span class="cockpit-muted">No file</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($rowError !== ''): ?>
                <div class="cockpit-error"><?= h($rowError) ?></div>
              <?php elseif ($transcript !== ''): ?>
                <div class="cockpit-transcript"><?= h($transcript) ?></div>
              <?php else: ?>
                <span class="cockpit-muted">Transcript not ready.</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>
</div>

<?php
cw_footer();
