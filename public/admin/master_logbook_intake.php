<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
require_once __DIR__ . '/../../src/CvrDataIntakeReadService.php';
require_once __DIR__ . '/../../src/CvrIntakeDisplayService.php';
require_once __DIR__ . '/../../src/CvrIntakeAdminUploadService.php';
require_once __DIR__ . '/../../src/CvrAudioIntakeMetricsService.php';
require_once __DIR__ . '/../../src/CvrDeviceEnrollmentService.php';
require_once __DIR__ . '/../../src/CockpitAircraftService.php';
require_once __DIR__ . '/../../src/ManualReconstructionBundleService.php';
require_once __DIR__ . '/../../src/FlightDebriefService.php';
require_once __DIR__ . '/../../src/ReplayShareService.php';

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
$intakeDisplay = new CvrIntakeDisplayService($pdo);
$intakeUpload = new CvrIntakeAdminUploadService($pdo);
$reconstructionService = new ManualReconstructionBundleService($pdo);
$debriefService = new FlightDebriefService($pdo);
$replayShareService = new ReplayShareService($pdo);
$createdReplayShare = null;
$reconstructionError = trim((string)($_GET['reconstruction_error'] ?? ''));
$reconstructionNotice = '';
if ((string)($_GET['debrief_generation'] ?? '') === 'started') {
    $reconstructionNotice = 'AI Debrief generation started in the background. Refresh this tab to see completion status.';
} elseif ((string)($_GET['debrief_generation'] ?? '') === 'running') {
    $reconstructionNotice = 'AI Debrief generation is already running in the background.';
}
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
        } elseif ($action === 'supersede_reconstruction_bundle') {
            $reconstructionService->supersedeBundleSources(
                (int)($_POST['bundle_id'] ?? 0),
                (int)($_POST['dispatch_id'] ?? 0),
                (int)($_POST['recording_id'] ?? 0),
                (int)($_POST['garmin_csv_file_id'] ?? 0),
                !empty($_POST['include_adsb']),
                $actorUserId > 0 ? $actorUserId : null
            );
            $reconstructionNotice = 'Superseding bundle created with updated evidence sources. Re-derive Flight Record if Garmin CSV changed.';
        } elseif ($action === 'upload_manual_garmin_csv') {
            $aircraftRegistration = (string)($_POST['aircraft_registration'] ?? '');
            foreach ($aircraftOptions as $aircraftOption) {
                if ((int)($aircraftOption['id'] ?? 0) === (int)($_POST['aircraft_id'] ?? 0)) {
                    $aircraftRegistration = (string)($aircraftOption['registration'] ?? $aircraftRegistration);
                    break;
                }
            }
            $uploadResult = $intakeUpload->uploadGarminCsv(
                $_FILES['garmin_csv'] ?? array(),
                $aircraftRegistration,
                trim((string)($_POST['workflow_flight_record_uuid'] ?? '')) ?: null
            );
            $reconstructionNotice = (string)($uploadResult['message'] ?? 'Garmin CSV uploaded.');
        } elseif ($action === 'upload_manual_audio') {
            $uploadResult = $intakeUpload->uploadAudio(
                $_FILES['cockpit_audio'] ?? array(),
                (int)($_POST['aircraft_id'] ?? 0),
                (string)($_POST['started_at_local'] ?? ''),
                isset($_POST['duration_seconds']) && $_POST['duration_seconds'] !== ''
                    ? (float)$_POST['duration_seconds']
                    : null,
                trim((string)($_POST['student_name'] ?? '')),
                trim((string)($_POST['instructor_name'] ?? '')),
                trim((string)($_POST['mission_code'] ?? ''))
            );
            $reconstructionNotice = (string)($uploadResult['message'] ?? 'Cockpit Audio uploaded.');
        } elseif ($action === 'retry_reconstruction_bundle') {
            $reconstructionService->retryPreparation(
                (int)($_POST['bundle_id'] ?? 0),
                $actorUserId > 0 ? $actorUserId : null
            );
            $reconstructionNotice = 'Evidence bundle preparation completed successfully.';
        } elseif ($action === 'lock_bundle_transcript') {
            $reconstructionService->lockTranscript((int)($_POST['bundle_id'] ?? 0), $actorUserId > 0 ? $actorUserId : null);
            $reconstructionNotice = 'Raw transcript snapshot is version-locked and ready for AI debrief generation.';
        } elseif ($action === 'rebuild_bundle_flight_record') {
            $reconstructionService->rebuildFlightRecord(
                (int)($_POST['bundle_id'] ?? 0),
                $actorUserId > 0 ? $actorUserId : null
            );
            $reconstructionNotice = 'Canonical Flight Record and logbook fields rebuilt from the selected Garmin evidence.';
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
            $reconstructionNotice = 'Optional adjustments saved. The Debriefing Sheet is ready for verification.';
        } elseif ($action === 'approve_debrief') {
            if ($actorUserId <= 0) {
                throw new RuntimeException('Instructor identity is required.');
            }
            $debriefService->approveStructuredDebrief((int)($_POST['debrief_id'] ?? 0), $actorUserId);
            $reconstructionNotice = 'Debriefing Sheet verified and locked.';
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
        } elseif ($action === 'create_replay_share') {
            if ($actorUserId <= 0) {
                throw new RuntimeException('Instructor identity is required.');
            }
            $createdReplayShare = $replayShareService->create(
                (int)($_POST['debrief_id'] ?? 0),
                $actorUserId
            );
            $reconstructionNotice = 'A new 12-hour replay link was created. Any previous link is now revoked.';
        } elseif ($action === 'revoke_replay_share') {
            if ($actorUserId <= 0) {
                throw new RuntimeException('Instructor identity is required.');
            }
            $replayShareService->revoke((int)($_POST['debrief_id'] ?? 0), $actorUserId);
            $reconstructionNotice = 'Replay access revoked immediately.';
        }
    }
} catch (Throwable $e) {
    $reconstructionError = $e->getMessage();
}
$dispatch = $intake->dispatchRows();
$audio = $intake->audioRows();
if ($audio['available'] && $audio['rows'] !== array()) {
    $audio['rows'] = (new CvrAudioIntakeMetricsService($pdo))->enrichRows($audio['rows']);
}
$audioShortThresholdSeconds = 600;
$audioShortRowCount = 0;
foreach ($audio['rows'] as $audioRow) {
    $audioDurationSeconds = (float)($audioRow['duration_seconds'] ?? 0);
    if ($audioDurationSeconds > 0 && $audioDurationSeconds < $audioShortThresholdSeconds) {
        $audioShortRowCount++;
    }
}
$audioVisibleRowCount = max(0, count($audio['rows']) - $audioShortRowCount);
$garmin = $intake->garminRows();
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

function cvr_replay_share_base_url(): string
{
    $configured = trim((string)(getenv('CW_PUBLIC_BASE_URL') ?: getenv('PUBLIC_BASE_URL') ?: getenv('APP_URL') ?: ''));
    if ($configured !== '') {
        return rtrim($configured, '/');
    }
    $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
    if ($host === '' || !preg_match('/^[A-Za-z0-9.-]+(?::\d{1,5})?$/', $host)) {
        return '';
    }
    $https = !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off';
    return ($https ? 'https' : 'http') . '://' . $host;
}

function cvr_intake_timestamp(mixed $value, string $timezone = 'UTC'): string
{
    $text = trim((string)$value);
    if ($text === '') {
        return '—';
    }
    return cw_logbook_datetime($text, $timezone);
}

function cvr_intake_local_datetime(PDO $pdo, mixed $value, string $registration = ''): string
{
    $text = trim((string)$value);
    if ($text === '') {
        return '—';
    }
    $timezone = cw_aircraft_operational_timezone_by_registration($pdo, $registration);
    return cw_logbook_datetime($text, $timezone);
}

function cvr_intake_local_time(PDO $pdo, mixed $value, string $registration = ''): string
{
    $text = trim((string)$value);
    if ($text === '') {
        return '—';
    }
    $timezone = cw_aircraft_operational_timezone_by_registration($pdo, $registration);
    return cw_logbook_time($text, $timezone) . ' LT';
}

function cvr_intake_california_timezone(PDO $pdo, string $registration = ''): string
{
    $timezone = cw_aircraft_operational_timezone_by_registration($pdo, $registration);
    return $timezone !== 'UTC' ? $timezone : 'America/Los_Angeles';
}

function cvr_intake_audio_received_label(PDO $pdo, mixed $value, string $registration = ''): string
{
    $text = trim((string)$value);
    if ($text === '') {
        return '—';
    }
    $timezone = cvr_intake_california_timezone($pdo, $registration);
    $dt = cw_dt_obj($text, $timezone);
    if (!$dt instanceof DateTimeImmutable) {
        return '—';
    }
    $now = new DateTimeImmutable('now', new DateTimeZone($timezone));
    $today = $now->format('Y-m-d');
    $time = $dt->format('H:i');
    if ($dt->format('Y-m-d') === $today) {
        return 'Today, ' . $time . ' LT';
    }
    return $dt->format('D M j, Y') . ' - ' . $time . ' LT';
}

function cvr_intake_audio_start_label(PDO $pdo, mixed $value, string $registration = ''): string
{
    $text = trim((string)$value);
    if ($text === '') {
        return '—';
    }
    $timezone = cvr_intake_california_timezone($pdo, $registration);
    $dt = cw_dt_obj($text, $timezone);
    return $dt instanceof DateTimeImmutable ? ($dt->format('H:i:s') . ' LT') : '—';
}

function cvr_intake_audio_stop_label(PDO $pdo, mixed $startedAt, float $durationSeconds, string $registration = ''): string
{
    $startText = trim((string)$startedAt);
    if ($startText === '' || $durationSeconds <= 0) {
        return '—';
    }
    $timezone = cvr_intake_california_timezone($pdo, $registration);
    $start = cw_dt_obj($startText, $timezone);
    if (!$start instanceof DateTimeImmutable) {
        return '—';
    }
    $end = $start->modify('+' . max(0, (int)round($durationSeconds)) . ' seconds');
    return $end->format('H:i:s') . ' LT';
}

function cvr_intake_duration_decimal(float $seconds): string
{
    if ($seconds <= 0) {
        return '—';
    }
    return number_format(round($seconds / 3600.0, 1), 1, '.', '');
}

function cvr_intake_duration_hms(float $seconds): string
{
    $total = max(0, (int)round($seconds));
    return sprintf(
        '%02d:%02d:%02d',
        intdiv($total, 3600),
        intdiv($total % 3600, 60),
        $total % 60
    );
}

/** @return array{label:string,class:string} */
function cvr_intake_audio_input_pill(mixed $inputDevice): array
{
    $device = strtolower(trim((string)$inputDevice));
    if ($device !== ''
        && (str_contains($device, 'usb') || str_contains($device, 'usbc') || str_contains($device, 'usb-c') || str_contains($device, 'external'))
        && !str_contains($device, 'iphone')) {
        return array('label' => 'USB-C Mic', 'class' => 'intake-input-good');
    }
    return array('label' => 'iPhone Mic', 'class' => 'intake-input-bad');
}

function cvr_intake_audio_relevant_error(mixed $error): string
{
    $text = trim((string)$error);
    if ($text === '') {
        return '';
    }
    $lower = strtolower($text);
    foreach (array(
        'g3x',
        'garmin',
        'reconstruction',
        'replay',
        'ads-b',
        'adsb',
        'samples inside the recording time window',
    ) as $needle) {
        if (str_contains($lower, $needle)) {
            return '';
        }
    }
    return $text;
}

function cvr_intake_audio_error_display(mixed $error, int $maxLength = 52): string
{
    $text = cvr_intake_audio_relevant_error($error);
    if ($text === '') {
        return '';
    }
    if (strlen($text) <= $maxLength) {
        return $text;
    }
    $truncated = rtrim(substr($text, 0, $maxLength));
    return $truncated . '...';
}

function cvr_intake_audio_info_tooltip(array $row): string
{
    $lines = array();
    $uid = trim((string)($row['recording_uid'] ?? ''));
    if ($uid !== '') {
        $lines[] = 'Recording ID: ' . $uid;
    }
    $session = trim((string)($row['session_uuid'] ?? ''));
    if ($session !== '') {
        $lines[] = 'Session: ' . $session;
    }
    $filename = trim((string)($row['original_filename'] ?? ''));
    $size = cvr_intake_bytes($row['file_size_bytes'] ?? 0);
    if ($filename !== '') {
        $lines[] = 'File: ' . $filename . ' (' . $size . ')';
    } elseif ($size !== '—') {
        $lines[] = 'File size: ' . $size;
    }
    return $lines === array() ? 'No recording details' : implode("\n", $lines);
}

