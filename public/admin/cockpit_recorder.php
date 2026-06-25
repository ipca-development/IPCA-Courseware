<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
require_once __DIR__ . '/../../src/CockpitRecorderService.php';
require_once __DIR__ . '/../../src/CockpitReconstructionService.php';
require_once __DIR__ . '/../../src/CockpitAdsbEnrichmentService.php';

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

function cockpit_admin_fmt_rate(mixed $rate): string
{
    return is_numeric($rate) ? number_format((float)$rate, 2) . ' Hz' : '--';
}

function cockpit_admin_fmt_timestamp(mixed $timestamp): string
{
    $timestamp = trim((string)$timestamp);
    return $timestamp !== '' ? $timestamp : '--';
}

function cockpit_admin_fmt_coordinate(mixed $coordinate): string
{
    if (!is_array($coordinate) || !isset($coordinate['latitude'], $coordinate['longitude'])) {
        return '--';
    }
    return number_format((float)$coordinate['latitude'], 6) . ', ' . number_format((float)$coordinate['longitude'], 6);
}

function cockpit_admin_fmt_chunk_range(float $start, float $end): string
{
    return cockpit_admin_fmt_duration($start) . '-' . cockpit_admin_fmt_duration($end);
}

/**
 * @param array<string,mixed> $row
 * @return array<string,mixed>
 */
