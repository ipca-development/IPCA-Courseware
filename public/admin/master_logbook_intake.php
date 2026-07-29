<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
require_once __DIR__ . '/../../src/CvrDataIntakeReadService.php';
require_once __DIR__ . '/../../src/CvrDeviceEnrollmentService.php';
require_once __DIR__ . '/../../src/CockpitAircraftService.php';
require_once __DIR__ . '/../../src/ManualReconstructionBundleService.php';
require_once __DIR__ . '/../../src/FlightDebriefService.php';

cw_require_admin();

$enrollmentResult = null;
$enrollmentError = '';
$enrollmentCsrf = (string)($_SESSION['cvr_intake_enrollment_csrf'] ?? '');
if ($enrollmentCsrf === '') {
    $enrollmentCsrf = bin2hex(random_bytes(24));
    $_SESSION['cvr_intake_enrollment_csrf'] = $enrollmentCsrf;
}
$aircraftOptions = array();
try {
    $aircraftOptions = (new CockpitAircraftService($pdo))->activeAircraft();
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
        && (string)($_POST['action'] ?? '') === 'create_cvr_enrollment') {
        if (!hash_equals($enrollmentCsrf, (string)($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('Enrollment request expired. Refresh the page and try again.');
        }
        $aircraftId = (int)($_POST['aircraft_id'] ?? 0);
        $selectedAircraft = null;
        foreach ($aircraftOptions as $aircraftOption) {
            if ((int)($aircraftOption['id'] ?? 0) === $aircraftId) {
                $selectedAircraft = $aircraftOption;
                break;
            }
        }
        if (!is_array($selectedAircraft)) {
            throw new RuntimeException('Select a valid active aircraft.');
        }
        $enrollmentResult = (new CvrDeviceEnrollmentService($pdo))->createEnrollment(
            $aircraftId,
            (string)($selectedAircraft['registration'] ?? ''),
            isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null,
            60
        );
    }
} catch (Throwable $e) {
    $enrollmentError = $e->getMessage();
}

$intake = new CvrDataIntakeReadService($pdo);
$dispatch = $intake->dispatchRows();
$audio = $intake->audioRows();
$garmin = $intake->garminRows();
$reconstructionService = new ManualReconstructionBundleService($pdo);
$debriefService = new FlightDebriefService($pdo);
$reconstructionError = trim((string)($_GET['reconstruction_error'] ?? ''));
$reconstructionNotice = '';
$reconstructionCsrf = (string)($_SESSION['cvr_reconstruction_csrf'] ?? '');
if ($reconstructionCsrf === '') {
    $reconstructionCsrf = bin2hex(random_bytes(24));
    $_SESSION['cvr_reconstruction_csrf'] = $reconstructionCsrf;
}
try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        if (!hash_equals($reconstructionCsrf, (string)($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('Reconstruction request expired. Refresh the page and try again.');
        }
        $action = (string)($_POST['action'] ?? '');
        $actorUserId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
        if ($action === 'freeze_reconstruction_bundle') {
            $bundle = $reconstructionService->freezeAndPrepare(
                (int)($_POST['dispatch_id'] ?? 0),
                (int)($_POST['recording_id'] ?? 0),
                (int)($_POST['garmin_csv_file_id'] ?? 0),
                !empty($_POST['include_adsb']),
                $actorUserId > 0 ? $actorUserId : null
            );
            $reconstructionNotice = 'Immutable evidence bundle created. Review it below, then start Reconstruction.';
        } elseif ($action === 'retry_reconstruction_bundle') {
            $reconstructionService->retryPreparation(
                (int)($_POST['bundle_id'] ?? 0),
                $actorUserId > 0 ? $actorUserId : null
            );
            $reconstructionNotice = 'Evidence bundle preparation completed successfully.';
        } elseif ($action === 'lock_bundle_transcript') {
            $reconstructionService->lockTranscript((int)($_POST['bundle_id'] ?? 0), $actorUserId > 0 ? $actorUserId : null);
            $reconstructionNotice = 'Raw transcript snapshot is version-locked and ready for AI debrief generation.';
        } elseif ($action === 'generate_bundle_debrief') {
            $generated = $debriefService->generateStructuredDebrief(
                (int)($_POST['bundle_id'] ?? 0),
                $actorUserId > 0 ? $actorUserId : null
            );
            $reconstructionNotice = 'AI debrief suggestions generated. Instructor review remains authoritative.';
            $_GET['debrief_id'] = (string)($generated['id'] ?? '');
        } elseif ($action === 'save_debrief_review') {
            if ($actorUserId <= 0) {
                throw new RuntimeException('Instructor identity is required.');
            }
            $debriefService->saveInstructorReview(
                (int)($_POST['debrief_id'] ?? 0),
                is_array($_POST['review'] ?? null) ? $_POST['review'] : array(),
                (string)($_POST['instructor_overall'] ?? ''),
                (string)($_POST['instructor_comments'] ?? ''),
                $actorUserId
            );
            $reconstructionNotice = 'Instructor review draft saved.';
        } elseif ($action === 'approve_debrief') {
            if ($actorUserId <= 0) {
                throw new RuntimeException('Instructor identity is required.');
            }
            $debriefService->approveStructuredDebrief((int)($_POST['debrief_id'] ?? 0), $actorUserId);
            $reconstructionNotice = 'Instructor debrief approved and locked.';
        } elseif ($action === 'reject_debrief') {
            if ($actorUserId <= 0) {
                throw new RuntimeException('Instructor identity is required.');
            }
            $debriefService->rejectStructuredDebrief(
                (int)($_POST['debrief_id'] ?? 0),
                (string)($_POST['rejection_reason'] ?? ''),
                $actorUserId
            );
            $reconstructionNotice = 'AI draft rejected. Use Regenerate Debrief on the bundle to create a superseding version.';
        } elseif ($action === 'release_debrief') {
            if ($actorUserId <= 0) {
                throw new RuntimeException('Instructor identity is required.');
            }
            $debriefService->releaseStructuredDebrief(
                (int)($_POST['debrief_id'] ?? 0),
                (int)($_POST['recipient_user_id'] ?? 0),
                $actorUserId
            );
            $reconstructionNotice = 'Approved debrief released to the selected recipient.';
        }
    }
} catch (Throwable $e) {
    $reconstructionError = $e->getMessage();
}
$reconstructionBundles = $reconstructionService->recentBundles();
$selectedDebrief = null;
if ((int)($_GET['debrief_id'] ?? 0) > 0) {
    try {
        $selectedDebrief = $debriefService->structuredDebrief((int)$_GET['debrief_id']);
    } catch (Throwable $e) {
        $reconstructionError = $e->getMessage();
    }
}

function cvr_intake_h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function cvr_intake_timestamp(mixed $value): string
{
    $text = trim((string)$value);
    if ($text === '') {
        return '—';
    }
    $timestamp = strtotime($text);
    return $timestamp === false ? $text : date('M j, Y H:i:s', $timestamp);
}

function cvr_intake_bytes(mixed $value): string
{
    $bytes = max(0, (int)$value);
    if ($bytes === 0) {
        return '—';
    }
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' GB';
    }
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    }
    if ($bytes >= 1024) {
        return number_format($bytes / 1024, 1) . ' KB';
    }
    return number_format($bytes) . ' B';
}

