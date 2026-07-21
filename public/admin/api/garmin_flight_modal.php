<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/GarminTrackFlightSummaryService.php';

cw_require_admin();

function garmin_flight_modal_json(int $code, array $payload): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function garmin_flight_modal_metadata_value(?string $json, string $key): string
{
    if ($json === null || trim($json) === '') {
        return '';
    }
    $decoded = json_decode($json, true);
    if (!is_array($decoded) || !array_key_exists($key, $decoded)) {
        return '';
    }
    $value = $decoded[$key];
    return is_array($value) ? implode(', ', array_slice(array_map('strval', $value), 0, 4)) : (string)$value;
}

function garmin_flight_modal_tail_pill(string $tail): string
{
    $tail = trim($tail);
    if ($tail === '' || stripos($tail, 'unknown') !== false) {
        return '<span class="garmin-tail-pill garmin-tail-unknown">Unknown</span>';
    }
    $hue = abs(crc32($tail)) % 360;
    return '<span class="garmin-tail-pill" style="background:hsl(' . $hue . ' 76% 92%);color:hsl(' . $hue . ' 72% 24%);border-color:hsl(' . $hue . ' 58% 72%)">' . h($tail) . '</span>';
}

function garmin_flight_modal_date_label(string $value): string
{
    $timestamp = strtotime($value);
    return $timestamp !== false ? date('D M j, Y', $timestamp) : '--';
}

function garmin_flight_modal_airport_label(string $code): string
{
    $code = strtoupper(trim($code));
    if ($code === '' || $code === '--') {
        return '--';
    }
    return $code;
}

function garmin_flight_modal_status_label(array $summary, string $classification): string
{
    $defaultQuality = strtolower((string)($summary['default_quality'] ?? ''));
    if (($summary['status'] ?? '') === 'ok' && $defaultQuality === 'full') {
        return 'Complete';
    }
    if (str_contains(strtolower($classification), 'gps only')) {
        return 'GPS only';
    }
    return 'Partial';
}

function garmin_flight_modal_quality_score(array $summary, string $statusLabel): int
{
    if ($statusLabel === 'Complete') {
        return 100;
    }
    $score = 15;
    foreach (array('tail', 'dep_airport', 'arr_airport', 'dep_time_lt', 'arr_time_lt', 'hobbs_out', 'hobbs_in', 'tacho_out', 'tacho_in') as $key) {
        if (isset($summary[$key]) && trim((string)$summary[$key]) !== '' && (string)$summary[$key] !== '--') {
            $score += 9;
        }
    }
    return min(100, $score);
}