function cockpit_admin_health(array $row): array
{
    $raw = trim((string)($row['health_summary_json'] ?? ''));
    if ($raw === '') {
        return array();
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : array();
}

/**
 * @param array<string,mixed> $row
 * @return array<string,mixed>
 */
function cockpit_admin_reconstruction_summary(array $row): array
{
    $raw = trim((string)($row['reconstruction_summary_json'] ?? ''));
    if ($raw === '') {
        return array();
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : array();
}

$error = '';
$recordings = array();
$service = null;
$adsbService = null;
try {
    $service = new CockpitRecorderService($pdo);
    $adsbService = new CockpitAdsbEnrichmentService($pdo);
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
.cockpit-badge-warning { background: #fef3c7; color: #92400e; }
.cockpit-transcript { max-width: 520px; white-space: pre-wrap; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 10px; font-size: 13px; }
.cockpit-error { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; border-radius: 10px; padding: 12px; }
.cockpit-audio { width: 260px; max-width: 100%; }
.cockpit-health { min-width: 260px; }
.cockpit-health-grid { display: grid; gap: 4px; font-size: 12px; color: #334155; }
.cockpit-health-section { margin-top: 8px; padding-top: 8px; border-top: 1px solid #e2e8f0; }
.cockpit-warning-list { margin: 6px 0 0; padding-left: 18px; color: #92400e; font-size: 12px; }
.cockpit-chunks { display: grid; gap: 6px; min-width: 190px; font-size: 12px; }
.cockpit-chunk-row { display: grid; grid-template-columns: 1fr auto; gap: 8px; align-items: center; }
.cockpit-actions { display: grid; gap: 6px; min-width: 210px; }
.cockpit-button { border: 0; border-radius: 8px; background: #1d4ed8; color: #fff; font-weight: 700; padding: 7px 10px; cursor: pointer; }
.cockpit-link-grid { display: flex; flex-wrap: wrap; gap: 6px 10px; font-size: 12px; }
.cockpit-summary-grid { display: grid; gap: 3px; margin-top: 6px; font-size: 12px; color: #334155; }
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
            <th>Chunks</th>
            <th>AHRS</th>
            <th>GPS</th>
            <th>Health</th>
            <th>Reconstruction</th>
            <th>Audio</th>
            <th>Transcript / Error</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$recordings): ?>
          <tr>
            <td colspan="15" class="cockpit-muted">No cockpit recorder uploads yet.</td>
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
            $health = cockpit_admin_health($row);
            $healthWarnings = isset($health['warnings']) && is_array($health['warnings']) ? $health['warnings'] : array();
            $healthAudio = isset($health['audio']) && is_array($health['audio']) ? $health['audio'] : array();
            $healthAhrs = isset($health['ahrs']) && is_array($health['ahrs']) ? $health['ahrs'] : array();
            $healthGps = isset($health['gps']) && is_array($health['gps']) ? $health['gps'] : array();
            $chunks = $service instanceof CockpitRecorderService ? $service->adminTranscriptionChunks($id) : array();
            $reconSummary = cockpit_admin_reconstruction_summary($row);
            $sourceAlignment = isset($reconSummary['source_alignment']) && is_array($reconSummary['source_alignment']) ? $reconSummary['source_alignment'] : array();
            $alignmentSources = isset($sourceAlignment['sources']) && is_array($sourceAlignment['sources']) ? $sourceAlignment['sources'] : array();
            $alignmentWarnings = isset($sourceAlignment['warnings']) && is_array($sourceAlignment['warnings']) ? $sourceAlignment['warnings'] : array();
            $reconStatus = (string)($row['reconstruction_status'] ?? 'not_started');
            $timelineStatus = (string)($row['timeline_status'] ?? 'not_started');
            $adsbStatus = (string)($row['adsb_status'] ?? 'not_started');
            $replayUrl = '/admin/cockpit_recorder_replay.php?id=' . $id;
            $replayJsonUrl = '/api/recordings/replay.php?id=' . $id;
            $g3xUrl = '/admin/cockpit_recorder_g3x.php?id=' . $id;
            $adsbDetail = $adsbService instanceof CockpitAdsbEnrichmentService ? $adsbService->statusForRecording($id) : array();
            $adsbDisplayStatus = (string)($adsbDetail['status'] ?? $adsbStatus);
            $adsbOwnshipCount = (int)($adsbDetail['ownship_sample_count'] ?? 0);
            $adsbError = trim((string)($adsbDetail['error_message'] ?? ''));
            $adsbRawUrl = '/admin/cockpit_recorder_adsb.php?id=' . $id . '&type=raw';
            $adsbNormalizedUrl = '/admin/cockpit_recorder_adsb.php?id=' . $id . '&type=normalized';
            $chunkReady = 0;
            $chunkFailed = 0;
            foreach ($chunks as $chunkForCount) {
                if ((string)($chunkForCount['status'] ?? '') === 'ready') {
                    $chunkReady++;
                } elseif ((string)($chunkForCount['status'] ?? '') === 'failed') {
                    $chunkFailed++;
                }
            }
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
              <?php if ($id > 0 && $transcription !== 'ready'): ?>
                <form method="post" action="/admin/api/cockpit_recorder_transcribe.php" style="margin-top:6px">
                  <input type="hidden" name="id" value="<?= $id ?>">
                  <input type="hidden" name="mode" value="step">
                  <button class="cockpit-button" type="submit">Process next transcript chunk</button>
                </form>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($chunks): ?>
                <div class="cockpit-chunks">
                  <div>
                    <strong><?= count($chunks) ?> chunk<?= count($chunks) === 1 ? '' : 's' ?></strong>
                    <div class="cockpit-muted"><?= $chunkReady ?> ready<?= $chunkFailed > 0 ? ', ' . $chunkFailed . ' failed' : '' ?></div>
                  </div>
                  <?php foreach ($chunks as $chunk): ?>
                    <?php
                      $chunkStatus = (string)($chunk['status'] ?? '');
                      $chunkError = trim((string)($chunk['error_message'] ?? ''));
                    ?>
                    <div class="cockpit-chunk-row">
                      <span><?= h(cockpit_admin_fmt_chunk_range((float)($chunk['start_seconds'] ?? 0), (float)($chunk['end_seconds'] ?? 0))) ?></span>
                      <span class="cockpit-badge cockpit-badge-<?= h($chunkStatus) ?>"><?= h($chunkStatus) ?></span>
                    </div>
                    <div class="cockpit-muted"><?= (int)($chunk['text_length'] ?? 0) ?> chars<?= $chunkError !== '' ? ' - ' . h($chunkError) : '' ?></div>
                  <?php endforeach; ?>
                </div>
              <?php else: ?>
                <span class="cockpit-muted">Single pass</span>
              <?php endif; ?>
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
            <td class="cockpit-health">
              <?php if ($health): ?>
                <?php if ($healthWarnings): ?>
                  <span class="cockpit-badge cockpit-badge-warning"><?= count($healthWarnings) ?> warning<?= count($healthWarnings) === 1 ? '' : 's' ?></span>
                <?php else: ?>
                  <span class="cockpit-badge cockpit-badge-ready">Healthy</span>
                <?php endif; ?>
                <div class="cockpit-muted">Analyzed <?= h((string)($row['health_analyzed_at'] ?? ($health['analyzed_at'] ?? ''))) ?></div>

                <div class="cockpit-health-grid cockpit-health-section">
                  <strong>Audio</strong>
                  <div>Duration: <?= h(cockpit_admin_fmt_duration((float)($healthAudio['duration_seconds'] ?? $row['duration_seconds'] ?? 0))) ?></div>
                </div>

                <div class="cockpit-health-grid cockpit-health-section">
                  <strong>AHRS</strong>
                  <div>Samples: <?= (int)($healthAhrs['sample_count'] ?? 0) ?></div>
                  <div>Rate: <?= h(cockpit_admin_fmt_rate($healthAhrs['average_sample_rate_hz'] ?? null)) ?></div>
                  <div>First: <?= h(cockpit_admin_fmt_timestamp($healthAhrs['first_timestamp'] ?? null)) ?></div>
                  <div>Last: <?= h(cockpit_admin_fmt_timestamp($healthAhrs['last_timestamp'] ?? null)) ?></div>
                </div>

                <div class="cockpit-health-grid cockpit-health-section">
                  <strong>GPS</strong>
                  <div>Samples: <?= (int)($healthGps['sample_count'] ?? 0) ?></div>
                  <div>Rate: <?= h(cockpit_admin_fmt_rate($healthGps['average_sample_rate_hz'] ?? null)) ?></div>
                  <div>Max GS: <?= is_numeric($healthGps['max_groundspeed_kt'] ?? null) ? h(number_format((float)$healthGps['max_groundspeed_kt'], 1) . ' kt') : '--' ?></div>
                  <div>First: <?= h(cockpit_admin_fmt_timestamp($healthGps['first_timestamp'] ?? null)) ?></div>
                  <div>Last: <?= h(cockpit_admin_fmt_timestamp($healthGps['last_timestamp'] ?? null)) ?></div>
                  <div>From: <?= h(cockpit_admin_fmt_coordinate($healthGps['first_coordinate'] ?? null)) ?></div>
                  <div>To: <?= h(cockpit_admin_fmt_coordinate($healthGps['last_coordinate'] ?? null)) ?></div>
                </div>

                <?php if ($healthWarnings): ?>
                  <ul class="cockpit-warning-list">
                    <?php foreach ($healthWarnings as $warning): ?>
                      <li><?= h((string)$warning) ?></li>
                    <?php endforeach; ?>
                  </ul>
                <?php endif; ?>
              <?php else: ?>
                <span class="cockpit-muted">Not analyzed</span>
                <div class="cockpit-muted">Apply health migration and upload again.</div>
              <?php endif; ?>
            </td>
            <td>
              <div class="cockpit-actions">
                <div>
                  <span class="cockpit-badge cockpit-badge-<?= h($reconStatus) ?>"><?= h($reconStatus) ?></span>
                  <div class="cockpit-muted">Timeline: <?= h($timelineStatus) ?></div>
                  <div class="cockpit-muted">ADS-B: <?= h($adsbDisplayStatus) ?></div>
                </div>
                <div class="cockpit-summary-grid">
                  <strong>ADS-B enrichment</strong>
                  <?php if (!empty($row['aircraft_adsb_hex'])): ?>
                    <div>Hex: <code><?= h((string)$row['aircraft_adsb_hex']) ?></code></div>
                    <div>Ownship samples: <?= $adsbOwnshipCount ?></div>
                    <?php if ($adsbError !== ''): ?>
                      <div class="cockpit-muted"><?= h($adsbError) ?></div>
                    <?php endif; ?>
                    <?php if (!empty($adsbDetail['raw_storage_path']) || !empty($adsbDetail['normalized_storage_path'])): ?>
                      <div class="cockpit-link-grid">
                        <?php if (!empty($adsbDetail['raw_storage_path'])): ?>
                          <a href="<?= h($adsbRawUrl) ?>">Raw ADS-B JSON</a>
                        <?php endif; ?>
                        <?php if (!empty($adsbDetail['normalized_storage_path'])): ?>
                          <a href="<?= h($adsbNormalizedUrl) ?>">Normalized ADS-B JSON</a>
                        <?php endif; ?>
                      </div>
                    <?php endif; ?>
                    <form method="post" action="/admin/api/cockpit_recorder_adsb_enrich.php" style="margin-top:6px">
                      <input type="hidden" name="id" value="<?= $id ?>">
                      <button class="cockpit-button" type="submit">Fetch ADS-B</button>
                    </form>
                    <?php if ($adsbDisplayStatus === 'ready'): ?>
                      <div class="cockpit-muted">Reconstruct again to include ADS-B in replay/G3X.</div>
                    <?php endif; ?>
                  <?php else: ?>
                    <div><span class="cockpit-badge">not_available</span></div>
                    <div class="cockpit-muted">No selected aircraft hex.</div>
                  <?php endif; ?>
                </div>
                <?php if ($reconSummary): ?>
                  <div class="cockpit-summary-grid">
                    <strong>Timeline summary</strong>
                    <div>Samples: <?= (int)($reconSummary['sample_count'] ?? 0) ?></div>
                    <div>Phases: <?= (int)($reconSummary['phase_count'] ?? 0) ?> · Events: <?= (int)($reconSummary['event_count'] ?? 0) ?></div>
                    <?php if (isset($reconSummary['adsb_sample_count'])): ?>
                      <div>ADS-B samples: <?= (int)$reconSummary['adsb_sample_count'] ?></div>
                    <?php endif; ?>
                    <div>Max alt: <?= is_numeric($reconSummary['max_altitude_ft'] ?? null) ? h(number_format((float)$reconSummary['max_altitude_ft'], 0) . ' ft') : '--' ?></div>
                    <div>Max GS: <?= is_numeric($reconSummary['max_groundspeed_kt'] ?? null) ? h(number_format((float)$reconSummary['max_groundspeed_kt'], 1) . ' kt') : '--' ?></div>
                    <div>Max bank: <?= is_numeric($reconSummary['max_bank_deg'] ?? null) ? h(number_format((float)$reconSummary['max_bank_deg'], 1) . ' deg') : '--' ?></div>
                    <?php $derivedReplay = isset($reconSummary['derived_replay_values']) && is_array($reconSummary['derived_replay_values']) ? $reconSummary['derived_replay_values'] : array(); ?>
                    <?php if ($derivedReplay): ?>
                      <strong>Derived replay values</strong>
                      <div>Estimated baro samples: <?= (int)($derivedReplay['estimated_baro_altitude_samples'] ?? 0) ?></div>
                      <div>Estimated VS samples: <?= (int)($derivedReplay['estimated_vertical_speed_samples'] ?? 0) ?></div>
                      <div>Altimeter source: <?= h((string)($derivedReplay['altimeter_setting_source'] ?? 'unavailable')) ?><?= is_numeric($derivedReplay['altimeter_setting_inhg'] ?? null) ? h(' · ' . number_format((float)$derivedReplay['altimeter_setting_inhg'], 2) . ' inHg') : '' ?></div>
                      <div class="cockpit-muted">GPS altitude is primary. Estimated baro/VS are derived replay values, not raw aircraft instrument values.</div>
                    <?php endif; ?>
                    <?php if ($alignmentSources): ?>
                      <strong>Source alignment</strong>
                      <?php foreach (array('gps' => 'GPS', 'ahrs' => 'AHRS', 'adsb' => 'ADS-B') as $sourceKey => $sourceLabel): ?>
                        <?php $source = isset($alignmentSources[$sourceKey]) && is_array($alignmentSources[$sourceKey]) ? $alignmentSources[$sourceKey] : array(); ?>
                        <div><?= h($sourceLabel) ?>: <?= (int)($source['sample_count'] ?? 0) ?> samples · <?= is_numeric($source['coverage_percent'] ?? null) ? h(number_format((float)$source['coverage_percent'], 1) . '%') : '--' ?> coverage<?= is_numeric($source['max_gap_seconds'] ?? null) ? h(' · max gap ' . number_format((float)$source['max_gap_seconds'], 1) . 's') : '' ?></div>
                      <?php endforeach; ?>
                      <?php foreach ($alignmentWarnings as $warning): ?>
                        <div class="cockpit-muted"><?= h((string)$warning) ?></div>
                      <?php endforeach; ?>
                    <?php endif; ?>
                  </div>
                <?php endif; ?>
                <form method="post" action="/admin/api/cockpit_recorder_reconstruct.php">
                  <input type="hidden" name="id" value="<?= $id ?>">
                  <button class="cockpit-button" type="submit">Reconstruct</button>
                </form>
                <div class="cockpit-link-grid">
                  <a href="<?= h($replayUrl) ?>">Replay</a>
                  <a href="<?= h($replayJsonUrl) ?>">Replay JSON</a>
                  <a href="<?= h($g3xUrl) ?>">G3X CSV</a>
                </div>
              </div>
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