function cvr_debrief_time(mixed $value, string $timezone = 'UTC'): string
{
    return cw_logbook_time(trim((string)$value), $timezone);
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

/** @return array{student:string,instructor:string} */
function cvr_debrief_people(mixed $value): array
{
    $crew = is_array($value) ? $value : json_decode((string)$value, true);
    $people = array('student' => '—', 'instructor' => '—');
    foreach (is_array($crew) ? $crew : array() as $member) {
        if (!is_array($member)) {
            continue;
        }
        $name = trim((string)($member['personName'] ?? $member['person_name'] ?? $member['name'] ?? ''));
        $role = strtolower(trim((string)($member['role'] ?? $member['crew_role'] ?? '')));
        if ($name === '') {
            continue;
        }
        if (str_contains($role, 'student')) {
            $people['student'] = $name;
        } elseif (str_contains($role, 'instructor') || str_contains($role, 'supervisor')) {
            $people['instructor'] = $name;
        }
    }
    return $people;
}

function cvr_debrief_area(string $rubricItemId): string
{
    $prefix = strtolower(strtok($rubricItemId, '.') ?: '');
    return array(
        'preflight' => 'Preflight Preparation',
        'procedures' => 'Preflight Procedures',
        'airport' => 'Airport Operations',
        'takeoff' => 'Takeoff, Landing and Go-Around',
        'landing' => 'Takeoff, Landing and Go-Around',
        'maneuvers' => 'Performance and Ground Reference Maneuvers',
        'navigation' => 'Navigation',
        'stalls' => 'Slow Flight and Stalls',
        'instrument' => 'Basic Instrument Maneuvers',
        'emergency' => 'Emergency Operations',
        'postflight' => 'Postflight Operations',
    )[$prefix] ?? 'Scenario Tasks';
}

function cvr_debrief_evidence_label(mixed $reference): string
{
    if (!is_array($reference)) {
        return trim((string)$reference);
    }
    $type = ucfirst(str_replace('_', ' ', (string)($reference['type'] ?? 'Evidence')));
    $parts = array();
    foreach (array('time', 'timestamp', 'time_range', 'offset', 'chunk', 'title', 'description', 'claim', 'text') as $key) {
        if (!array_key_exists($key, $reference) || is_array($reference[$key])) {
            continue;
        }
        $value = trim((string)$reference[$key]);
        if ($value !== '') {
            $parts[] = $key === 'chunk' ? 'Chunk ' . $value : $value;
        }
    }
    return $type . ($parts === array() ? '' : ' · ' . implode(' · ', array_slice($parts, 0, 3)));
}

function cvr_debrief_overall_label(string $grade): string
{
    return array(
        'RED' => 'Unsatisfactory',
        'YELLOW' => 'To Be Improved',
        'GREEN' => 'Normal Progress',
        'BLUE' => 'Very Good Progress',
        'INCOMPLETE' => 'Incomplete',
        'PENDING INSTRUCTOR REVIEW' => 'Pending Review',
    )[strtoupper($grade)] ?? ucwords(strtolower($grade));
}

function cvr_debrief_segment_label(string $title, int $index): string
{
    $title = trim($title);
    while ($title !== '' && preg_match('/^[A-Z]\.\s*/', $title)) {
        $title = preg_replace('/^[A-Z]\.\s*/', '', $title, 1) ?? $title;
        $title = trim($title);
    }
    $title = trim($title, " \t\n\r\0\x0B.-");
    if ($title === '') {
        $title = 'Flight Segment';
    }
    return ($index < 26 ? chr(65 + $index) . '. ' : '') . $title;
}

function cvr_debrief_hours(mixed $milliseconds, int $precision = 1): string
{
    if (!is_numeric($milliseconds)) {
        return '—';
    }
    return number_format(max(0, (float)$milliseconds) / 3600000, $precision);
}

function cvr_debrief_proposed_value(array $values, array $keys, mixed $fallback = '—'): mixed
{
    foreach ($keys as $key) {
        if (array_key_exists($key, $values) && $values[$key] !== '' && $values[$key] !== null) {
            return $values[$key];
        }
    }
    return $fallback;
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
.intake-panel-head{display:flex;justify-content:space-between;gap:12px;align-items:center;margin-bottom:12px;flex-wrap:wrap}
.intake-audio-filter{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.intake-audio-toggle{border:1px solid #cbd5e1;background:#fff;color:#334155;border-radius:999px;padding:7px 12px;font-size:11px;font-weight:850;cursor:pointer}
.intake-audio-toggle.is-on{background:#1d4ed8;color:#fff;border-color:#1d4ed8}
.intake-audio-table[data-hide-short="true"] tr.intake-audio-row-short{display:none}
.intake-audio-table.intake-table-wrap{overflow-x:hidden}
.intake-table-audio{table-layout:fixed;width:100%;min-width:0;font-size:10px}
.intake-table-audio col.intake-col-received{width:133px}
.intake-table-audio col.intake-col-source{width:6%}
.intake-table-audio col.intake-col-info{width:32px}
.intake-table-audio col.intake-col-aircraft{width:4.5%}
.intake-table-audio col.intake-col-crew{width:15%}
.intake-table-audio col.intake-col-mission{width:5%}
.intake-table-audio col.intake-col-startstop{width:9%}
.intake-table-audio col.intake-col-duration{width:4%}
.intake-table-audio col.intake-col-input{width:11%}
.intake-table-audio col.intake-col-upload{width:5.5%}
.intake-table-audio col.intake-col-transcript{width:10%}
.intake-table-audio col.intake-col-view{width:7.5%}
.intake-table-audio col.intake-col-error{width:7.5%}
.intake-table-audio th{font-size:8px;padding:7px 8px}
.intake-table-audio td{padding:7px 8px;font-size:10px;white-space:nowrap}
.intake-table-audio th.intake-col-duration-h,
.intake-table-audio td.intake-audio-duration{text-align:center}
.intake-table-audio td.intake-audio-info-cell{padding-left:2px;padding-right:2px;text-align:center;overflow:visible}
.intake-audio-crew{font-size:10px;line-height:1.35;white-space:normal;min-width:221px}
.intake-audio-crew-line{color:#334155}
.intake-audio-crew-role{font-weight:800;color:#0f172a}
.intake-audio-mission{font-size:10px;font-weight:800;font-variant-numeric:tabular-nums;color:#0f172a}
.intake-audio-input-mix{display:grid;gap:3px}
.intake-audio-input-detail{font-size:9px;color:#64748b;font-weight:700;white-space:nowrap}
.intake-audio-transcript-head{display:flex;align-items:center;gap:6px}
.intake-audio-transcript-progress{font-size:10px;color:#64748b;font-weight:700;font-variant-numeric:tabular-nums}
.intake-audio-received{font-size:10px;font-weight:700;color:#0f172a;white-space:nowrap}
.intake-audio-start-stop{font-size:10px;font-variant-numeric:tabular-nums;color:#1e3a8a;line-height:1.35;white-space:nowrap}
.intake-audio-start-line{font-weight:700}
.intake-audio-stop-line{font-weight:700;color:#475569}
.intake-audio-duration{font-size:10px;font-variant-numeric:tabular-nums;font-weight:800;color:#0f172a}
.intake-input-pill{display:inline-flex;border-radius:999px;padding:3px 8px;font-size:9px;font-weight:900;white-space:nowrap}
.intake-input-good{background:#dcfce7;color:#166534}
.intake-input-bad{background:#fee2e2;color:#991b1b}
.intake-info-tip{position:relative;display:inline-flex;align-items:center;justify-content:center;width:22px;height:22px;border:1px solid #cbd5e1;border-radius:999px;background:#f8fafc;color:#475569;font-size:11px;font-weight:900;cursor:help;vertical-align:middle}
.intake-info-tip:hover,.intake-info-tip:focus-visible{border-color:#93c5fd;color:#1d4ed8;outline:none}
.intake-info-popover{display:none}
.intake-info-popover-float{position:fixed;z-index:2000;min-width:240px;max-width:320px;padding:10px 12px;border:1px solid #cbd5e1;border-radius:10px;background:#0f172a;color:#f8fafc;font-size:10px;line-height:1.5;white-space:pre-wrap;box-shadow:0 10px 24px rgba(15,23,42,.18);font-weight:500;text-align:left;pointer-events:none}
.intake-info-popover-float[hidden]{display:none}
.intake-audio-transcript-btn{border:1px solid #cbd5e1;border-radius:999px;background:#fff;color:#1d4ed8;padding:3px 8px;font-size:9px;font-weight:900;cursor:pointer;margin-top:4px}
.intake-audio-transcript-btn:disabled{opacity:.45;cursor:not-allowed;color:#64748b}
.intake-modal-backdrop{position:fixed;inset:0;background:rgba(15,23,42,.55);display:grid;place-items:center;padding:20px;z-index:1200}
.intake-modal-backdrop[hidden]{display:none}
.intake-modal{width:min(920px,100%);max-height:90vh;overflow:auto;background:#fff;border-radius:16px;border:1px solid #dbe3ee;box-shadow:0 24px 60px rgba(15,23,42,.22)}
.intake-modal-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;padding:14px 16px;border-bottom:1px solid #e2e8f0}
.intake-modal-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.intake-modal-reprocess{border:1px solid #fbbf24;border-radius:9px;background:#fffbeb;color:#92400e;padding:7px 10px;font-size:11px;font-weight:800;cursor:pointer}
.intake-modal-reprocess:disabled{opacity:.55;cursor:not-allowed}
.intake-modal-note{margin:0 0 8px;color:#64748b;font-size:11px;line-height:1.45}
.intake-modal-title{margin:0;font-size:16px;color:#0f172a}
.intake-modal-body{display:grid;gap:14px;padding:16px}
.intake-modal-audio{width:100%}
.intake-modal-transcript{max-height:48vh;overflow:auto;border:1px solid #e2e8f0;border-radius:12px;padding:12px;background:#f8fafc;font-size:12px;line-height:1.6;white-space:pre-wrap;color:#0f172a}
.intake-modal-close{border:1px solid #cbd5e1;border-radius:9px;background:#fff;color:#334155;padding:7px 10px;font-size:11px;font-weight:800;cursor:pointer}
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
.intake-source-manual{background:#fef3c7;color:#92400e}
.intake-empty{padding:28px;text-align:center;color:#64748b}
.intake-notice{border:1px solid #fbbf24;background:#fffbeb;color:#92400e;border-radius:12px;padding:12px;font-size:12px}
.intake-error{color:#991b1b;max-width:280px;white-space:normal}
.intake-audio-error{width:120px;max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:10px}
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
.intake-upload-panel{border:1px dashed #cbd5e1;border-radius:12px;padding:14px;margin-bottom:14px;background:#f8fafc}
.intake-upload-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;align-items:end}
.intake-upload-grid .intake-field{margin:0}
.intake-reassign{margin-top:8px}
.intake-reassign summary{cursor:pointer;color:#1d4ed8;font-size:12px;font-weight:700}
.intake-reassign .reconstruction-grid{margin-top:10px}
.intake-select option{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;font-size:11px}
.intake-code{display:inline-flex;margin-top:10px;border:1px solid #86efac;border-radius:10px;background:#f0fdf4;color:#166534;padding:10px 12px;font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;font-size:18px;font-weight:900;letter-spacing:.08em}
.debrief-toolbar{display:flex;justify-content:space-between;align-items:center;gap:12px;margin:24px 0 10px}
.debrief-toolbar-actions{display:flex;gap:8px;flex-wrap:wrap}
.debrief-sheet{background:#f8fafc;border:1px solid #dbe3ee;border-radius:22px;padding:18px;box-shadow:0 18px 50px rgba(15,23,42,.09);color:#1e293b}
.debrief-section{background:#fff;border:1px solid #dbe3ee;border-radius:16px;overflow:hidden;margin-top:14px;box-shadow:0 6px 18px rgba(15,23,42,.04)}
.debrief-section-head{padding:11px 14px;background:linear-gradient(135deg,#eff6ff,#f8fafc);border-bottom:1px solid #dbe3ee;font-size:13px;font-weight:900;letter-spacing:.03em;color:#0f3a6d}
.debrief-meta{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:1px;background:#dbe3ee;border:1px solid #dbe3ee;border-radius:16px;overflow:hidden}
.debrief-meta-item{background:#fff;padding:11px 13px;min-width:0}
.debrief-meta-item.wide{grid-column:span 2}
.debrief-label{display:block;color:#64748b;font-size:9px;font-weight:900;text-transform:uppercase;letter-spacing:.08em;margin-bottom:4px}
.debrief-value{display:block;color:#0f172a;font-size:13px;font-weight:800;overflow-wrap:anywhere}
.debrief-overall{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:8px;padding:12px}
.debrief-overall-grade{position:relative;border:1px solid #dbe3ee;border-radius:12px;padding:10px;text-align:center;color:#64748b;background:#fff;font-size:11px;font-weight:800}
.debrief-overall-grade.is-selected{border-width:2px;color:#0f172a;box-shadow:0 5px 12px rgba(15,23,42,.08)}
.debrief-overall-grade.is-selected::before{content:"✓";display:inline-grid;place-items:center;width:18px;height:18px;border-radius:999px;margin-right:5px;background:currentColor;color:#fff;font-size:11px}
.debrief-overall-grade.grade-red.is-selected{border-color:#dc2626;background:#fef2f2;color:#991b1b}
.debrief-overall-grade.grade-yellow.is-selected{border-color:#d97706;background:#fffbeb;color:#92400e}
.debrief-overall-grade.grade-green.is-selected{border-color:#16a34a;background:#f0fdf4;color:#166534}
.debrief-overall-grade.grade-blue.is-selected{border-color:#2563eb;background:#eff6ff;color:#1d4ed8}
.debrief-overall-grade.grade-incomplete.is-selected{border-color:#64748b;background:#f1f5f9;color:#334155}
.debrief-grade-table{width:100%;border-collapse:collapse;font-size:11px}
.debrief-grade-table th{padding:8px 6px;background:#f8fafc;border-bottom:1px solid #dbe3ee;color:#475569;font-size:9px;text-transform:uppercase;letter-spacing:.05em;text-align:center}
.debrief-grade-table th:first-child{text-align:left;padding-left:14px}
.debrief-grade-table td{padding:8px 6px;border-bottom:1px solid #edf2f7;text-align:center;vertical-align:middle}
.debrief-grade-table td:first-child{text-align:left;padding-left:14px}
.debrief-area-row td{padding:8px 14px!important;background:#eaf2fb;color:#153e68!important;font-size:10px;font-weight:900;text-transform:uppercase;letter-spacing:.06em;text-align:left!important}
.debrief-grade-cell{width:38px;color:#cbd5e1;font-weight:900}
.debrief-grade-cell.is-selected{color:#0f3a6d;background:#dbeafe;font-size:14px}
.debrief-required{color:#64748b;font-size:9px;font-weight:800}
.debrief-unassessed{display:inline-flex;border-radius:999px;background:#fef3c7;color:#92400e;padding:4px 7px;font-size:9px;font-weight:900}
.debrief-narrative{padding:14px;line-height:1.65;font-size:13px;color:#334155}
.debrief-chronology{display:grid;gap:10px;padding:14px}
.debrief-flight-segment{border:1px solid #e2e8f0;border-radius:13px;padding:12px;background:#fff}
.debrief-flight-segment h5{margin:0 0 5px;color:#0f172a;font-size:12px}
.debrief-flight-segment p{margin:0;color:#475569;font-size:12px;line-height:1.6}
.debrief-evidence{margin-top:8px}
.debrief-evidence summary{cursor:pointer;color:#2563eb;font-size:10px;font-weight:850}
.debrief-evidence-list{margin:7px 0 0;padding-left:18px;color:#64748b;font-size:10px;line-height:1.55}
.debrief-adjustments{margin-top:14px;border:1px dashed #94a3b8;border-radius:14px;background:#fff}
.debrief-adjustments>summary{cursor:pointer;padding:12px 14px;font-size:11px;font-weight:900;color:#334155}
.debrief-adjustments-body{padding:0 14px 14px}
.debrief-signoff{display:flex;justify-content:space-between;align-items:center;gap:16px;padding:14px;flex-wrap:wrap}
.debrief-signature{color:#166534;font-weight:850;font-size:12px}
.debrief-verify{background:#166534;padding:11px 17px}
.debrief-copy-box{padding:14px}
.debrief-copy-box textarea{width:100%;min-height:300px;resize:vertical;border:1px solid #cbd5e1;border-radius:12px;padding:12px;background:#f8fafc;color:#334155;font:12px/1.6 ui-sans-serif,system-ui,-apple-system,sans-serif}
.debrief-logbook-wrap{overflow:auto}
.debrief-logbook{width:100%;min-width:1050px;border-collapse:collapse;font-size:9px;text-align:center}
.debrief-logbook th{padding:7px 5px;background:#eaf2fb;color:#153e68;border:1px solid #cbd5e1;font-weight:900}
.debrief-logbook td{padding:7px 5px;border:1px solid #dbe3ee;color:#334155}
.debrief-logbook-summary{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:1px;background:#dbe3ee;border-bottom:1px solid #dbe3ee}
.debrief-logbook-summary>div{padding:10px;background:#fff}
.replay-share-card{margin-top:16px;padding:18px;border:1px solid #bfdbfe;border-radius:16px;background:linear-gradient(135deg,#eff6ff,#fff);box-shadow:0 8px 24px rgba(30,64,175,.07)}
.replay-share-head{display:flex;align-items:start;justify-content:space-between;gap:14px;flex-wrap:wrap}
.replay-share-head h4{margin:0;color:#173a66;font-size:15px}
.replay-share-grid{display:grid;grid-template-columns:minmax(0,1fr) 170px;gap:10px;margin-top:14px}
.replay-share-field{display:grid;gap:5px}
.replay-share-field span{color:#64748b;font-size:9px;font-weight:900;text-transform:uppercase;letter-spacing:.07em}
.replay-share-value{width:100%;border:1px solid #bfcee0;border-radius:10px;padding:10px;background:#fff;color:#172033;font:12px/1.3 ui-monospace,SFMono-Regular,Menlo,monospace}
.replay-share-passcode{font-size:17px;font-weight:900;letter-spacing:.13em;text-align:center}
.replay-share-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}
@media(max-width:720px){.intake-card{padding:12px}.intake-title{font-size:22px}.reconstruction-grid{grid-template-columns:1fr}}
@media(max-width:720px){.replay-share-grid{grid-template-columns:1fr}}
@media(max-width:860px){.debrief-meta{grid-template-columns:repeat(2,minmax(0,1fr))}.debrief-meta-item.wide{grid-column:span 1}.debrief-overall{grid-template-columns:1fr}.debrief-sheet{padding:10px;border-radius:16px}.debrief-grade-table{min-width:640px}.debrief-section{overflow:auto}}
@media print{
  @page{size:A4 portrait;margin:11mm}
  body{background:#fff!important;color:#000!important}
  .app-sidebar,.app-topbar,.app-mobile-overlay,.no-print,.intake-page>.intake-card,.intake-panel[data-intake-panel="reconstruction"]>.intake-panel-head,.intake-panel[data-intake-panel="reconstruction"]>.intake-notice,.intake-panel[data-intake-panel="reconstruction"]>.reconstruction-grid,.intake-panel[data-intake-panel="reconstruction"]>.intake-table-wrap{display:none!important}
  .app-shell,.app-main-shell,.app-main,.app-content,.intake-page,.intake-panel[data-intake-panel="reconstruction"]{display:block!important;width:100%!important;max-width:none!important;margin:0!important;padding:0!important;border:0!important;box-shadow:none!important;background:#fff!important}
  .intake-page>.intake-panel[data-intake-panel="reconstruction"]{display:block!important}
  .debrief-toolbar{display:none!important}
  .debrief-sheet{display:block!important;border:0!important;border-radius:0!important;box-shadow:none!important;padding:0!important;background:#fff!important}
  .debrief-section,.debrief-meta{border-color:#94a3b8!important;border-radius:8px!important;box-shadow:none!important;break-inside:avoid}
  .debrief-section-head,.debrief-area-row td{background:#eef2f7!important;-webkit-print-color-adjust:exact;print-color-adjust:exact}
  .debrief-grade-table{font-size:9px}
  .debrief-grade-table thead{display:table-header-group}
  .debrief-grade-table tr{break-inside:avoid}
  .debrief-grade-cell.is-selected,.debrief-overall-grade.is-selected{background:#e5e7eb!important;-webkit-print-color-adjust:exact;print-color-adjust:exact}
  .debrief-adjustments,.debrief-evidence{display:none!important}
  .debrief-copy-box{display:none!important}
  .debrief-logbook{font-size:7.5px;min-width:0}
  .debrief-logbook-summary{grid-template-columns:repeat(3,minmax(0,1fr))}
  .debrief-flight-segment{break-inside:avoid;box-shadow:none}
}
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
        Cockpit Audio <span class="intake-count" data-audio-tab-count><?= $audioVisibleRowCount ?></span>
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
          <thead><tr><th>Received (LT)</th><th>Dispatch</th><th>Flight Record</th><th>Aircraft</th><th>Mission</th><th>Crew</th><th>OFF Block</th><th>ON Block</th><th>Source</th><th>Device</th><th>Status</th><th>Server Receipt</th><th>Error</th></tr></thead>
          <tbody>
          <?php foreach ($dispatch['rows'] as $row): ?>
            <?php $tail = (string)($row['aircraft_registration'] ?? ''); ?>
            <tr>
              <td><?= cvr_intake_h(cvr_intake_local_datetime($pdo, $row['received_at'] ?? null, $tail)) ?></td>
              <td><div class="intake-primary intake-mono"><?= cvr_intake_h($row['dispatch_uuid'] ?? '—') ?></div><div class="intake-muted">Version <?= cvr_intake_h($row['dispatch_version'] ?? '1') ?></div></td>
              <td class="intake-mono"><?= cvr_intake_h($row['workflow_flight_record_uuid'] ?: '—') ?></td>
              <td class="intake-primary"><?= cvr_intake_h($row['aircraft_registration'] ?: '—') ?></td>
              <td><?= cvr_intake_h($row['mission_code'] ?: '—') ?></td>
              <td><?= cvr_intake_h(cvr_intake_crew($row['crew_json'] ?? null)) ?></td>
              <td><?= cvr_intake_h(cvr_intake_local_time($pdo, $row['off_block_utc'] ?? null, $tail)) ?></td>
              <td><?= cvr_intake_h(cvr_intake_local_time($pdo, $row['on_block_utc'] ?? null, $tail)) ?></td>
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
      <?php if ($audio['available'] && $audio['rows'] !== array() && $audioShortRowCount > 0): ?>
        <div class="intake-audio-filter">
          <span class="intake-muted" data-audio-summary>
            Showing <?= (int)$audioVisibleRowCount ?> of <?= count($audio['rows']) ?> recordings
            (<?= (int)$audioShortRowCount ?> under 10 min hidden)
          </span>
          <button
            class="intake-audio-toggle"
            type="button"
            data-audio-short-toggle
            aria-pressed="false"
            title="Short recordings are usually power-ups and are hidden by default"
          >
            Short recordings (&lt; 10 min): OFF
          </button>
        </div>
      <?php endif; ?>
    </div>
    <div class="intake-upload-panel">
      <div class="intake-muted" style="margin-bottom:10px">Manual Upload Audio — use when a recording is missing from intake. Times are interpreted in the aircraft&apos;s operational timezone (California local for IPCA fleet).</div>
      <form method="post" action="/admin/master_logbook.php?tab=audio" enctype="multipart/form-data">
        <input type="hidden" name="action" value="upload_manual_audio">
        <input type="hidden" name="csrf_token" value="<?= cvr_intake_h($reconstructionCsrf) ?>">
        <div class="intake-upload-grid">
          <label class="intake-field"><span>Aircraft</span>
            <select class="intake-select" name="aircraft_id" required>
              <option value="">Select aircraft</option>
              <?php foreach ($aircraftOptions as $aircraftOption): ?>
                <option value="<?= (int)($aircraftOption['id'] ?? 0) ?>"><?= cvr_intake_h($aircraftOption['registration'] ?? '') ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label class="intake-field"><span>Recording start (local)</span><input class="intake-select" type="datetime-local" name="started_at_local" required></label>
          <label class="intake-field"><span>Duration (seconds, optional)</span><input class="intake-select" type="number" min="0" step="1" name="duration_seconds" placeholder="Auto if unknown"></label>
          <label class="intake-field"><span>Student name</span><input class="intake-select" type="text" name="student_name" placeholder="Required for manual intake"></label>
          <label class="intake-field"><span>Instructor name</span><input class="intake-select" type="text" name="instructor_name" placeholder="Required for manual intake"></label>
          <label class="intake-field"><span>Mission code</span><input class="intake-select" type="text" name="mission_code" placeholder="e.g. 1-2-5"></label>
          <label class="intake-field"><span>Audio file</span><input class="intake-select" type="file" name="cockpit_audio" accept=".m4a,.mp4,.wav,.aac,audio/*" required></label>
          <div><button class="intake-button" type="submit">Upload Cockpit Audio</button></div>
        </div>
      </form>
    </div>
    <?php if (!$audio['available']): ?>
      <div class="intake-notice"><?= cvr_intake_h($audio['message']) ?></div>
    <?php elseif ($audio['rows'] === array()): ?>
      <div class="intake-empty">No Cockpit Audio recordings have been received.</div>
    <?php else: ?>
      <div class="intake-table-wrap intake-audio-table" data-audio-table data-hide-short="true" data-audio-total="<?= count($audio['rows']) ?>" data-audio-short="<?= (int)$audioShortRowCount ?>">
        <table class="intake-table intake-table-audio">
          <colgroup>
            <col class="intake-col-received">
            <col class="intake-col-source">
            <col class="intake-col-info">
            <col class="intake-col-aircraft">
            <col class="intake-col-crew">
            <col class="intake-col-mission">
            <col class="intake-col-startstop">
            <col class="intake-col-duration">
            <col class="intake-col-input">
            <col class="intake-col-upload">
            <col class="intake-col-transcript">
            <col class="intake-col-view">
            <col class="intake-col-error">
          </colgroup>
          <thead><tr><th>Received</th><th>Source</th><th></th><th>Aircraft</th><th>Crew</th><th>Mission</th><th>Start/Stop</th><th class="intake-col-duration-h">Duration</th><th>Input</th><th>Upload</th><th>Transcript</th><th>View Transcript</th><th>Error</th></tr></thead>
          <tbody>
          <?php foreach ($audio['rows'] as $row): ?>
            <?php
              $transcriptionProgress = max(0, min(100, (int)($row['transcription_progress'] ?? 0)));
              $tail = (string)($row['aircraft_registration'] ?? '');
              $durationSeconds = (float)($row['duration_seconds'] ?? 0);
              $isShortRecording = $durationSeconds > 0 && $durationSeconds < $audioShortThresholdSeconds;
              $inputMix = is_array($row['intake_input_mix'] ?? null) ? $row['intake_input_mix'] : array();
              $inputMixClass = (($inputMix['dominant'] ?? '') === 'usb') ? 'intake-input-good' : 'intake-input-bad';
              $audioError = cvr_intake_audio_error_display($row['error_message'] ?? '');
              $audioErrorFull = cvr_intake_audio_relevant_error($row['error_message'] ?? '');
              $recordingId = (int)($row['id'] ?? 0);
              $transcriptionStatus = strtolower(trim((string)($row['transcription_status'] ?? '')));
              $canOpenTranscript = $recordingId > 0 && $transcriptionStatus === 'ready';
              $infoTooltip = cvr_intake_audio_info_tooltip($row);
              $crewLines = is_array($row['intake_crew_lines'] ?? null) ? $row['intake_crew_lines'] : array();
              $missionCode = trim((string)($row['intake_mission_code'] ?? ''));
              $sourceLabel = trim((string)($row['intake_source_label'] ?? 'MANUAL'));
              $sourceClass = trim((string)($row['intake_source_class'] ?? 'intake-source-manual'));
            ?>
            <tr class="<?= $isShortRecording ? 'intake-audio-row-short' : '' ?>" data-audio-duration-seconds="<?= cvr_intake_h((string)$durationSeconds) ?>">
              <td class="intake-audio-received"><?= cvr_intake_h(cvr_intake_audio_received_label($pdo, $row['received_at'] ?? $row['created_at'] ?? null, $tail)) ?></td>
              <td><span class="intake-status <?= cvr_intake_h($sourceClass) ?>"><?= cvr_intake_h($sourceLabel) ?></span></td>
              <td class="intake-audio-info-cell">
                <span class="intake-info-tip" tabindex="0" aria-label="Recording details" data-intake-info-tip>
                  i
                  <span class="intake-info-popover"><?= cvr_intake_h($infoTooltip) ?></span>
                </span>
              </td>
              <td class="intake-primary"><?= cvr_intake_h($row['aircraft_registration'] ?: '—') ?></td>
              <td class="intake-audio-crew">
                <?php if ($crewLines === array()): ?>
                  —
                <?php else: ?>
                  <?php foreach ($crewLines as $crewLine): ?>
                    <div class="intake-audio-crew-line"><strong class="intake-audio-crew-role"><?= cvr_intake_h((string)($crewLine['role'] ?? 'Crew')) ?></strong>: <?= cvr_intake_h((string)($crewLine['name'] ?? '')) ?></div>
                  <?php endforeach; ?>
                <?php endif; ?>
              </td>
              <td class="intake-audio-mission"><?= $missionCode !== '' ? cvr_intake_h($missionCode) : '—' ?></td>
              <td class="intake-audio-start-stop">
                <div class="intake-audio-start-line"><?= cvr_intake_h(cvr_intake_audio_start_label($pdo, $row['started_at'] ?? null, $tail)) ?></div>
                <div class="intake-audio-stop-line"><?= cvr_intake_h(cvr_intake_audio_stop_label($pdo, $row['started_at'] ?? null, $durationSeconds, $tail)) ?></div>
              </td>
              <td class="intake-audio-duration"><?= $durationSeconds > 0 ? cvr_intake_h(cvr_intake_duration_decimal($durationSeconds)) : '—' ?></td>
              <td>
                <div class="intake-audio-input-mix">
                  <span class="intake-input-pill <?= cvr_intake_h($inputMixClass) ?>"><?= cvr_intake_h((string)($inputMix['label'] ?? 'iPhone Mic')) ?></span>
                  <span class="intake-audio-input-detail"><?= cvr_intake_h((string)($inputMix['detail'] ?? 'USB 0% · iPhone 100%')) ?></span>
                </div>
              </td>
              <td><?= cvr_intake_badge($row['upload_status'] ?? '') ?></td>
              <td data-audio-recording-id="<?= $recordingId ?>">
                <div class="intake-audio-transcript-head">
                  <?php
                    $transcriptStatusText = trim((string)($row['transcription_status'] ?? ''));
                    if ($transcriptStatusText === '') {
                        $transcriptStatusText = 'Unknown';
                    }
                  ?>
                  <span class="intake-status <?= cvr_intake_h(cvr_intake_status_class($transcriptStatusText)) ?>" data-audio-transcription-status><?= cvr_intake_h(strtoupper(str_replace('_', ' ', $transcriptStatusText))) ?></span>
                  <span class="intake-audio-transcript-progress" data-audio-transcription-progress><?= $transcriptionProgress ?>%</span>
                </div>
                <div class="intake-progress-bar" style="margin-top:4px"><div class="intake-progress-fill" data-audio-transcription-fill style="width:<?= $transcriptionProgress ?>%"></div></div>
              </td>
              <td>
                <button
                  class="intake-audio-transcript-btn"
                  type="button"
                  data-audio-transcript-open
                  data-recording-id="<?= $recordingId ?>"
                  <?= $canOpenTranscript ? '' : 'disabled' ?>
                >View Transcript</button>
              </td>
              <td class="intake-error intake-audio-error" data-audio-error-cell<?= $audioErrorFull !== '' ? ' title="' . cvr_intake_h($audioErrorFull) . '"' : '' ?>><?= $audioError !== '' ? cvr_intake_h($audioError) : '—' ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php if ($audioVisibleRowCount === 0 && $audioShortRowCount > 0): ?>
        <div class="intake-empty" data-audio-filtered-empty style="margin-top:12px">
          All recordings are under 10 minutes. Turn on <strong>Short recordings (&lt; 10 min)</strong> to view them.
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </section>

  <section class="intake-card intake-panel" role="tabpanel" data-intake-panel="garmin">
    <div class="intake-panel-head">
      <div>
        <h2 class="intake-panel-title">Garmin CSV</h2>
        <div class="intake-muted">CSV evidence received through the CVR App, Automatic IPCA Sync Agent, or manual admin upload. Historical and FlightCircle sources are excluded.</div>
      </div>
    </div>
    <div class="intake-upload-panel">
      <div class="intake-muted" style="margin-bottom:10px">Manual Upload CSV — use when Garmin evidence is missing from intake.</div>
      <form method="post" action="/admin/master_logbook.php?tab=garmin" enctype="multipart/form-data">
        <input type="hidden" name="action" value="upload_manual_garmin_csv">
        <input type="hidden" name="csrf_token" value="<?= cvr_intake_h($reconstructionCsrf) ?>">
        <div class="intake-upload-grid">
          <label class="intake-field"><span>Aircraft</span>
            <select class="intake-select" name="aircraft_id" required>
              <option value="">Select aircraft</option>
              <?php foreach ($aircraftOptions as $aircraftOption): ?>
                <option value="<?= (int)($aircraftOption['id'] ?? 0) ?>"><?= cvr_intake_h($aircraftOption['registration'] ?? '') ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label class="intake-field"><span>Flight Record UUID (optional)</span><input class="intake-select" type="text" name="workflow_flight_record_uuid" placeholder="Link to dispatch flight record"></label>
          <label class="intake-field"><span>Garmin CSV file</span><input class="intake-select" type="file" name="garmin_csv" accept=".csv,text/csv" required></label>
          <div><button class="intake-button" type="submit">Upload Garmin CSV</button></div>
        </div>
      </form>
    </div>
    <?php if (!$garmin['available']): ?>
      <div class="intake-notice"><?= cvr_intake_h($garmin['message']) ?></div>
    <?php elseif ($garmin['rows'] === array()): ?>
      <div class="intake-empty">No current Garmin CSV files have been received.</div>
    <?php else: ?>
      <div class="intake-table-wrap">
        <table class="intake-table">
          <thead><tr><th>Received (LT)</th><th>Source</th><th>Filename</th><th>Linked Record</th><th>Aircraft</th><th>Coverage (LT)</th><th>Size</th><th>SHA-256</th><th>Rows</th><th>Evidence</th><th>Validation</th></tr></thead>
          <tbody>
          <?php foreach ($garmin['rows'] as $row): ?>
            <?php
              $sourceIsSync = ($row['source_label'] ?? '') === 'IPCA SYNC AGENT';
              $sourceIsManual = ($row['source_label'] ?? '') === 'MANUAL UPLOAD';
              $tail = (string)($row['aircraft_registration'] ?? '');
            ?>
            <tr>
              <td><?= cvr_intake_h(cvr_intake_local_datetime($pdo, $row['received_at'] ?? null, $tail)) ?></td>
              <td><span class="intake-status <?= $sourceIsManual ? 'intake-source-manual' : ($sourceIsSync ? 'intake-source-sync' : 'intake-source-app') ?>"><?= cvr_intake_h($row['source_label'] ?? 'CVR APP') ?></span><div class="intake-muted"><?= cvr_intake_h($row['provider_name'] ?: '') ?></div></td>
              <td><div class="intake-primary"><?= cvr_intake_h($row['original_filename'] ?: '—') ?></div><div class="intake-muted intake-mono"><?= cvr_intake_h($row['csv_file_uuid'] ?: '—') ?></div></td>
              <td><div class="intake-mono"><?= cvr_intake_h($row['workflow_flight_record_uuid'] ?: '—') ?></div><div class="intake-muted"><?= !empty($row['session_id']) ? 'Session ' . cvr_intake_h($row['session_id']) : 'No canonical session' ?></div></td>
              <td class="intake-primary"><?= cvr_intake_h($row['aircraft_registration'] ?: '—') ?></td>
              <td><div><?= cvr_intake_h(cvr_intake_local_datetime($pdo, $row['first_valid_sample_utc'] ?? null, $tail)) ?></div><div class="intake-muted">to <?= cvr_intake_h(cvr_intake_local_datetime($pdo, $row['last_valid_sample_utc'] ?? null, $tail)) ?></div></td>
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
              <option value="<?= (int)($row['id'] ?? 0) ?>"><?= cvr_intake_h($intakeDisplay->dispatchOptionLabel($row)) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="intake-field">
          <label for="bundle-audio">Cockpit Audio / Raw Transcript</label>
          <select class="intake-select" id="bundle-audio" name="recording_id" required>
            <option value="">Select Cockpit Audio</option>
            <?php foreach ($audio['rows'] as $row): ?>
              <option value="<?= (int)($row['id'] ?? 0) ?>"><?= cvr_intake_h($intakeDisplay->audioOptionLabel($row)) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="intake-field">
          <label for="bundle-garmin">Garmin CSV Source</label>
          <select class="intake-select" id="bundle-garmin" name="garmin_csv_file_id" required>
            <option value="">Select Garmin CSV</option>
            <?php foreach ($garmin['rows'] as $row): ?>
              <option value="<?= (int)($row['id'] ?? 0) ?>"><?= cvr_intake_h($intakeDisplay->garminOptionLabel($row)) ?></option>
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
              <td><div class="intake-primary intake-mono"><?= cvr_intake_h($bundle['bundle_uuid']) ?></div><div class="intake-muted">Version <?= (int)$bundle['version_number'] ?> · <?= cvr_intake_h(cvr_intake_local_datetime($pdo, $bundle['frozen_at'] ?? null, (string)$bundle['aircraft_registration'])) ?></div></td>
              <td><div class="intake-primary"><?= cvr_intake_h($bundle['aircraft_registration']) ?></div><div class="intake-muted"><?= cvr_intake_h($bundle['mission_code'] ?: 'No mission') ?></div></td>
              <td><div>Dispatch #<?= (int)$bundle['dispatch_id'] ?></div><div>Audio #<?= (int)$bundle['cockpit_recording_id'] ?></div><div>Garmin #<?= (int)$bundle['garmin_csv_file_id'] ?></div><div>ADS-B <?= !empty($bundle['adsb_enrichment_id']) ? '#' . (int)$bundle['adsb_enrichment_id'] : 'not linked' ?></div></td>
              <td class="intake-mono" title="<?= cvr_intake_h($bundle['manifest_sha256']) ?>"><?= cvr_intake_h(cvr_intake_short_hash($bundle['manifest_sha256'])) ?></td>
              <td><?= cvr_intake_badge($bundle['transcription_status'] ?? '') ?><div class="intake-muted"><?= cvr_intake_h($transcriptGate['ready'] ? 'Version locked' : $transcriptGate['reason']) ?></div></td>
              <td>
                <?= cvr_intake_badge($bundle['latest_job_status'] ?? $bundle['replay_status'] ?? '') ?>
                <div class="intake-muted"><?= !empty($bundle['latest_job_id']) ? 'Job #' . (int)$bundle['latest_job_id'] . ' · ' . (int)($bundle['latest_job_progress'] ?? 0) . '%' : '' ?></div>
                <div class="intake-muted"><?= cvr_intake_h($bundle['latest_job_message'] ?? '') ?></div>
              </td>
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
                <?php if (in_array((string)($bundle['status'] ?? ''), array('reconstruction_ready', 'reconstruction_complete'), true)): ?>
                  <?php $hasFlightRecordVersion = (int)($bundle['operational_flight_record_version_id'] ?? 0) > 0; ?>
                  <form method="post" action="/admin/master_logbook.php?tab=reconstruction" style="margin-top:6px">
                    <input type="hidden" name="action" value="rebuild_bundle_flight_record">
                    <input type="hidden" name="csrf_token" value="<?= cvr_intake_h($reconstructionCsrf) ?>">
                    <input type="hidden" name="bundle_id" value="<?= (int)$bundle['id'] ?>">
                    <button class="intake-button" type="submit" title="<?= cvr_intake_h($hasFlightRecordVersion ? 'Creates a new Flight Record version from the frozen Garmin CSV. Regenerate Debrief afterward to refresh logbook fields.' : 'Derives the canonical Flight Record from the frozen Garmin CSV.') ?>"><?= $hasFlightRecordVersion ? 'Re-derive Flight Record' : 'Rebuild Flight Record' ?></button>
                  </form>
                <?php endif; ?>
                <?php $debriefJobRunning = in_array((string)($bundle['debrief_job_status'] ?? ''), array('pending','claimed','running','retry_wait'), true); ?>
                <form method="post" action="/admin/api/manual_bundle_debrief.php" style="margin-top:6px">
                  <input type="hidden" name="action" value="generate_bundle_debrief">
                  <input type="hidden" name="csrf_token" value="<?= cvr_intake_h($reconstructionCsrf) ?>">
                  <input type="hidden" name="bundle_id" value="<?= (int)$bundle['id'] ?>">
                  <button class="intake-button" type="submit" <?= (!$transcriptGate['ready'] || $debriefJobRunning) ? 'disabled title="' . cvr_intake_h($debriefJobRunning ? 'Debrief generation is running.' : $transcriptGate['reason']) . '"' : '' ?>><?= $debriefJobRunning ? 'Generating Debrief…' : ($latestDebrief ? 'Regenerate Debrief' : 'Generate Debrief') ?></button>
                </form>
                <?php if (!empty($bundle['debrief_job_id'])): ?>
                  <div class="intake-muted" style="margin-top:4px">Debrief job #<?= (int)$bundle['debrief_job_id'] ?> · <?= cvr_intake_h(strtoupper((string)$bundle['debrief_job_status'])) ?></div>
                <?php endif; ?>
                <?php if (!empty($bundle['debrief_job_error'])): ?>
                  <div class="intake-error" style="margin-top:4px"><?= cvr_intake_h($bundle['debrief_job_error']) ?></div>
                <?php endif; ?>
                <?php if (is_array($latestDebrief)): ?>
                  <a class="intake-refresh" style="margin-top:6px" href="/admin/master_logbook.php?tab=reconstruction&debrief_id=<?= (int)$latestDebrief['id'] ?>">Review Debrief · <?= cvr_intake_h($latestDebrief['suggested_overall']) ?></a>
                <?php endif; ?>
                <details class="intake-reassign">
                  <summary>Reassign sources &amp; create superseding bundle</summary>
                  <form method="post" action="/admin/master_logbook.php?tab=reconstruction">
                    <input type="hidden" name="action" value="supersede_reconstruction_bundle">
                    <input type="hidden" name="csrf_token" value="<?= cvr_intake_h($reconstructionCsrf) ?>">
                    <input type="hidden" name="bundle_id" value="<?= (int)$bundle['id'] ?>">
                    <div class="reconstruction-grid">
                      <div class="intake-field">
                        <label>Dispatch</label>
                        <select class="intake-select" name="dispatch_id" required>
                          <?php foreach ($dispatch['rows'] as $row): ?>
                            <option value="<?= (int)($row['id'] ?? 0) ?>" <?= (int)($row['id'] ?? 0) === (int)$bundle['dispatch_id'] ? 'selected' : '' ?>><?= cvr_intake_h($intakeDisplay->dispatchOptionLabel($row)) ?></option>
                          <?php endforeach; ?>
                        </select>
                      </div>
                      <div class="intake-field">
                        <label>Cockpit Audio</label>
                        <select class="intake-select" name="recording_id" required>
                          <?php foreach ($audio['rows'] as $row): ?>
                            <option value="<?= (int)($row['id'] ?? 0) ?>" <?= (int)($row['id'] ?? 0) === (int)$bundle['cockpit_recording_id'] ? 'selected' : '' ?>><?= cvr_intake_h($intakeDisplay->audioOptionLabel($row)) ?></option>
                          <?php endforeach; ?>
                        </select>
                      </div>
                      <div class="intake-field">
                        <label>Garmin CSV</label>
                        <select class="intake-select" name="garmin_csv_file_id" required>
                          <?php foreach ($garmin['rows'] as $row): ?>
                            <option value="<?= (int)($row['id'] ?? 0) ?>" <?= (int)($row['id'] ?? 0) === (int)$bundle['garmin_csv_file_id'] ? 'selected' : '' ?>><?= cvr_intake_h($intakeDisplay->garminOptionLabel($row)) ?></option>
                          <?php endforeach; ?>
                        </select>
                      </div>
                      <div class="intake-field">
                        <label style="display:flex;align-items:center;gap:8px;text-transform:none;font-size:12px;color:#334155">
                          <input type="checkbox" name="include_adsb" value="1" <?= !empty($bundle['adsb_enrichment_id']) ? 'checked' : '' ?>>
                          Link ADS-B enrichment for selected audio
                        </label>
                      </div>
                    </div>
                    <div class="reconstruction-actions">
                      <button class="intake-button" type="submit">Create Superseding Bundle</button>
                      <span class="intake-muted">Use this to swap Garmin CSV, audio, or dispatch and reprocess.</span>
                    </div>
                  </form>
                </details>
              </td>
              <td class="intake-error"><?= cvr_intake_h($bundle['processing_error'] ?: ($bundle['latest_job_error'] ?: '—')) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

    <?php if (is_array($selectedDebrief)): ?>
      <?php
        $chronological = json_decode((string)$selectedDebrief['chronological_review_json'], true);
        $context = is_array($selectedDebrief['context'] ?? null) ? $selectedDebrief['context'] : array();
        $people = cvr_debrief_people($context['crew_json'] ?? null);
        $overallOptions = array('RED', 'YELLOW', 'GREEN', 'BLUE', 'INCOMPLETE');
        $effectiveOverall = trim((string)($selectedDebrief['instructor_overall'] ?? '')) ?: (string)$selectedDebrief['suggested_overall'];
        $isApprovedDebrief = in_array((string)$selectedDebrief['status'], array('approved', 'released'), true);
        $canVerifyDebrief = in_array((string)$selectedDebrief['status'], array('ai_draft', 'instructor_draft'), true);
        $taskEvaluations = array_values(array_filter($selectedDebrief['evaluations'], fn(array $row): bool => (string)$row['rubric_type'] === 'task'));
        $srmEvaluations = array_values(array_filter($selectedDebrief['evaluations'], fn(array $row): bool => (string)$row['rubric_type'] === 'srm'));
        $legs = is_array($context['legs'] ?? null) ? $context['legs'] : array();
        $proposal = is_array($context['logbook_proposal'] ?? null) ? $context['logbook_proposal'] : array();
        $proposalValues = is_array($proposal['proposed_values'] ?? null) ? $proposal['proposed_values'] : array();
        $flightRecordSummary = json_decode((string)($context['summary_json'] ?? ''), true);
        $crewReconciliation = is_array($flightRecordSummary['preview']['calculations']['crew_reconciliation'] ?? null)
            ? $flightRecordSummary['preview']['calculations']['crew_reconciliation']
            : array();
        $meterDiscrepancies = is_array($crewReconciliation['discrepancies'] ?? null)
            ? $crewReconciliation['discrepancies']
            : array();
        if (is_array($context['block_time_discrepancies'] ?? null)) {
            $meterDiscrepancies = array_values(array_merge($meterDiscrepancies, $context['block_time_discrepancies']));
        }
        $logbookTimezone = (string)($context['operational_timezone'] ?? 'UTC');
        $durationStart = strtotime((string)($context['engine_start_utc'] ?? $context['garmin_start_utc'] ?? ''));
        $durationEnd = strtotime((string)($context['engine_stop_utc'] ?? $context['garmin_end_utc'] ?? ''));
        $fallbackDurationMs = $durationStart !== false && $durationEnd !== false && $durationEnd >= $durationStart
            ? ($durationEnd - $durationStart) * 1000
            : null;
        $recordDurationMs = $context['exact_hobbs_duration_ms'] ?? $fallbackDurationMs;
        $recordLandingCount = max((int)($context['landing_event_count'] ?? 0), (int)($context['gps_landing_count'] ?? 0));
        $copySections = array();
        foreach (is_array($chronological) ? $chronological : array() as $index => $segment) {
            $copySections[] = cvr_debrief_segment_label((string)($segment['title'] ?? 'Flight Segment'), $index)
                . "\n" . trim((string)($segment['narrative'] ?? ''));
        }
        $copySections[] = "Overall Assessment\n" . trim((string)$selectedDebrief['mission_assessment_text']);
        $copySections[] = "Main Takeaways\n" . trim((string)$selectedDebrief['summary_next_steps_text']);
        $chronologicalCopyText = trim(implode("\n\n", array_filter($copySections)));
        $replayShareReady = $replayShareService->isReady();
        $replayShare = $replayShareReady
            ? $replayShareService->currentForDebrief((int)$selectedDebrief['id'])
            : array();
        $newShareForDebrief = is_array($createdReplayShare)
            && (int)($createdReplayShare['debrief_id'] ?? 0) === (int)$selectedDebrief['id']
            ? $createdReplayShare
            : null;
        $replayShareUrl = $newShareForDebrief !== null
            ? cvr_replay_share_base_url() . '/replay-debrief.php?t=' . rawurlencode((string)$newShareForDebrief['token'])
            : '';
        $replayShareActive = $replayShare !== array()
            && (string)($replayShare['status'] ?? '') === 'active'
            && empty($replayShare['revoked_at'])
            && strtotime((string)($replayShare['expires_at'] ?? '')) > time();
        $replayShareDisplayStatus = $replayShareActive
            ? 'active'
            : ((string)($replayShare['status'] ?? '') === 'revoked' ? 'revoked' : 'expired');
      ?>
      <div class="debrief-toolbar no-print">
        <div>
          <h3 class="intake-panel-title">Debriefing Sheet</h3>
          <div class="intake-muted">Generated from the locked evidence bundle. Review the completed sheet, then verify it.</div>
        </div>
        <div class="debrief-toolbar-actions">
          <?= cvr_intake_badge($selectedDebrief['status']) ?>
          <button class="intake-refresh" type="button" onclick="window.print()">Print / Save as PDF</button>
        </div>
      </div>

      <article class="debrief-sheet">
        <header class="debrief-meta">
          <div class="debrief-meta-item wide"><span class="debrief-label">Customer</span><span class="debrief-value"><?= cvr_intake_h($people['student']) ?></span></div>
          <div class="debrief-meta-item wide"><span class="debrief-label">Instructor</span><span class="debrief-value"><?= cvr_intake_h($people['instructor']) ?></span></div>
          <div class="debrief-meta-item"><span class="debrief-label">Form No.</span><span class="debrief-value">DB-<?= (int)$selectedDebrief['id'] ?></span></div>
          <div class="debrief-meta-item"><span class="debrief-label">Scenario</span><span class="debrief-value"><?= cvr_intake_h(($context['mission_code'] ?? '—') . ' (ACFT)') ?></span></div>
          <div class="debrief-meta-item wide"><span class="debrief-label">Aircraft Type</span><span class="debrief-value"><?= cvr_intake_h($context['aircraft_type'] ?: '—') ?></span></div>
          <div class="debrief-meta-item wide"><span class="debrief-label">Registration</span><span class="debrief-value"><?= cvr_intake_h($context['aircraft_registration'] ?? '—') ?></span></div>
          <div class="debrief-meta-item"><span class="debrief-label">Date</span><span class="debrief-value"><?= cvr_intake_h($context['scheduled_date'] ?? '—') ?></span></div>
          <div class="debrief-meta-item"><span class="debrief-label">Version</span><span class="debrief-value"><?= (int)($context['version_number'] ?? 1) ?></span></div>
        </header>

        <section class="debrief-section">
          <div class="debrief-section-head">Overall Grading</div>
          <div class="debrief-overall">
            <?php foreach ($overallOptions as $grade): ?>
              <div class="debrief-overall-grade grade-<?= strtolower($grade) ?> <?= strtoupper($effectiveOverall) === $grade ? 'is-selected' : '' ?>">
                <?= cvr_intake_h(cvr_debrief_overall_label($grade)) ?>
              </div>
            <?php endforeach; ?>
          </div>
        </section>

        <section class="debrief-section">
          <div class="debrief-section-head">Scenario Grading</div>
          <table class="debrief-grade-table">
            <thead><tr><th>Task Element</th><th>Req.</th><th>DE</th><th>EX</th><th>PR</th><th>PE</th><th>NO</th></tr></thead>
            <tbody>
              <?php $currentArea = ''; ?>
              <?php foreach ($taskEvaluations as $evaluation): ?>
                <?php
                  $area = cvr_debrief_area((string)$evaluation['rubric_item_id']);
                  $effectiveGrade = trim((string)($evaluation['instructor_grade'] ?? '')) ?: trim((string)($evaluation['suggested_grade'] ?? ''));
                  $refs = json_decode((string)$evaluation['evidence_refs_json'], true);
                ?>
                <?php if ($area !== $currentArea): $currentArea = $area; ?>
                  <tr class="debrief-area-row"><td colspan="7"><?= cvr_intake_h($area) ?></td></tr>
                <?php endif; ?>
                <tr>
                  <td>
                    <strong><?= cvr_intake_h($evaluation['title']) ?></strong>
                    <?php if ($effectiveGrade === ''): ?><span class="debrief-unassessed">Insufficient evidence</span><?php endif; ?>
                    <details class="debrief-evidence no-print">
                      <summary>Supporting evidence and rationale</summary>
                      <div class="intake-muted"><?= cvr_intake_h($evaluation['rationale']) ?></div>
                      <?php if (is_array($refs) && $refs !== array()): ?>
                        <ul class="debrief-evidence-list">
                          <?php foreach ($refs as $reference): ?><li><?= cvr_intake_h(cvr_debrief_evidence_label($reference)) ?></li><?php endforeach; ?>
                        </ul>
                      <?php endif; ?>
                    </details>
                  </td>
                  <td class="debrief-required"><?= cvr_intake_h($evaluation['required_standard']) ?></td>
                  <?php foreach (array('DE','EX','PR','PE','NO') as $grade): ?>
                    <td class="debrief-grade-cell <?= $effectiveGrade === $grade ? 'is-selected' : '' ?>"><?= $effectiveGrade === $grade ? 'X' : '·' ?></td>
                  <?php endforeach; ?>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </section>

        <section class="debrief-section">
          <div class="debrief-section-head">Instructor Comments</div>
          <div class="debrief-narrative"><strong>General</strong><br><?= nl2br(cvr_intake_h($selectedDebrief['general_text'])) ?></div>
          <div class="debrief-section-head">Chronological Flight Review</div>
          <div class="debrief-chronology">
            <?php foreach (is_array($chronological) ? $chronological : array() as $segmentIndex => $segment): ?>
              <article class="debrief-flight-segment">
                <h5><?= cvr_intake_h(cvr_debrief_segment_label((string)($segment['title'] ?? 'Flight Segment'), (int)$segmentIndex)) ?></h5>
                <p><?= nl2br(cvr_intake_h($segment['narrative'] ?? '')) ?></p>
                <?php if (!empty($segment['evidence_refs'])): ?>
                  <details class="debrief-evidence no-print">
                    <summary>Supporting evidence</summary>
                    <ul class="debrief-evidence-list">
                      <?php foreach ((array)$segment['evidence_refs'] as $reference): ?><li><?= cvr_intake_h(cvr_debrief_evidence_label($reference)) ?></li><?php endforeach; ?>
                    </ul>
                  </details>
                <?php endif; ?>
              </article>
            <?php endforeach; ?>
          </div>
          <div class="debrief-copy-box no-print">
            <div class="debrief-toolbar-actions" style="justify-content:space-between;margin-bottom:8px">
              <strong>Copy-ready chronological review</strong>
              <button class="intake-refresh" type="button" data-copy-debrief="debrief-copy-<?= (int)$selectedDebrief['id'] ?>">Copy Full Review</button>
            </div>
            <textarea id="debrief-copy-<?= (int)$selectedDebrief['id'] ?>" readonly><?= cvr_intake_h($chronologicalCopyText) ?></textarea>
          </div>
          <div class="debrief-narrative"><strong>Mission Standards Assessment</strong><br><?= nl2br(cvr_intake_h($selectedDebrief['mission_assessment_text'])) ?></div>
          <div class="debrief-narrative"><strong>Summary and Next Steps</strong><br><?= nl2br(cvr_intake_h($selectedDebrief['summary_next_steps_text'])) ?></div>
          <?php if (trim((string)($selectedDebrief['instructor_comments'] ?? '')) !== ''): ?>
            <div class="debrief-narrative"><strong>Instructor Addendum</strong><br><?= nl2br(cvr_intake_h($selectedDebrief['instructor_comments'])) ?></div>
          <?php endif; ?>
        </section>

        <section class="debrief-section">
          <div class="debrief-section-head">Single Pilot Resource Management (SRM) Grading</div>
          <table class="debrief-grade-table">
            <thead><tr><th>SRM Element</th><th>Req.</th><th>EX</th><th>PR</th><th>MD</th><th>NO</th></tr></thead>
            <tbody>
              <?php foreach ($srmEvaluations as $evaluation): ?>
                <?php
                  $effectiveGrade = trim((string)($evaluation['instructor_grade'] ?? '')) ?: trim((string)($evaluation['suggested_grade'] ?? ''));
                  $refs = json_decode((string)$evaluation['evidence_refs_json'], true);
                ?>
                <tr>
                  <td>
                    <strong><?= cvr_intake_h($evaluation['title']) ?></strong>
                    <?php if ($effectiveGrade === ''): ?><span class="debrief-unassessed">Insufficient evidence</span><?php endif; ?>
                    <details class="debrief-evidence no-print">
                      <summary>Supporting evidence and rationale</summary>
                      <div class="intake-muted"><?= cvr_intake_h($evaluation['rationale']) ?></div>
                      <?php if (is_array($refs) && $refs !== array()): ?><ul class="debrief-evidence-list"><?php foreach ($refs as $reference): ?><li><?= cvr_intake_h(cvr_debrief_evidence_label($reference)) ?></li><?php endforeach; ?></ul><?php endif; ?>
                    </details>
                  </td>
                  <td class="debrief-required"><?= cvr_intake_h($evaluation['required_standard']) ?></td>
                  <?php foreach (array('EX','PR','MD','NO') as $grade): ?>
                    <td class="debrief-grade-cell <?= $effectiveGrade === $grade ? 'is-selected' : '' ?>"><?= $effectiveGrade === $grade ? 'X' : '·' ?></td>
                  <?php endforeach; ?>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </section>

        <section class="debrief-section">
          <div class="debrief-section-head">Flight and Logbook Record</div>
          <div class="debrief-logbook-summary">
            <div><span class="debrief-label">OFF Block</span><span class="debrief-value"><?= cvr_intake_h(cvr_intake_timestamp($context['engine_start_utc'] ?? null, $logbookTimezone)) ?></span></div>
            <div><span class="debrief-label">ON Block</span><span class="debrief-value"><?= cvr_intake_h(cvr_intake_timestamp($context['engine_stop_utc'] ?? null, $logbookTimezone)) ?></span></div>
            <div class="no-print" style="grid-column:1 / -1"><span class="intake-muted">Logbook times shown in aircraft operational local time (<?= cvr_intake_h($logbookTimezone) ?>). Source timestamps remain stored in UTC.</span></div>
            <div><span class="debrief-label">Hobbs Start</span><span class="debrief-value"><?= cvr_intake_h($context['hobbs_start_hours'] ?? '—') ?></span></div>
            <div><span class="debrief-label">Hobbs End</span><span class="debrief-value"><?= cvr_intake_h($context['hobbs_end_hours'] ?? '—') ?></span></div>
            <div><span class="debrief-label">Tacho Start</span><span class="debrief-value"><?= cvr_intake_h($context['tacho_start_hours'] ?? '—') ?></span></div>
            <div><span class="debrief-label">Tacho End</span><span class="debrief-value"><?= cvr_intake_h($context['tacho_end_hours'] ?? '—') ?></span></div>
          </div>
          <?php if ($meterDiscrepancies !== array()): ?>
            <div class="intake-notice no-print" style="margin:12px">
              <strong>Meter / fuel reconciliation requires attention</strong>
              <ul style="margin:7px 0 0;padding-left:18px">
                <?php foreach ($meterDiscrepancies as $message): ?><li><?= cvr_intake_h($message) ?></li><?php endforeach; ?>
              </ul>
            </div>
          <?php elseif (!empty($crewReconciliation['available'])): ?>
            <div class="debrief-narrative no-print"><span class="intake-muted">Dispatch Hobbs/Tacho starts lead the logbook. Garmin airframe_hours and engine_hours verify those entries. Crew shutdown endings remain authoritative and were checked against Garmin-derived durations.</span></div>
          <?php endif; ?>
          <div class="debrief-logbook-wrap">
            <table class="debrief-logbook">
              <thead><tr><th>Sect</th><th>DEP</th><th>TIME</th><th>DEST</th><th>TIME</th><th>ACFT</th><th>REG</th><th>SE</th><th>ME</th><th>TOTAL</th><th>LD-D</th><th>LD-N</th><th>NIGHT</th><th>IFR</th><th>FNPT</th><th>PIC</th><th>DUAL</th><th>X-C</th></tr></thead>
              <tbody>
                <?php $logbookLegs = $legs !== array() ? $legs : array(array(
                    'leg_index' => 1,
                    'departure_airport_code' => '',
                    'arrival_airport_code' => '',
                    'departure_utc' => $context['engine_start_utc'] ?? null,
                    'arrival_utc' => $context['engine_stop_utc'] ?? null,
                    'allocated_hobbs_duration_ms' => $recordDurationMs,
                    'night_duration_ms' => $context['total_night_duration_ms'] ?? null,
                    'cross_country_easa_qualified' => $context['cross_country_easa_qualified'] ?? 0,
                    'landing_event_count' => $recordLandingCount,
                )); ?>
                <?php foreach ($logbookLegs as $legIndex => $leg): ?>
                  <?php
                    $durationMs = $leg['allocated_hobbs_duration_ms'] ?? $context['exact_hobbs_duration_ms'] ?? null;
                    $durationHours = cvr_debrief_hours($durationMs);
                    $nightMs = $leg['night_duration_ms'] ?? null;
                    $landingCount = (int)($leg['landing_event_count'] ?? 0);
                    $singleLeg = count($logbookLegs) === 1;
                    $dayLandings = $singleLeg
                        ? cvr_debrief_proposed_value($proposalValues, array('day_landings'), (!is_numeric($nightMs) || (int)$nightMs === 0) && $landingCount > 0 ? $landingCount : '—')
                        : ((!is_numeric($nightMs) || (int)$nightMs === 0) && $landingCount > 0 ? $landingCount : '—');
                    $nightLandings = $singleLeg ? cvr_debrief_proposed_value($proposalValues, array('night_landings'), '—') : '—';
                    $crossCountry = !empty($leg['cross_country_easa_qualified']) || !empty($leg['cross_country_faa_qualified']) ? $durationHours : '—';
                    $departureTime = $legIndex === array_key_first($logbookLegs)
                        ? ($context['engine_start_utc'] ?? $leg['departure_utc'] ?? null)
                        : ($leg['departure_utc'] ?? null);
                    $arrivalTime = $legIndex === array_key_last($logbookLegs)
                        ? ($context['engine_stop_utc'] ?? $leg['arrival_utc'] ?? null)
                        : ($leg['arrival_utc'] ?? null);
                  ?>
                  <tr>
                    <td><?= (int)($leg['leg_index'] ?? 1) ?></td>
                    <td><?= cvr_intake_h($leg['departure_airport_code'] ?: '—') ?></td>
                    <td><?= cvr_intake_h(cvr_debrief_time($departureTime, $logbookTimezone)) ?></td>
                    <td><?= cvr_intake_h($leg['arrival_airport_code'] ?: '—') ?></td>
                    <td><?= cvr_intake_h(cvr_debrief_time($arrivalTime, $logbookTimezone)) ?></td>
                    <td><?= cvr_intake_h($context['aircraft_type'] ?: '—') ?></td>
                    <td><?= cvr_intake_h($context['aircraft_registration'] ?: '—') ?></td>
                    <td><?= cvr_intake_h($singleLeg ? cvr_debrief_proposed_value($proposalValues, array('single_engine_time'), '—') : '—') ?></td>
                    <td><?= cvr_intake_h($singleLeg ? cvr_debrief_proposed_value($proposalValues, array('multi_engine_time'), '—') : '—') ?></td>
                    <td><strong><?= cvr_intake_h($durationHours) ?></strong></td>
                    <td><?= cvr_intake_h($dayLandings) ?></td>
                    <td><?= cvr_intake_h($nightLandings) ?></td>
                    <td><?= cvr_intake_h(cvr_debrief_hours($nightMs)) ?></td>
                    <td><?= cvr_intake_h($singleLeg ? cvr_debrief_proposed_value($proposalValues, array('instrument_time', 'ifr_time'), '—') : '—') ?></td>
                    <td><?= cvr_intake_h($singleLeg ? cvr_debrief_proposed_value($proposalValues, array('fnpt_simulator_time', 'fnpt_time'), '—') : '—') ?></td>
                    <td><?= cvr_intake_h($singleLeg ? cvr_debrief_proposed_value($proposalValues, array('pic_time'), '—') : '—') ?></td>
                    <td><?= cvr_intake_h($singleLeg ? cvr_debrief_proposed_value($proposalValues, array('dual_received_time', 'dual_time'), '—') : '—') ?></td>
                    <td><?= cvr_intake_h($singleLeg ? cvr_debrief_proposed_value($proposalValues, array('cross_country_time'), $crossCountry) : $crossCountry) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <div class="debrief-narrative no-print"><span class="intake-muted">Fields remain blank when the canonical Flight Record or logbook proposal has not supplied authoritative data.</span></div>
        </section>

        <?php if (!$isApprovedDebrief): ?>
          <details class="debrief-adjustments no-print">
            <summary>Adjust generated sheet (optional)</summary>
            <div class="debrief-adjustments-body">
              <form method="post" action="/admin/master_logbook.php?tab=reconstruction&debrief_id=<?= (int)$selectedDebrief['id'] ?>">
                <input type="hidden" name="action" value="save_debrief_review">
                <input type="hidden" name="csrf_token" value="<?= cvr_intake_h($reconstructionCsrf) ?>">
                <input type="hidden" name="debrief_id" value="<?= (int)$selectedDebrief['id'] ?>">
                <div class="intake-table-wrap">
                  <table class="intake-table">
                    <thead><tr><th>Element</th><th>Generated</th><th>Override</th><th>Optional Comment</th></tr></thead>
                    <tbody>
                      <?php foreach ($selectedDebrief['evaluations'] as $evaluation): ?>
                        <?php $scale = (string)$evaluation['rubric_type'] === 'srm' ? array('EX','PR','MD','NO') : array('DE','EX','PR','PE','NO'); ?>
                        <tr>
                          <td class="intake-primary"><?= cvr_intake_h($evaluation['title']) ?></td>
                          <td><?= cvr_intake_badge($evaluation['suggested_grade'] ?: 'insufficient evidence') ?></td>
                          <td>
                            <input type="hidden" name="review[<?= (int)$evaluation['id'] ?>][rubric_type]" value="<?= cvr_intake_h($evaluation['rubric_type']) ?>">
                            <select class="intake-select" name="review[<?= (int)$evaluation['id'] ?>][grade]">
                              <option value="">Use generated result</option>
                              <?php foreach ($scale as $grade): ?><option value="<?= $grade ?>" <?= (string)$evaluation['instructor_grade'] === $grade ? 'selected' : '' ?>><?= $grade ?></option><?php endforeach; ?>
                            </select>
                          </td>
                          <td><input class="intake-select" type="text" name="review[<?= (int)$evaluation['id'] ?>][comment]" value="<?= cvr_intake_h($evaluation['instructor_comment']) ?>"></td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
                <div class="reconstruction-grid" style="margin-top:12px">
                  <label class="intake-field"><span>Overall Override</span><select class="intake-select" name="instructor_overall"><option value="">Use generated result (<?= cvr_intake_h($selectedDebrief['suggested_overall']) ?>)</option><?php foreach ($overallOptions as $option): ?><option value="<?= $option ?>" <?= (string)$selectedDebrief['instructor_overall'] === $option ? 'selected' : '' ?>><?= cvr_intake_h(cvr_debrief_overall_label($option)) ?></option><?php endforeach; ?></select></label>
                  <label class="intake-field"><span>Instructor Addendum</span><textarea class="intake-select" name="instructor_comments" rows="4"><?= cvr_intake_h($selectedDebrief['instructor_comments']) ?></textarea></label>
                </div>
                <div class="reconstruction-actions"><button class="intake-button" type="submit">Save Adjustments</button></div>
              </form>
            </div>
          </details>
        <?php endif; ?>

        <footer class="debrief-section">
          <div class="debrief-signoff">
            <?php if ($isApprovedDebrief): ?>
              <div class="debrief-signature">Verified by instructor on <?= cvr_intake_h(cvr_intake_timestamp($selectedDebrief['approved_at'])) ?></div>
              <?= cvr_intake_badge('Instructor Verified') ?>
            <?php else: ?>
              <div><strong>Instructor Verification</strong><div class="intake-muted">Verification accepts and locks this generated Debriefing Sheet as presented, including any saved adjustments.</div></div>
              <?php if ($canVerifyDebrief): ?>
                <form class="no-print" method="post" action="/admin/master_logbook.php?tab=reconstruction&debrief_id=<?= (int)$selectedDebrief['id'] ?>" onsubmit="return confirm('Verify and lock this Debriefing Sheet as presented?');">
                  <input type="hidden" name="action" value="approve_debrief">
                  <input type="hidden" name="csrf_token" value="<?= cvr_intake_h($reconstructionCsrf) ?>">
                  <input type="hidden" name="debrief_id" value="<?= (int)$selectedDebrief['id'] ?>">
                  <button class="intake-button debrief-verify" type="submit">Verify Debriefing Sheet</button>
                </form>
              <?php endif; ?>
            <?php endif; ?>
          </div>
        </footer>
      </article>

      <section class="replay-share-card no-print" aria-labelledby="replay-share-title">
        <div class="replay-share-head">
          <div>
            <h4 id="replay-share-title">Secure Replay Debrief Link</h4>
            <div class="intake-muted">Shares only this flight’s Cockpit Recorder replay for 12 hours. The viewer must enter the separate passcode and accept the privacy notice.</div>
          </div>
          <?php if ($replayShare !== array()): ?>
            <?= cvr_intake_badge($replayShareDisplayStatus) ?>
          <?php endif; ?>
        </div>

        <?php if (!$replayShareReady): ?>
          <div class="intake-notice" style="margin-top:12px">Install <span class="intake-mono">scripts/sql/2026_07_28_replay_debrief_shares.sql</span> before enabling replay sharing.</div>
        <?php else: ?>
          <?php if ($newShareForDebrief !== null): ?>
            <div class="intake-notice" style="margin-top:12px">Copy both values now. For security, the link token and passcode are not shown again after this page is refreshed.</div>
            <div class="replay-share-grid">
              <label class="replay-share-field">
                <span>Private replay link</span>
                <input id="replay-share-url-<?= (int)$selectedDebrief['id'] ?>" class="replay-share-value" type="text" readonly value="<?= cvr_intake_h($replayShareUrl) ?>">
              </label>
              <label class="replay-share-field">
                <span>Separate passcode</span>
                <input id="replay-share-passcode-<?= (int)$selectedDebrief['id'] ?>" class="replay-share-value replay-share-passcode" type="text" readonly value="<?= cvr_intake_h($newShareForDebrief['passcode']) ?>">
              </label>
            </div>
            <div class="replay-share-actions">
              <button class="intake-refresh" type="button" data-copy-input="replay-share-url-<?= (int)$selectedDebrief['id'] ?>">Copy Link</button>
              <button class="intake-refresh" type="button" data-copy-input="replay-share-passcode-<?= (int)$selectedDebrief['id'] ?>">Copy Passcode</button>
            </div>
          <?php elseif ($replayShareActive): ?>
            <div class="intake-muted" style="margin-top:12px">Active until <?= cvr_intake_h(cvr_intake_timestamp($replayShare['expires_at'])) ?> · <?= (int)($replayShare['view_count'] ?? 0) ?> media requests. Regenerate to issue new credentials and immediately revoke the current link.</div>
          <?php elseif ($replayShare !== array()): ?>
            <div class="intake-muted" style="margin-top:12px">The most recent replay link is expired or revoked. Generate a new link to share this flight again.</div>
          <?php endif; ?>

          <div class="replay-share-actions">
            <form method="post" action="/admin/master_logbook.php?tab=reconstruction&debrief_id=<?= (int)$selectedDebrief['id'] ?>" onsubmit="return confirm('<?= $replayShareActive ? 'Regenerate credentials and immediately revoke the current replay link?' : 'Generate a private replay link valid for 12 hours?' ?>');">
              <input type="hidden" name="action" value="create_replay_share">
              <input type="hidden" name="csrf_token" value="<?= cvr_intake_h($reconstructionCsrf) ?>">
              <input type="hidden" name="debrief_id" value="<?= (int)$selectedDebrief['id'] ?>">
              <button class="intake-button" type="submit"><?= $replayShareActive ? 'Regenerate Link & Passcode' : 'Generate Link & Passcode' ?></button>
            </form>
            <?php if ($replayShareActive): ?>
              <form method="post" action="/admin/master_logbook.php?tab=reconstruction&debrief_id=<?= (int)$selectedDebrief['id'] ?>" onsubmit="return confirm('Revoke this replay link immediately?');">
                <input type="hidden" name="action" value="revoke_replay_share">
                <input type="hidden" name="csrf_token" value="<?= cvr_intake_h($reconstructionCsrf) ?>">
                <input type="hidden" name="debrief_id" value="<?= (int)$selectedDebrief['id'] ?>">
                <button class="intake-refresh" type="submit" style="color:#991b1b;border-color:#fecaca">Revoke Access</button>
              </form>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </section>

      <div class="reconstruction-actions no-print">
        <?php if (!$isApprovedDebrief && (string)$selectedDebrief['status'] !== 'rejected'): ?>
          <details class="debrief-adjustments" style="flex:1">
            <summary>Reject generated sheet</summary>
            <form method="post" action="/admin/master_logbook.php?tab=reconstruction&debrief_id=<?= (int)$selectedDebrief['id'] ?>" style="padding:0 14px 14px" onsubmit="return confirm('Reject this generated sheet?');">
              <input type="hidden" name="action" value="reject_debrief">
              <input type="hidden" name="csrf_token" value="<?= cvr_intake_h($reconstructionCsrf) ?>">
              <input type="hidden" name="debrief_id" value="<?= (int)$selectedDebrief['id'] ?>">
              <label class="intake-field"><span>Reason</span><input class="intake-select" type="text" name="rejection_reason" required></label>
              <button class="intake-button" type="submit" style="margin-top:8px;background:#991b1b">Reject Sheet</button>
            </form>
          </details>
        <?php endif; ?>
        <?php if ((string)$selectedDebrief['status'] === 'approved'): ?>
          <form method="post" action="/admin/master_logbook.php?tab=reconstruction&debrief_id=<?= (int)$selectedDebrief['id'] ?>" onsubmit="return confirm('Release this verified debrief to the selected user?');">
            <input type="hidden" name="action" value="release_debrief">
            <input type="hidden" name="csrf_token" value="<?= cvr_intake_h($reconstructionCsrf) ?>">
            <input type="hidden" name="debrief_id" value="<?= (int)$selectedDebrief['id'] ?>">
            <label class="intake-field"><span>Recipient User ID</span><input class="intake-select" type="number" min="1" name="recipient_user_id" required></label>
            <button class="intake-button" type="submit" style="margin-top:8px;background:#7c3aed">Release Verified Debrief</button>
          </form>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </section>
</div>

<div class="intake-modal-backdrop" id="intake-audio-transcript-modal" hidden>
  <div class="intake-modal" role="dialog" aria-modal="true" aria-labelledby="intake-audio-transcript-title">
    <div class="intake-modal-head">
      <div>
        <h3 class="intake-modal-title" id="intake-audio-transcript-title">Cockpit Audio Transcript</h3>
        <div class="intake-muted" id="intake-audio-transcript-meta"></div>
      </div>
      <div class="intake-modal-actions">
        <button class="intake-modal-reprocess" type="button" data-audio-transcript-cleanup>Clean Up Transcript</button>
        <button class="intake-modal-reprocess" type="button" data-audio-transcript-reprocess>Re-Process From Audio</button>
        <button class="intake-modal-close" type="button" data-audio-transcript-close>Close</button>
      </div>
    </div>
    <div class="intake-modal-body">
      <p class="intake-modal-note" id="intake-audio-transcript-note">Use <strong>Clean Up</strong> to remove duplicate loops and repeated [unclear] markers from the stored transcript. Use <strong>Re-Process From Audio</strong> only when you need a fresh transcription from the audio file.</p>
      <audio class="intake-modal-audio" id="intake-audio-transcript-player" controls preload="none"></audio>
      <div class="intake-modal-transcript" id="intake-audio-transcript-body">Loading transcript…</div>
    </div>
  </div>
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
  page.querySelectorAll('[data-copy-debrief]').forEach((button) => {
    button.addEventListener('click', async () => {
      const field = document.getElementById(button.getAttribute('data-copy-debrief'));
      if (!field) return;
      try {
        await navigator.clipboard.writeText(field.value);
        button.textContent = 'Copied';
      } catch (error) {
        field.focus();
        field.select();
        document.execCommand('copy');
        button.textContent = 'Copied';
      }
      window.setTimeout(() => { button.textContent = 'Copy Full Review'; }, 1800);
    });
  });
  page.querySelectorAll('[data-copy-input]').forEach((button) => {
    button.addEventListener('click', async () => {
      const field = document.getElementById(button.getAttribute('data-copy-input'));
      if (!field) return;
      const originalText = button.textContent;
      try {
        await navigator.clipboard.writeText(field.value);
      } catch (error) {
        field.focus();
        field.select();
        document.execCommand('copy');
      }
      button.textContent = 'Copied';
      window.setTimeout(() => { button.textContent = originalText; }, 1800);
    });
  });

  const audioTable = page.querySelector('[data-audio-table]');
  const audioToggle = page.querySelector('[data-audio-short-toggle]');
  const audioSummary = page.querySelector('[data-audio-summary]');
  const audioTabCount = page.querySelector('[data-audio-tab-count]');
  const audioFilteredEmpty = page.querySelector('[data-audio-filtered-empty]');
  const audioShortStorageKey = 'master_logbook_audio_show_short';
  if (audioTable && audioToggle) {
    const totalCount = Number(audioTable.getAttribute('data-audio-total') || '0');
    const shortCount = Number(audioTable.getAttribute('data-audio-short') || '0');
    const visibleCount = Math.max(0, totalCount - shortCount);
    const updateAudioFilter = (showShort) => {
      audioTable.setAttribute('data-hide-short', showShort ? 'false' : 'true');
      audioToggle.classList.toggle('is-on', showShort);
      audioToggle.setAttribute('aria-pressed', showShort ? 'true' : 'false');
      audioToggle.textContent = showShort
        ? 'Short recordings (< 10 min): ON'
        : 'Short recordings (< 10 min): OFF';
      const shown = showShort ? totalCount : visibleCount;
      if (audioSummary) {
        audioSummary.textContent = showShort
          ? `Showing all ${totalCount} recordings (${shortCount} under 10 min)`
          : `Showing ${visibleCount} of ${totalCount} recordings (${shortCount} under 10 min hidden)`;
      }
      if (audioTabCount) {
        audioTabCount.textContent = String(shown);
      }
      if (audioFilteredEmpty) {
        audioFilteredEmpty.style.display = (!showShort && visibleCount === 0 && shortCount > 0) ? 'block' : 'none';
      }
    };
    let showShort = false;
    try {
      showShort = window.localStorage.getItem(audioShortStorageKey) === '1';
    } catch (error) {
      showShort = false;
    }
    updateAudioFilter(showShort);
    audioToggle.addEventListener('click', () => {
      showShort = !showShort;
      try {
        window.localStorage.setItem(audioShortStorageKey, showShort ? '1' : '0');
      } catch (error) {
        // Ignore storage failures; keep in-memory preference for this page view.
      }
      updateAudioFilter(showShort);
    });
  }

  let intakeInfoPopoverFloat = document.getElementById('intake-info-popover-float');
  if (!intakeInfoPopoverFloat) {
    intakeInfoPopoverFloat = document.createElement('div');
    intakeInfoPopoverFloat.id = 'intake-info-popover-float';
    intakeInfoPopoverFloat.className = 'intake-info-popover-float';
    intakeInfoPopoverFloat.hidden = true;
    document.body.appendChild(intakeInfoPopoverFloat);
  }
  const hideIntakeInfoPopover = () => {
    intakeInfoPopoverFloat.hidden = true;
  };
  const positionIntakeInfoPopover = (tip) => {
    const popover = tip.querySelector('.intake-info-popover');
    if (!popover) {
      return;
    }
    intakeInfoPopoverFloat.textContent = popover.textContent || '';
    intakeInfoPopoverFloat.hidden = false;
    intakeInfoPopoverFloat.style.left = '0px';
    intakeInfoPopoverFloat.style.top = '0px';
    intakeInfoPopoverFloat.style.transform = 'none';
    const tipRect = tip.getBoundingClientRect();
    const floatRect = intakeInfoPopoverFloat.getBoundingClientRect();
    const margin = 12;
    let left = tipRect.right + 8;
    let top = tipRect.top + (tipRect.height / 2) - (floatRect.height / 2);
    if (left + floatRect.width > window.innerWidth - margin) {
      left = tipRect.left - floatRect.width - 8;
    }
    if (left < margin) {
      left = margin;
    }
    if (top < margin) {
      top = margin;
    }
    if (top + floatRect.height > window.innerHeight - margin) {
      top = window.innerHeight - floatRect.height - margin;
    }
    intakeInfoPopoverFloat.style.left = left + 'px';
    intakeInfoPopoverFloat.style.top = top + 'px';
  };
  page.querySelectorAll('[data-intake-info-tip]').forEach((tip) => {
    tip.addEventListener('mouseenter', () => positionIntakeInfoPopover(tip));
    tip.addEventListener('focus', () => positionIntakeInfoPopover(tip));
    tip.addEventListener('mouseleave', hideIntakeInfoPopover);
    tip.addEventListener('blur', hideIntakeInfoPopover);
  });
  if (audioTable) {
    audioTable.addEventListener('scroll', hideIntakeInfoPopover, { passive: true });
  }
  window.addEventListener('scroll', hideIntakeInfoPopover, { passive: true });
  window.addEventListener('resize', hideIntakeInfoPopover, { passive: true });

  const transcriptModal = document.getElementById('intake-audio-transcript-modal');
  const transcriptPlayer = document.getElementById('intake-audio-transcript-player');
  const transcriptBody = document.getElementById('intake-audio-transcript-body');
  const transcriptMeta = document.getElementById('intake-audio-transcript-meta');
  const transcriptTitle = document.getElementById('intake-audio-transcript-title');
  const transcriptNote = document.getElementById('intake-audio-transcript-note');
  const transcriptReprocessButton = document.querySelector('[data-audio-transcript-reprocess]');
  const transcriptCleanupButton = document.querySelector('[data-audio-transcript-cleanup]');
  let activeTranscriptRecordingId = null;
  let transcriptPollTimer = null;

  const stopTranscriptPoll = () => {
    if (transcriptPollTimer !== null) {
      window.clearInterval(transcriptPollTimer);
      transcriptPollTimer = null;
    }
  };

  const loadTranscriptModal = async (recordingId, options = {}) => {
    const allowPoll = options.allowPoll !== false;
    if (!recordingId || !transcriptModal || !transcriptBody || !transcriptPlayer) {
      return;
    }
    activeTranscriptRecordingId = recordingId;
    transcriptModal.hidden = false;
    if (transcriptBody.textContent === 'Loading transcript…' || !allowPoll) {
      transcriptBody.textContent = 'Loading transcript…';
    }
    if (transcriptMeta && transcriptBody.textContent === 'Loading transcript…') transcriptMeta.textContent = '';
    if (transcriptTitle && transcriptBody.textContent === 'Loading transcript…') transcriptTitle.textContent = 'Cockpit Audio Transcript';

    const response = await fetch('/admin/api/cockpit_recorder_intake_transcript.php?id=' + encodeURIComponent(recordingId), { credentials: 'same-origin' });
    const payload = await response.json();
    if (!response.ok || !payload.ok) {
      throw new Error(payload.error || 'Could not load transcript.');
    }
    if (transcriptTitle) {
      transcriptTitle.textContent = payload.recording_uid ? ('Transcript · ' + payload.recording_uid) : 'Cockpit Audio Transcript';
    }
    if (transcriptMeta) {
      const metaParts = [];
      if (payload.aircraft_registration) metaParts.push(payload.aircraft_registration);
      if (payload.transcription_status) metaParts.push(String(payload.transcription_status).toUpperCase());
      if (payload.transcription_progress != null) metaParts.push(String(payload.transcription_progress) + '%');
      if (payload.original_filename) metaParts.push(payload.original_filename);
      transcriptMeta.textContent = metaParts.join(' · ');
    }
    if (transcriptNote) {
      transcriptNote.hidden = false;
    }
    if (payload.audio_url && !transcriptPlayer.getAttribute('src')) {
      transcriptPlayer.src = payload.audio_url;
    }
    const status = String(payload.transcription_status || '').toLowerCase();
    const inProgress = status === 'queued' || status === 'transcribing' || status === 'pending';
    if (transcriptReprocessButton) {
      transcriptReprocessButton.disabled = inProgress;
    }
    if (transcriptCleanupButton) {
      transcriptCleanupButton.disabled = inProgress;
    }
    if (inProgress) {
      transcriptBody.textContent = 'Transcription in progress… ' + String(payload.transcription_progress || 0) + '%';
      if (allowPoll && transcriptPollTimer === null) {
        transcriptPollTimer = window.setInterval(() => {
          loadTranscriptModal(recordingId, { allowPoll: true }).catch(() => stopTranscriptPoll());
        }, 3000);
      }
      return;
    }
    stopTranscriptPoll();
    if (status === 'failed') {
      transcriptBody.textContent = 'Transcription failed. Use Re-Process Transcript to try again.';
      if (transcriptReprocessButton) transcriptReprocessButton.disabled = false;
      if (transcriptCleanupButton) transcriptCleanupButton.disabled = false;
      return;
    }
    transcriptBody.textContent = payload.transcript_text || 'Transcript is not available yet.';
    if (transcriptReprocessButton) transcriptReprocessButton.disabled = false;
    if (transcriptCleanupButton) transcriptCleanupButton.disabled = false;
  };

  const closeTranscriptModal = () => {
    if (!transcriptModal) return;
    stopTranscriptPoll();
    activeTranscriptRecordingId = null;
    transcriptModal.hidden = true;
    if (transcriptPlayer) {
      transcriptPlayer.pause();
      transcriptPlayer.removeAttribute('src');
      transcriptPlayer.load();
    }
  };
  document.querySelectorAll('[data-audio-transcript-close]').forEach((button) => {
    button.addEventListener('click', closeTranscriptModal);
  });
  if (transcriptModal) {
    transcriptModal.addEventListener('click', (event) => {
      if (event.target === transcriptModal) closeTranscriptModal();
    });
  }
  page.querySelectorAll('[data-audio-transcript-open]').forEach((button) => {
    button.addEventListener('click', async () => {
      const recordingId = button.getAttribute('data-recording-id');
      if (!recordingId) return;
      stopTranscriptPoll();
      if (transcriptNote) transcriptNote.hidden = true;
      if (transcriptReprocessButton) transcriptReprocessButton.disabled = false;
      if (transcriptCleanupButton) transcriptCleanupButton.disabled = false;
      if (transcriptPlayer) {
        transcriptPlayer.removeAttribute('src');
        transcriptPlayer.load();
      }
      if (transcriptBody) transcriptBody.textContent = 'Loading transcript…';
      try {
        await loadTranscriptModal(recordingId, { allowPoll: true });
      } catch (error) {
        if (transcriptBody) {
          transcriptBody.textContent = error instanceof Error ? error.message : 'Could not load transcript.';
        }
      }
    });
  });

  if (transcriptCleanupButton) {
    transcriptCleanupButton.addEventListener('click', async () => {
      if (!activeTranscriptRecordingId) {
        return;
      }
      transcriptCleanupButton.disabled = true;
      if (transcriptBody) transcriptBody.textContent = 'Cleaning transcript…';
      try {
        const formData = new FormData();
        formData.append('id', activeTranscriptRecordingId);
        formData.append('mode', 'cleanup');
        const response = await fetch('/admin/api/cockpit_recorder_intake_reprocess_transcript.php', {
          method: 'POST',
          body: formData,
          credentials: 'same-origin',
        });
        const payload = await response.json();
        if (!response.ok || !payload.ok) {
          throw new Error(payload.error || payload.message || 'Could not clean transcript.');
        }
        await loadTranscriptModal(activeTranscriptRecordingId, { allowPoll: false });
        if (transcriptBody && payload.transcript_text) {
          transcriptBody.textContent = payload.transcript_text;
        }
      } catch (error) {
        if (transcriptBody) {
          transcriptBody.textContent = error instanceof Error ? error.message : 'Could not clean transcript.';
        }
      } finally {
        transcriptCleanupButton.disabled = false;
      }
    });
  }

  if (transcriptReprocessButton) {
    transcriptReprocessButton.addEventListener('click', async () => {
      if (!activeTranscriptRecordingId) {
        return;
      }
      if (!window.confirm('Re-process will delete the current transcript and regenerate it from the audio file. This may take several minutes. Try Clean Up first unless the audio itself was wrong. Continue?')) {
        return;
      }
      transcriptReprocessButton.disabled = true;
      if (transcriptCleanupButton) transcriptCleanupButton.disabled = true;
      if (transcriptBody) transcriptBody.textContent = 'Queuing transcript re-processing…';
      if (transcriptMeta) transcriptMeta.textContent = 'QUEUED · 0%';
      try {
        stopTranscriptPoll();
        const formData = new FormData();
        formData.append('id', activeTranscriptRecordingId);
        formData.append('mode', 'retry');
        const response = await fetch('/admin/api/cockpit_recorder_intake_reprocess_transcript.php', {
          method: 'POST',
          body: formData,
          credentials: 'same-origin',
        });
        const payload = await response.json();
        if (!response.ok || !payload.ok) {
          throw new Error(payload.error || payload.message || 'Could not queue transcript re-processing.');
        }
        if (transcriptBody) {
          transcriptBody.textContent = payload.message || 'Transcript re-processing queued…';
        }
        await loadTranscriptModal(activeTranscriptRecordingId, { allowPoll: true });
      } catch (error) {
        transcriptReprocessButton.disabled = false;
        if (transcriptCleanupButton) transcriptCleanupButton.disabled = false;
        if (transcriptBody) {
          transcriptBody.textContent = error instanceof Error ? error.message : 'Could not queue transcript re-processing.';
        }
      }
    });
  }

  const audioStatusCells = page.querySelectorAll('[data-audio-recording-id]');
  if (audioStatusCells.length > 0) {
    const audioStatusClass = (status) => {
      const normalized = String(status || '').toLowerCase();
      if (['ready', 'uploaded', 'received', 'finalized', 'complete', 'completed', 'ok', 'valid', 'active'].includes(normalized)) {
        return 'intake-status-good';
      }
      if (['failed', 'error', 'invalid', 'rejected'].includes(normalized)) {
        return 'intake-status-bad';
      }
      if (normalized === '') {
        return 'intake-status-muted';
      }
      return 'intake-status-pending';
    };
    const formatAudioStatusLabel = (status) => {
      const normalized = String(status || '').trim().toLowerCase();
      if (normalized === '') {
        return 'UNKNOWN';
      }
      return normalized.replace(/_/g, ' ').toUpperCase();
    };
    const abbreviateAudioError = (text, maxLength = 52) => {
      const normalized = String(text || '').trim();
      if (normalized === '') {
        return { display: '—', full: '' };
      }
      if (normalized.length <= maxLength) {
        return { display: normalized, full: normalized };
      }
      return { display: normalized.slice(0, maxLength).trimEnd() + '...', full: normalized };
    };
    const pollAudioStatus = async () => {
      const ids = Array.from(audioStatusCells)
        .map((cell) => cell.getAttribute('data-audio-recording-id'))
        .filter((id) => id && id !== '0');
      if (ids.length === 0) {
        return;
      }
      try {
        const response = await fetch('/admin/api/cockpit_recorder_intake_audio_status.php?ids=' + encodeURIComponent(ids.join(',')), { credentials: 'same-origin' });
        const payload = await response.json();
        if (!response.ok || !payload.ok) {
          return;
        }
        const byId = {};
        (payload.recordings || []).forEach((recording) => {
          byId[String(recording.id)] = recording;
        });
        audioStatusCells.forEach((cell) => {
          const recordingId = cell.getAttribute('data-audio-recording-id');
          const recording = byId[String(recordingId)];
          if (!recording) {
            return;
          }
          const progress = Math.max(0, Math.min(100, Number(recording.transcription_progress || 0)));
          const progressEl = cell.querySelector('[data-audio-transcription-progress]');
          const fillEl = cell.querySelector('[data-audio-transcription-fill]');
          const statusEl = cell.querySelector('[data-audio-transcription-status]');
          if (progressEl) {
            progressEl.textContent = progress + '%';
          }
          if (fillEl) {
            fillEl.style.width = progress + '%';
          }
          if (statusEl) {
            const status = String(recording.transcription_status || '');
            statusEl.textContent = formatAudioStatusLabel(status);
            statusEl.className = 'intake-status ' + audioStatusClass(status);
          }
          const row = cell.closest('tr');
          if (!row) {
            return;
          }
          const transcriptButton = row.querySelector('[data-audio-transcript-open]');
          if (transcriptButton) {
            transcriptButton.disabled = !recording.can_view_transcript;
          }
          const errorCell = row.querySelector('[data-audio-error-cell]');
          if (errorCell) {
            const error = abbreviateAudioError(recording.error_message);
            errorCell.textContent = error.display;
            if (error.full !== '') {
              errorCell.title = error.full;
            } else {
              errorCell.removeAttribute('title');
            }
          }
        });
      } catch (error) {
        // Ignore transient polling failures.
      }
    };
    pollAudioStatus();
    window.setInterval(pollAudioStatus, 3000);
  }
})();
</script>
<?php cw_footer(); ?>