try {
    $trackArtifactId = max(0, (int)($_GET['track_artifact_id'] ?? 0));
    if ($trackArtifactId <= 0) {
        throw new RuntimeException('track_artifact_id is required.');
    }
    $stmt = $pdo->prepare("
        SELECT
          t.id, t.garmin_entry_uuid, t.track_uuid, t.sha256, t.file_size_bytes, t.session_count, t.field_count,
          t.raw_metadata_json, t.source_descriptors_json, t.first_seen_at, t.last_seen_at
        FROM ipca_garmin_normalized_track_artifacts t
        WHERE t.id = ?
        LIMIT 1
    ");
    $stmt->execute(array($trackArtifactId));
    $track = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($track)) {
        throw new RuntimeException('Garmin flight artifact not found.');
    }
    $summary = (new GarminTrackFlightSummaryService($pdo))->summaryForTrackArtifact($track);
    $classification = garmin_flight_modal_metadata_value((string)($track['raw_metadata_json'] ?? ''), 'trackClassification');
    $sourceNames = garmin_flight_modal_metadata_value((string)($track['raw_metadata_json'] ?? ''), 'sourceNames');
    $statusLabel = garmin_flight_modal_status_label($summary, $classification);
    $qualityScore = garmin_flight_modal_quality_score($summary, $statusLabel);
    $qualityClass = $qualityScore >= 80 ? 'good' : ($qualityScore >= 50 ? 'warn' : 'bad');
    $flightId = 'A-' . str_pad((string)$trackArtifactId, 5, '0', STR_PAD_LEFT);
    $modalId = 'garmin-flight-' . $trackArtifactId;

    ob_start();
    ?>
    <div class="garmin-modal-backdrop is-open" id="<?= h($modalId) ?>">
      <div class="garmin-modal">
        <div class="garmin-modal-header">
          <div>
            <div class="garmin-label">Garmin Flight</div>
            <h3 style="margin:2px 0"><?= h($flightId) ?> · <?= h((string)($summary['tail'] ?? 'Unknown tail')) ?></h3>
            <div class="garmin-muted"><?= h(garmin_flight_modal_date_label((string)($summary['start_utc'] ?? ''))) ?> · <?= h((string)($summary['dep_airport'] ?? '--')) ?> <?= h((string)($summary['dep_time_lt'] ?? '--')) ?> LT - <?= h((string)($summary['arr_airport'] ?? '--')) ?> <?= h((string)($summary['arr_time_lt'] ?? '--')) ?> LT</div>
          </div>
          <button class="garmin-row-button" type="button" data-modal-close>Close</button>
        </div>
        <div class="garmin-modal-body">
          <div class="garmin-flight-hero">
            <div class="garmin-flight-card"><div class="garmin-label">Date</div><div class="garmin-flight-big"><?= h(garmin_flight_modal_date_label((string)($summary['start_utc'] ?? ''))) ?></div></div>
            <div class="garmin-flight-card"><div class="garmin-label">Departure Airport</div><div class="garmin-flight-big"><?= h(garmin_flight_modal_airport_label((string)($summary['dep_airport'] ?? '--'))) ?></div><div class="garmin-muted">Departure Time <?= h((string)($summary['dep_time_lt'] ?? '--')) ?> Local</div></div>
            <div class="garmin-flight-card garmin-flight-center"><div class="garmin-label">Enroute</div><div class="garmin-flight-duration"><?= h((string)($summary['elapsed_time'] ?? '--')) ?></div></div>
            <div class="garmin-flight-card"><div class="garmin-label">Arrival Airport</div><div class="garmin-flight-big"><?= h(garmin_flight_modal_airport_label((string)($summary['arr_airport'] ?? '--'))) ?></div><div class="garmin-muted">Arrival Time <?= h((string)($summary['arr_time_lt'] ?? '--')) ?> Local</div></div>
          </div>
          <div class="garmin-counter-grid">
            <div class="garmin-counter-card"><div class="garmin-label">Hobbs</div><div class="garmin-counter-row"><span>Out</span><strong><?= h((string)($summary['hobbs_out'] ?? '--')) ?></strong></div><div class="garmin-counter-row"><span>In</span><strong><?= h((string)($summary['hobbs_in'] ?? '--')) ?></strong></div><div class="garmin-counter-total">Total Hobbs <?= h((string)($summary['hobbs_time'] ?? '--')) ?></div></div>
            <div class="garmin-counter-card"><div class="garmin-label">Tacho</div><div class="garmin-counter-row"><span>Out</span><strong><?= h((string)($summary['tacho_out'] ?? '--')) ?></strong></div><div class="garmin-counter-row"><span>In</span><strong><?= h((string)($summary['tacho_in'] ?? '--')) ?></strong></div><div class="garmin-counter-total">Total Tacho <?= h((string)($summary['tacho_time'] ?? '--')) ?></div></div>
            <div class="garmin-counter-card"><div class="garmin-label">At A Glance</div><div class="garmin-pill-row"><?= garmin_flight_modal_tail_pill((string)($summary['tail'] ?? '')) ?><span class="garmin-pill garmin-pill-<?= h($qualityClass) ?>"><?= h($statusLabel) ?></span><span class="garmin-pill">Rows <?= number_format((int)($summary['row_count'] ?? 0)) ?></span></div></div>
          </div>
          <div class="garmin-source-grid">
            <div class="garmin-source-panel"><div class="garmin-label">Source Data</div><div class="garmin-source-row"><span>Full Track UUID</span><code><?= h((string)$track['track_uuid']) ?></code></div><div class="garmin-source-row"><span>Entry UUID</span><code><?= h((string)$track['garmin_entry_uuid']) ?></code></div><div class="garmin-source-row"><span>Telemetry</span><strong><?= number_format((int)$track['session_count']) ?> sessions · <?= number_format((int)$track['field_count']) ?> fields</strong></div></div>
            <div class="garmin-source-panel"><div class="garmin-label">Classification</div><div class="garmin-pill-row"><span class="garmin-pill garmin-pill-<?= h($qualityClass) ?>"><?= h($classification === '' ? 'Classification unknown' : $classification) ?></span></div><div class="garmin-label" style="margin-top:12px">Source Quality</div><div class="garmin-quality-bar"><span class="garmin-quality-<?= h($qualityClass) ?>" style="width:<?= (int)$qualityScore ?>%"></span></div><div class="garmin-muted"><?= h((string)($summary['avionics_family'] ?? '--')) ?> · <?= h((string)($summary['default_quality'] ?? '--')) ?></div></div>
          </div>
          <div class="garmin-map-panel"><div class="garmin-label">GPS Track Log</div><div class="garmin-track-map" data-garmin-map data-raw-url="/admin/api/garmin_artifact_raw.php?track_artifact_id=<?= (int)$track['id'] ?>"></div></div>
          <div><a href="/admin/api/garmin_artifact_raw.php?track_artifact_id=<?= (int)$track['id'] ?>" target="_blank" rel="noopener">Open full raw Garmin normalized JSON</a></div>
          <pre class="garmin-raw-block"><?= h(json_encode(array('summary' => $summary, 'source_names' => $sourceNames, 'sha256' => $track['sha256'] ?? ''), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>
        </div>
      </div>
    </div>
    <?php
    garmin_flight_modal_json(200, array('ok' => true, 'html' => (string)ob_get_clean(), 'modal_id' => $modalId));
} catch (Throwable $e) {
    garmin_flight_modal_json(500, array('ok' => false, 'error' => $e->getMessage()));
}