function cvr_intake_status_class(mixed $value): string
{
    $status = strtolower(trim((string)$value));
    if (in_array($status, array('ready', 'uploaded', 'received', 'finalized', 'complete', 'completed', 'ok', 'valid', 'active'), true)) {
        return 'intake-status-good';
    }
    if (in_array($status, array('failed', 'error', 'invalid', 'rejected'), true)) {
        return 'intake-status-bad';
    }
    if ($status === '') {
        return 'intake-status-muted';
    }
    return 'intake-status-pending';
}

function cvr_intake_badge(mixed $value): string
{
    $text = trim((string)$value);
    if ($text === '') {
        $text = 'Unknown';
    }
    return '<span class="intake-status ' . cvr_intake_status_class($text) . '">' . cvr_intake_h(strtoupper(str_replace('_', ' ', $text))) . '</span>';
}

function cvr_intake_short_hash(mixed $value): string
{
    $hash = trim((string)$value);
    return $hash === '' ? '—' : substr($hash, 0, 12) . (strlen($hash) > 12 ? '…' : '');
}

function cvr_intake_crew(mixed $value): string
{
    if (is_array($value)) {
        $decoded = $value;
    } else {
        $decoded = json_decode((string)$value, true);
    }
    if (!is_array($decoded) || $decoded === array()) {
        return '—';
    }
    $names = array();
    foreach ($decoded as $member) {
        if (!is_array($member)) {
            continue;
        }
        $name = trim((string)($member['personName'] ?? $member['person_name'] ?? $member['name'] ?? ''));
        $role = trim((string)($member['role'] ?? $member['crew_role'] ?? ''));
        if ($name !== '') {
            $names[] = $name . ($role !== '' ? ' · ' . $role : '');
        }
    }
    return $names === array() ? '—' : implode(', ', $names);
}

cw_header('Master Logbook');
?>
<style>
.intake-page{display:grid;gap:14px}
.intake-card{background:#fff;border:1px solid rgba(15,23,42,.12);border-radius:16px;padding:16px;box-shadow:0 10px 22px rgba(15,23,42,.05)}
.intake-hero{display:flex;justify-content:space-between;gap:18px;align-items:flex-start;flex-wrap:wrap}
.intake-title{margin:0;color:#0f172a;font-size:26px}
.intake-muted{color:#64748b;font-size:12px;line-height:1.45}
.intake-tabs{display:flex;gap:8px;flex-wrap:wrap}
.intake-tab{border:1px solid #cbd5e1;background:#fff;color:#334155;border-radius:999px;padding:8px 13px;font-size:12px;font-weight:850;cursor:pointer}
.intake-tab.is-active{background:#1d4ed8;color:#fff;border-color:#1d4ed8}
.intake-count{display:inline-flex;align-items:center;justify-content:center;min-width:20px;height:20px;border-radius:999px;margin-left:5px;padding:0 5px;background:#e2e8f0;color:#334155;font-size:10px}
.intake-tab.is-active .intake-count{background:rgba(255,255,255,.2);color:#fff}
.intake-panel{display:none}
.intake-panel.is-active{display:block}
.intake-panel-head{display:flex;justify-content:space-between;gap:12px;align-items:center;margin-bottom:12px}
.intake-panel-title{margin:0;color:#0f172a;font-size:17px}
.intake-table-wrap{overflow:auto;border:1px solid #e2e8f0;border-radius:12px}
.intake-table{width:100%;min-width:1080px;border-collapse:collapse;font-size:12px}
.intake-table th{padding:9px 10px;background:#f8fafc;border-bottom:1px solid #e2e8f0;color:#475569;text-align:left;text-transform:uppercase;font-size:9px;letter-spacing:.06em;white-space:nowrap}
.intake-table td{padding:9px 10px;border-bottom:1px solid #edf2f7;color:#334155;vertical-align:top}
.intake-table tbody tr:last-child td{border-bottom:0}
.intake-table tbody tr:hover{background:#f8fbff}
.intake-mono{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;font-size:11px}
.intake-primary{font-weight:800;color:#0f172a}
.intake-status{display:inline-flex;border-radius:999px;padding:3px 7px;font-size:9px;font-weight:900;white-space:nowrap;background:#e2e8f0;color:#475569}
.intake-status-good{background:#dcfce7;color:#166534}
.intake-status-pending{background:#fef3c7;color:#92400e}
.intake-status-bad{background:#fee2e2;color:#991b1b}
.intake-status-muted{background:#e2e8f0;color:#64748b}
.intake-source-app{background:#dbeafe;color:#1e40af}
.intake-source-sync{background:#ede9fe;color:#5b21b6}
.intake-empty{padding:28px;text-align:center;color:#64748b}
.intake-notice{border:1px solid #fbbf24;background:#fffbeb;color:#92400e;border-radius:12px;padding:12px;font-size:12px}
.intake-error{color:#991b1b;max-width:280px;white-space:normal}
.intake-progress{display:grid;gap:4px;min-width:120px}
.intake-progress-bar{height:5px;background:#e2e8f0;border-radius:999px;overflow:hidden}
.intake-progress-fill{height:100%;background:#2563eb;border-radius:999px}
.intake-refresh{display:inline-flex;align-items:center;border:1px solid #cbd5e1;border-radius:9px;background:#fff;color:#334155;padding:7px 10px;text-decoration:none;font-size:11px;font-weight:800}
.intake-enrollment{display:flex;align-items:end;gap:10px;flex-wrap:wrap}
.intake-field{display:grid;gap:5px;min-width:230px}
.intake-field label{font-size:10px;font-weight:850;text-transform:uppercase;letter-spacing:.06em;color:#64748b}
.intake-select{border:1px solid #cbd5e1;border-radius:9px;background:#fff;color:#0f172a;padding:8px 10px}
.intake-button{border:0;border-radius:9px;background:#1d4ed8;color:#fff;padding:9px 12px;font-size:11px;font-weight:850;cursor:pointer}
.intake-button:disabled{background:#94a3b8;cursor:not-allowed}
.reconstruction-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
.reconstruction-grid .intake-field{min-width:0}
.reconstruction-grid .intake-select{width:100%}
.reconstruction-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:12px}
.intake-code{display:inline-flex;margin-top:10px;border:1px solid #86efac;border-radius:10px;background:#f0fdf4;color:#166534;padding:10px 12px;font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;font-size:18px;font-weight:900;letter-spacing:.08em}
@media(max-width:720px){.intake-card{padding:12px}.intake-title{font-size:22px}.reconstruction-grid{grid-template-columns:1fr}}
</style>

<div class="intake-page" data-intake-page>
  <section class="intake-card intake-hero">
    <div>
      <h1 class="intake-title">Data Intake</h1>
      <p class="intake-muted">Raw CVR inputs received by IPCA.training. This page reports receipt and processing only; it does not correlate or merge flight data.</p>
    </div>
    <a class="intake-refresh" href="/admin/master_logbook.php">Refresh data</a>
  </section>

  <section class="intake-card">
    <div class="intake-panel-head">
      <div>
        <h2 class="intake-panel-title">CVR Unit Enrollment</h2>
        <div class="intake-muted">Generate a one-time code, then enter it in the iOS app under CVR Unit Admin. The code expires after 60 minutes.</div>
      </div>
    </div>
    <form method="post" action="/admin/master_logbook.php" class="intake-enrollment">
      <input type="hidden" name="action" value="create_cvr_enrollment">
      <input type="hidden" name="csrf_token" value="<?= cvr_intake_h($enrollmentCsrf) ?>">
      <div class="intake-field">
        <label for="cvr-enrollment-aircraft">Dedicated aircraft</label>
        <select class="intake-select" id="cvr-enrollment-aircraft" name="aircraft_id" required>
          <option value="">Select aircraft</option>
          <?php foreach ($aircraftOptions as $aircraftOption): ?>
            <option value="<?= (int)($aircraftOption['id'] ?? 0) ?>"><?= cvr_intake_h($aircraftOption['registration'] ?? '') ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button class="intake-button" type="submit">Generate Enrollment Code</button>
    </form>
    <?php if (is_array($enrollmentResult)): ?>
      <div class="intake-code"><?= cvr_intake_h($enrollmentResult['enrollment_code'] ?? '') ?></div>
    <?php endif; ?>
    <?php if ($enrollmentError !== ''): ?>
      <div class="intake-notice" style="margin-top:10px"><?= cvr_intake_h($enrollmentError) ?></div>
    <?php endif; ?>
  </section>

  <section class="intake-card">
    <div class="intake-tabs" role="tablist" aria-label="Data intake sources">
      <button class="intake-tab is-active" type="button" role="tab" aria-selected="true" data-intake-tab="dispatch">
        Dispatch <span class="intake-count"><?= count($dispatch['rows']) ?></span>
      </button>
      <button class="intake-tab" type="button" role="tab" aria-selected="false" data-intake-tab="audio">
        Cockpit Audio <span class="intake-count"><?= count($audio['rows']) ?></span>
      </button>
      <button class="intake-tab" type="button" role="tab" aria-selected="false" data-intake-tab="garmin">
        Garmin CSV <span class="intake-count"><?= count($garmin['rows']) ?></span>
      </button>
      <button class="intake-tab" type="button" role="tab" aria-selected="false" data-intake-tab="reconstruction">
        Reconstruction <span class="intake-count"><?= count($reconstructionBundles) ?></span>
      </button>
    </div>
  </section>

  <section class="intake-card intake-panel is-active" role="tabpanel" data-intake-panel="dispatch">
    <div class="intake-panel-head">
      <div>
        <h2 class="intake-panel-title">Dispatch</h2>
        <div class="intake-muted">Dispatch records received directly from the CVR app.</div>
      </div>
    </div>
    <?php if (!$dispatch['available']): ?>
      <div class="intake-notice"><?= cvr_intake_h($dispatch['message']) ?></div>
    <?php elseif ($dispatch['rows'] === array()): ?>
      <div class="intake-empty">No Dispatch records have been received.</div>
    <?php else: ?>
      <div class="intake-table-wrap">
        <table class="intake-table">
          <thead><tr><th>Received</th><th>Dispatch</th><th>Flight Record</th><th>Aircraft</th><th>Mission</th><th>Crew</th><th>Source</th><th>Device</th><th>Status</th><th>Server Receipt</th><th>Error</th></tr></thead>
          <tbody>
          <?php foreach ($dispatch['rows'] as $row): ?>
            <tr>
              <td><?= cvr_intake_h(cvr_intake_timestamp($row['received_at'] ?? null)) ?></td>
              <td><div class="intake-primary intake-mono"><?= cvr_intake_h($row['dispatch_uuid'] ?? '—') ?></div><div class="intake-muted">Version <?= cvr_intake_h($row['dispatch_version'] ?? '1') ?></div></td>
              <td class="intake-mono"><?= cvr_intake_h($row['workflow_flight_record_uuid'] ?: '—') ?></td>
              <td class="intake-primary"><?= cvr_intake_h($row['aircraft_registration'] ?: '—') ?></td>
              <td><?= cvr_intake_h($row['mission_code'] ?: '—') ?></td>
              <td><?= cvr_intake_h(cvr_intake_crew($row['crew_json'] ?? null)) ?></td>
              <td><?= cvr_intake_h($row['source'] ?: '—') ?></td>
              <td class="intake-mono"><?= cvr_intake_h($row['device_identifier'] ?: '—') ?></td>
              <td><?= cvr_intake_badge($row['status'] ?? '') ?></td>
              <td class="intake-mono"><?= cvr_intake_h($row['server_receipt_id'] ?: '—') ?></td>
              <td class="intake-error"><?= cvr_intake_h($row['error_message'] ?: '—') ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>

  <section class="intake-card intake-panel" role="tabpanel" data-intake-panel="audio">
    <div class="intake-panel-head">
      <div>
        <h2 class="intake-panel-title">Cockpit Audio</h2>
        <div class="intake-muted">Audio upload and transcription status received from recorder units.</div>
      </div>
    </div>
    <?php if (!$audio['available']): ?>
      <div class="intake-notice"><?= cvr_intake_h($audio['message']) ?></div>
    <?php elseif ($audio['rows'] === array()): ?>
      <div class="intake-empty">No Cockpit Audio recordings have been received.</div>
    <?php else: ?>
      <div class="intake-table-wrap">
        <table class="intake-table">
          <thead><tr><th>Received</th><th>Recording</th><th>Aircraft</th><th>Start</th><th>Duration</th><th>Input</th><th>File</th><th>Upload</th><th>Transcription</th><th>Error</th></tr></thead>
          <tbody>
          <?php foreach ($audio['rows'] as $row): ?>
            <?php $transcriptionProgress = max(0, min(100, (int)($row['transcription_progress'] ?? 0))); ?>
            <tr>
              <td><?= cvr_intake_h(cvr_intake_timestamp($row['received_at'] ?? $row['created_at'] ?? null)) ?></td>
              <td><div class="intake-primary intake-mono"><?= cvr_intake_h($row['recording_uid'] ?: '—') ?></div><div class="intake-muted"><?= cvr_intake_h($row['session_uuid'] ?: 'No session link') ?></div></td>
              <td class="intake-primary"><?= cvr_intake_h($row['aircraft_registration'] ?: '—') ?></td>
              <td><?= cvr_intake_h(cvr_intake_timestamp($row['started_at'] ?? null)) ?></td>
              <td><?= (float)($row['duration_seconds'] ?? 0) > 0 ? cvr_intake_h(number_format((float)$row['duration_seconds'], 1) . ' s') : '—' ?></td>
              <td><?= cvr_intake_h($row['input_device'] ?: '—') ?></td>
              <td><div><?= cvr_intake_h($row['original_filename'] ?: '—') ?></div><div class="intake-muted"><?= cvr_intake_h(cvr_intake_bytes($row['file_size_bytes'] ?? 0)) ?></div></td>
              <td><?= cvr_intake_badge($row['upload_status'] ?? '') ?></td>
              <td>
                <div class="intake-progress">
                  <div><?= cvr_intake_badge($row['transcription_status'] ?? '') ?> <span class="intake-muted"><?= $transcriptionProgress ?>%</span></div>
                  <div class="intake-progress-bar"><div class="intake-progress-fill" style="width:<?= $transcriptionProgress ?>%"></div></div>
                </div>
              </td>
              <td class="intake-error"><?= cvr_intake_h($row['error_message'] ?: '—') ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>

  <section class="intake-card intake-panel" role="tabpanel" data-intake-panel="garmin">
    <div class="intake-panel-head">
      <div>
        <h2 class="intake-panel-title">Garmin CSV</h2>
        <div class="intake-muted">CSV evidence received through the CVR App or Automatic IPCA Sync Agent. Historical and FlightCircle sources are excluded.</div>
      </div>
    </div>
    <?php if (!$garmin['available']): ?>
      <div class="intake-notice"><?= cvr_intake_h($garmin['message']) ?></div>
    <?php elseif ($garmin['rows'] === array()): ?>
      <div class="intake-empty">No current Garmin CSV files have been received.</div>
    <?php else: ?>
      <div class="intake-table-wrap">
        <table class="intake-table">
          <thead><tr><th>Received</th><th>Source</th><th>Filename</th><th>Linked Record</th><th>Aircraft</th><th>Coverage</th><th>Size</th><th>SHA-256</th><th>Rows</th><th>Evidence</th><th>Validation</th></tr></thead>
          <tbody>
          <?php foreach ($garmin['rows'] as $row): ?>
            <?php $sourceIsSync = ($row['source_label'] ?? '') === 'IPCA SYNC AGENT'; ?>
            <tr>
              <td><?= cvr_intake_h(cvr_intake_timestamp($row['received_at'] ?? null)) ?></td>
              <td><span class="intake-status <?= $sourceIsSync ? 'intake-source-sync' : 'intake-source-app' ?>"><?= cvr_intake_h($row['source_label'] ?? 'CVR APP') ?></span><div class="intake-muted"><?= cvr_intake_h($row['provider_name'] ?: '') ?></div></td>
              <td><div class="intake-primary"><?= cvr_intake_h($row['original_filename'] ?: '—') ?></div><div class="intake-muted intake-mono"><?= cvr_intake_h($row['csv_file_uuid'] ?: '—') ?></div></td>
              <td><div class="intake-mono"><?= cvr_intake_h($row['workflow_flight_record_uuid'] ?: '—') ?></div><div class="intake-muted"><?= !empty($row['session_id']) ? 'Session ' . cvr_intake_h($row['session_id']) : 'No canonical session' ?></div></td>
              <td class="intake-primary"><?= cvr_intake_h($row['aircraft_registration'] ?: '—') ?></td>
              <td><div><?= cvr_intake_h(cvr_intake_timestamp($row['first_valid_sample_utc'] ?? null)) ?></div><div class="intake-muted">to <?= cvr_intake_h(cvr_intake_timestamp($row['last_valid_sample_utc'] ?? null)) ?></div></td>
              <td><?= cvr_intake_h(cvr_intake_bytes($row['file_size_bytes'] ?? 0)) ?></td>
              <td class="intake-mono" title="<?= cvr_intake_h($row['sha256'] ?? '') ?>"><?= cvr_intake_h(cvr_intake_short_hash($row['sha256'] ?? '')) ?></td>
              <td><?= cvr_intake_h(number_format((int)($row['valid_row_count'] ?? 0))) ?></td>
              <td><?= cvr_intake_badge($row['evidence_status'] ?? '') ?></td>
              <td><?= cvr_intake_badge($row['validation_status'] ?? '') ?><div class="intake-muted"><?= cvr_intake_h($row['validation_severity'] ?: '') ?></div></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>

  <section class="intake-card intake-panel" role="tabpanel" data-intake-panel="reconstruction">
    <div class="intake-panel-head">
      <div>
        <h2 class="intake-panel-title">Reconstruction</h2>
        <div class="intake-muted">Manually link CVR Dispatch, Cockpit Audio, Garmin CSV and ADS-B evidence. FlightCircle and historical sources are rejected in the service layer.</div>
      </div>
    </div>
    <?php if ($reconstructionNotice !== ''): ?>
      <div class="intake-notice" style="border-color:#86efac;background:#f0fdf4;color:#166534;margin-bottom:12px"><?= cvr_intake_h($reconstructionNotice) ?></div>
    <?php endif; ?>
    <?php if ($reconstructionError !== ''): ?>
      <div class="intake-notice" style="border-color:#fca5a5;background:#fef2f2;color:#991b1b;margin-bottom:12px"><?= cvr_intake_h($reconstructionError) ?></div>
    <?php endif; ?>
    <form method="post" action="/admin/master_logbook.php?tab=reconstruction">
      <input type="hidden" name="action" value="freeze_reconstruction_bundle">
      <input type="hidden" name="csrf_token" value="<?= cvr_intake_h($reconstructionCsrf) ?>">
      <div class="reconstruction-grid">
        <div class="intake-field">
          <label for="bundle-dispatch">Dispatch</label>
          <select class="intake-select" id="bundle-dispatch" name="dispatch_id" required>
            <option value="">Select Dispatch</option>
            <?php foreach ($dispatch['rows'] as $row): ?>
              <option value="<?= (int)($row['id'] ?? 0) ?>"><?= cvr_intake_h(($row['aircraft_registration'] ?? '—') . ' · ' . ($row['mission_code'] ?? '—') . ' · ' . ($row['dispatch_uuid'] ?? '')) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="intake-field">
          <label for="bundle-audio">Cockpit Audio / Raw Transcript</label>
          <select class="intake-select" id="bundle-audio" name="recording_id" required>
            <option value="">Select Cockpit Audio</option>
            <?php foreach ($audio['rows'] as $row): ?>
              <option value="<?= (int)($row['id'] ?? 0) ?>"><?= cvr_intake_h(($row['aircraft_registration'] ?? '—') . ' · ' . cvr_intake_timestamp($row['started_at'] ?? null) . ' · Transcript ' . ($row['transcription_status'] ?? 'unknown')) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="intake-field">
          <label for="bundle-garmin">Garmin CSV Source</label>
          <select class="intake-select" id="bundle-garmin" name="garmin_csv_file_id" required>
            <option value="">Select Garmin CSV</option>
            <?php foreach ($garmin['rows'] as $row): ?>
              <option value="<?= (int)($row['id'] ?? 0) ?>"><?= cvr_intake_h(($row['aircraft_registration'] ?? '—') . ' · ' . ($row['source_label'] ?? 'CVR APP') . ' · ' . ($row['original_filename'] ?? '')) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="intake-field">
          <label>ADS-B Traffic</label>
          <label style="display:flex;align-items:center;gap:8px;text-transform:none;font-size:12px;color:#334155">
            <input type="checkbox" name="include_adsb" value="1" checked>
            Link available ADS-B enrichment for selected audio
          </label>
        </div>
      </div>
      <div class="reconstruction-actions">
        <button class="intake-button" type="submit">Create Immutable Evidence Bundle</button>
        <span class="intake-muted">Aircraft and time overlap are validated before freeze.</span>
      </div>
    </form>

    <div class="intake-panel-head" style="margin-top:22px">
      <div>
        <h3 class="intake-panel-title">Frozen Bundles</h3>
        <div class="intake-muted">Corrections create superseding versions; frozen manifests are never edited.</div>
      </div>
    </div>
    <?php if ($reconstructionBundles === array()): ?>
      <div class="intake-empty">No Reconstruction bundles have been created.</div>
    <?php else: ?>
      <div class="intake-table-wrap">
        <table class="intake-table">
          <thead><tr><th>Bundle</th><th>Aircraft / Mission</th><th>Evidence</th><th>Manifest</th><th>Transcript</th><th>Reconstruction</th><th>Action</th><th>Error</th></tr></thead>
          <tbody>
          <?php foreach ($reconstructionBundles as $bundle): ?>
            <?php
              $transcriptGate = $reconstructionService->transcriptGate((int)$bundle['id']);
              $canStart = in_array((string)$bundle['status'], array('reconstruction_ready', 'processing'), true);
              $bundleDebriefs = $debriefService->structuredDebriefsForBundle((int)$bundle['id']);
              $latestDebrief = $bundleDebriefs[0] ?? null;
            ?>
            <tr>
              <td><div class="intake-primary intake-mono"><?= cvr_intake_h($bundle['bundle_uuid']) ?></div><div class="intake-muted">Version <?= (int)$bundle['version_number'] ?> · <?= cvr_intake_h(cvr_intake_timestamp($bundle['frozen_at'] ?? null)) ?></div></td>
              <td><div class="intake-primary"><?= cvr_intake_h($bundle['aircraft_registration']) ?></div><div class="intake-muted"><?= cvr_intake_h($bundle['mission_code'] ?: 'No mission') ?></div></td>
              <td><div>Dispatch #<?= (int)$bundle['dispatch_id'] ?></div><div>Audio #<?= (int)$bundle['cockpit_recording_id'] ?></div><div>Garmin #<?= (int)$bundle['garmin_csv_file_id'] ?></div><div>ADS-B <?= !empty($bundle['adsb_enrichment_id']) ? '#' . (int)$bundle['adsb_enrichment_id'] : 'not linked' ?></div></td>
              <td class="intake-mono" title="<?= cvr_intake_h($bundle['manifest_sha256']) ?>"><?= cvr_intake_h(cvr_intake_short_hash($bundle['manifest_sha256'])) ?></td>
              <td><?= cvr_intake_badge($bundle['transcription_status'] ?? '') ?><div class="intake-muted"><?= cvr_intake_h($transcriptGate['ready'] ? 'Version locked' : $transcriptGate['reason']) ?></div></td>
              <td><?= cvr_intake_badge($bundle['latest_job_status'] ?? $bundle['replay_status'] ?? '') ?><div class="intake-muted"><?= !empty($bundle['latest_job_id']) ? 'Job #' . (int)$bundle['latest_job_id'] : '' ?></div></td>
              <td>
                <?php if ($canStart && (string)($bundle['latest_job_status'] ?? '') !== 'processing'): ?>
                  <form method="post" action="/admin/api/manual_bundle_reconstruct.php">
                    <input type="hidden" name="csrf_token" value="<?= cvr_intake_h($reconstructionCsrf) ?>">
                    <input type="hidden" name="bundle_id" value="<?= (int)$bundle['id'] ?>">
                    <button class="intake-button" type="submit">Start Reconstruction</button>
                  </form>
                <?php elseif ((string)($bundle['latest_job_status'] ?? '') === 'processing'): ?>
                  <?= cvr_intake_badge($bundle['status'] ?? '') ?>
                <?php endif; ?>
                <?php if ((string)($bundle['status'] ?? '') === 'needs_review' && trim((string)($bundle['processing_error'] ?? '')) !== ''): ?>
                  <form method="post" action="/admin/master_logbook.php?tab=reconstruction" style="margin-top:6px">
                    <input type="hidden" name="action" value="retry_reconstruction_bundle">
                    <input type="hidden" name="csrf_token" value="<?= cvr_intake_h($reconstructionCsrf) ?>">
                    <input type="hidden" name="bundle_id" value="<?= (int)$bundle['id'] ?>">
                    <button class="intake-button" type="submit">Retry Bundle Preparation</button>
                  </form>
                <?php endif; ?>
                <?php if (empty($bundle['transcript_snapshot_id']) && strtolower((string)($bundle['transcription_status'] ?? '')) === 'ready'): ?>
                  <form method="post" action="/admin/master_logbook.php?tab=reconstruction" style="margin-top:6px">
                    <input type="hidden" name="action" value="lock_bundle_transcript">
                    <input type="hidden" name="csrf_token" value="<?= cvr_intake_h($reconstructionCsrf) ?>">
                    <input type="hidden" name="bundle_id" value="<?= (int)$bundle['id'] ?>">
                    <button class="intake-button" type="submit">Lock Raw Transcript</button>
                  </form>
                <?php endif; ?>
                <form method="post" action="/admin/master_logbook.php?tab=reconstruction" style="margin-top:6px">
                  <input type="hidden" name="action" value="generate_bundle_debrief">
                  <input type="hidden" name="csrf_token" value="<?= cvr_intake_h($reconstructionCsrf) ?>">
                  <input type="hidden" name="bundle_id" value="<?= (int)$bundle['id'] ?>">
                  <button class="intake-button" type="submit" <?= !$transcriptGate['ready'] ? 'disabled title="' . cvr_intake_h($transcriptGate['reason']) . '"' : '' ?>><?= $latestDebrief ? 'Regenerate Debrief' : 'Generate Debrief' ?></button>
                </form>
                <?php if (is_array($latestDebrief)): ?>
                  <a class="intake-refresh" style="margin-top:6px" href="/admin/master_logbook.php?tab=reconstruction&debrief_id=<?= (int)$latestDebrief['id'] ?>">Review Debrief · <?= cvr_intake_h($latestDebrief['suggested_overall']) ?></a>
                <?php endif; ?>
              </td>
              <td class="intake-error"><?= cvr_intake_h($bundle['processing_error'] ?: '—') ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

    <?php if (is_array($selectedDebrief)): ?>
      <?php
        $chronological = json_decode((string)$selectedDebrief['chronological_review_json'], true);
        $overallOptions = array('BLUE', 'GREEN', 'YELLOW', 'RED', 'INCOMPLETE');
        $isApprovedDebrief = in_array((string)$selectedDebrief['status'], array('approved', 'released'), true);
      ?>
      <div class="intake-panel-head" style="margin-top:24px">
        <div>
          <h3 class="intake-panel-title">Instructor Debrief Review</h3>
          <div class="intake-muted">AI suggestions are evidence-backed drafts. Instructor grades, comments and approval are authoritative.</div>
        </div>
        <?= cvr_intake_badge($selectedDebrief['status']) ?>
      </div>
      <div class="reconstruction-grid">
        <section class="intake-notice" style="border-color:#bfdbfe;background:#eff6ff;color:#1e3a8a">
          <strong>General</strong><br><?= nl2br(cvr_intake_h($selectedDebrief['general_text'])) ?>
        </section>
        <section class="intake-notice" style="border-color:#bfdbfe;background:#eff6ff;color:#1e3a8a">
          <strong>Mission Standards Assessment</strong><br><?= nl2br(cvr_intake_h($selectedDebrief['mission_assessment_text'])) ?>
        </section>
      </div>
      <section class="intake-card" style="margin-top:12px;background:#f8fafc">
        <h4 class="intake-panel-title">Chronological Flight Review</h4>
        <?php foreach (is_array($chronological) ? $chronological : array() as $segment): ?>
          <div style="margin-top:10px">
            <strong><?= cvr_intake_h($segment['title'] ?? 'Flight Segment') ?></strong>
            <div class="intake-muted" style="font-size:12px"><?= nl2br(cvr_intake_h($segment['narrative'] ?? '')) ?></div>
            <?php if (!empty($segment['evidence_refs'])): ?>
              <div class="intake-mono intake-muted"><?= cvr_intake_h(json_encode($segment['evidence_refs'], JSON_UNESCAPED_SLASHES)) ?></div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </section>
      <section class="intake-notice" style="margin-top:12px;border-color:#86efac;background:#f0fdf4;color:#166534">
        <strong>Summary and Next Steps</strong><br><?= nl2br(cvr_intake_h($selectedDebrief['summary_next_steps_text'])) ?>
      </section>

      <form method="post" action="/admin/master_logbook.php?tab=reconstruction&debrief_id=<?= (int)$selectedDebrief['id'] ?>" style="margin-top:14px">
        <input type="hidden" name="action" value="save_debrief_review">
        <input type="hidden" name="csrf_token" value="<?= cvr_intake_h($reconstructionCsrf) ?>">
        <input type="hidden" name="debrief_id" value="<?= (int)$selectedDebrief['id'] ?>">
        <div class="intake-table-wrap">
          <table class="intake-table">
            <thead><tr><th>Task / SRM</th><th>Required</th><th>AI Suggestion</th><th>Evidence / Rationale</th><th>Issue / Improvement</th><th>Instructor Grade</th><th>Instructor Comment</th></tr></thead>
            <tbody>
            <?php foreach ($selectedDebrief['evaluations'] as $evaluation): ?>
              <?php
                $scale = (string)$evaluation['rubric_type'] === 'srm' ? array('EX','PR','MD','NO') : array('DE','EX','PR','PE','NO');
                $refs = json_decode((string)$evaluation['evidence_refs_json'], true);
              ?>
              <tr>
                <td><div class="intake-primary"><?= cvr_intake_h($evaluation['title']) ?></div><div class="intake-muted intake-mono"><?= cvr_intake_h($evaluation['rubric_item_id']) ?></div></td>
                <td><?= cvr_intake_badge($evaluation['required_standard']) ?></td>
                <td><?= cvr_intake_badge($evaluation['suggested_grade'] ?: 'insufficient evidence') ?><div class="intake-muted">Confidence <?= cvr_intake_h(number_format((float)$evaluation['confidence'] * 100, 0)) ?>%</div></td>
                <td><div><?= cvr_intake_h($evaluation['rationale']) ?></div><div class="intake-mono intake-muted"><?= cvr_intake_h(json_encode($refs, JSON_UNESCAPED_SLASHES)) ?></div></td>
                <td><div><?= cvr_intake_h($evaluation['main_issue'] ?: '—') ?></div><div class="intake-muted"><?= cvr_intake_h($evaluation['improvement_suggestion'] ?: '—') ?></div></td>
                <td>
                  <input type="hidden" name="review[<?= (int)$evaluation['id'] ?>][rubric_type]" value="<?= cvr_intake_h($evaluation['rubric_type']) ?>">
                  <select class="intake-select" name="review[<?= (int)$evaluation['id'] ?>][grade]" <?= $isApprovedDebrief ? 'disabled' : '' ?>>
                    <option value="">Select</option>
                    <?php foreach ($scale as $grade): ?>
                      <option value="<?= $grade ?>" <?= (string)$evaluation['instructor_grade'] === $grade ? 'selected' : '' ?>><?= $grade ?></option>
                    <?php endforeach; ?>
                  </select>
                </td>
                <td><input class="intake-select" type="text" name="review[<?= (int)$evaluation['id'] ?>][comment]" value="<?= cvr_intake_h($evaluation['instructor_comment']) ?>" <?= $isApprovedDebrief ? 'disabled' : '' ?>></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <div class="reconstruction-grid" style="margin-top:12px">
          <div class="intake-field">
            <label>Suggested Overall</label>
            <div><?= cvr_intake_badge($selectedDebrief['suggested_overall']) ?></div>
          </div>
          <div class="intake-field">
            <label for="instructor-overall">Instructor Overall</label>
            <select class="intake-select" id="instructor-overall" name="instructor_overall" required <?= $isApprovedDebrief ? 'disabled' : '' ?>>
              <option value="">Select authoritative result</option>
              <?php foreach ($overallOptions as $option): ?>
                <option value="<?= $option ?>" <?= (string)$selectedDebrief['instructor_overall'] === $option ? 'selected' : '' ?>><?= $option ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="intake-field" style="grid-column:1/-1">
            <label for="instructor-comments">Instructor Comments, Main Issues and Improvements</label>
            <textarea class="intake-select" id="instructor-comments" name="instructor_comments" rows="5" <?= $isApprovedDebrief ? 'disabled' : '' ?>><?= cvr_intake_h($selectedDebrief['instructor_comments']) ?></textarea>
          </div>
        </div>
        <?php if (!$isApprovedDebrief): ?>
          <div class="reconstruction-actions">
            <button class="intake-button" type="submit">Save Instructor Draft</button>
          </div>
        <?php endif; ?>
      </form>
      <?php if (!$isApprovedDebrief && (string)$selectedDebrief['status'] === 'instructor_draft'): ?>
        <form method="post" action="/admin/master_logbook.php?tab=reconstruction&debrief_id=<?= (int)$selectedDebrief['id'] ?>" style="margin-top:8px" onsubmit="return confirm('Approve and lock this instructor debrief?');">
          <input type="hidden" name="action" value="approve_debrief">
          <input type="hidden" name="csrf_token" value="<?= cvr_intake_h($reconstructionCsrf) ?>">
          <input type="hidden" name="debrief_id" value="<?= (int)$selectedDebrief['id'] ?>">
          <button class="intake-button" type="submit" style="background:#166534">Approve and Sign Debrief</button>
        </form>
      <?php endif; ?>
      <?php if (!$isApprovedDebrief && (string)$selectedDebrief['status'] !== 'rejected'): ?>
        <form method="post" action="/admin/master_logbook.php?tab=reconstruction&debrief_id=<?= (int)$selectedDebrief['id'] ?>" style="margin-top:8px" onsubmit="return confirm('Reject this AI draft?');">
          <input type="hidden" name="action" value="reject_debrief">
          <input type="hidden" name="csrf_token" value="<?= cvr_intake_h($reconstructionCsrf) ?>">
          <input type="hidden" name="debrief_id" value="<?= (int)$selectedDebrief['id'] ?>">
          <label class="intake-field" style="max-width:560px">
            <span>Rejection Reason</span>
            <input class="intake-select" type="text" name="rejection_reason" required>
          </label>
          <button class="intake-button" type="submit" style="margin-top:8px;background:#991b1b">Reject AI Draft</button>
        </form>
      <?php endif; ?>
      <?php if ((string)$selectedDebrief['status'] === 'approved'): ?>
        <form method="post" action="/admin/master_logbook.php?tab=reconstruction&debrief_id=<?= (int)$selectedDebrief['id'] ?>" style="margin-top:8px" onsubmit="return confirm('Release this approved debrief to the selected user?');">
          <input type="hidden" name="action" value="release_debrief">
          <input type="hidden" name="csrf_token" value="<?= cvr_intake_h($reconstructionCsrf) ?>">
          <input type="hidden" name="debrief_id" value="<?= (int)$selectedDebrief['id'] ?>">
          <label class="intake-field" style="max-width:260px">
            <span>Recipient User ID</span>
            <input class="intake-select" type="number" min="1" name="recipient_user_id" required>
          </label>
          <button class="intake-button" type="submit" style="margin-top:8px;background:#7c3aed">Release Approved Debrief</button>
        </form>
      <?php endif; ?>
    <?php endif; ?>
  </section>
</div>

<script>
(function () {
  const page = document.querySelector('[data-intake-page]');
  if (!page) return;
  const tabs = Array.from(page.querySelectorAll('[data-intake-tab]'));
  const panels = Array.from(page.querySelectorAll('[data-intake-panel]'));
  tabs.forEach((tab) => {
    tab.addEventListener('click', () => {
      const selected = tab.getAttribute('data-intake-tab');
      tabs.forEach((item) => {
        const active = item === tab;
        item.classList.toggle('is-active', active);
        item.setAttribute('aria-selected', active ? 'true' : 'false');
      });
      panels.forEach((panel) => panel.classList.toggle('is-active', panel.getAttribute('data-intake-panel') === selected));
    });
  });
  const requested = new URLSearchParams(window.location.search).get('tab');
  if (requested) {
    const requestedTab = tabs.find((tab) => tab.getAttribute('data-intake-tab') === requested);
    if (requestedTab) requestedTab.click();
  }
})();
</script>
<?php cw_footer(); ?>
