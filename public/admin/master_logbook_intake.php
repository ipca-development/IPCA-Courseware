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
require_once __DIR__ . '/../../src/CvrAdminLegCorrectionService.php';
require_once __DIR__ . '/../../src/CockpitRecorderDebriefQueueService.php';

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
        } elseif ($action === 'save_operational_leg') {
            (new CvrAdminLegCorrectionService($pdo))->save(
                (int)($_POST['dispatch_id'] ?? 0),
                is_array($_POST['leg'] ?? null) ? $_POST['leg'] : $_POST,
                $actorUserId > 0 ? $actorUserId : null
            );
            $reconstructionNotice = 'Operational leg updated.';
        } elseif ($action === 'generate_bundle_debrief') {
            $bundleId = (int)($_POST['bundle_id'] ?? 0);
            if ($bundleId <= 0) {
                throw new RuntimeException('bundle_id is required.');
            }
            $result = CockpitRecorderDebriefQueueService::fromPdo($pdo)
                ->lockAndQueueDebrief($bundleId, $actorUserId > 0 ? $actorUserId : null);
            $job = is_array($result['debrief_job'] ?? null) ? $result['debrief_job'] : array();
            $already = !empty($job['skipped']) || (($job['reason'] ?? '') === 'already_running');
            $reconstructionNotice = $already
                ? 'AI Debrief generation is already running in the background.'
                : 'AI Debrief generation started in the background. Refresh this tab to see completion status.';
        }
    }
} catch (Throwable $e) {
    $reconstructionError = $e->getMessage();
}

$legsAircraftFilter = strtoupper(trim((string)($_GET['legs_aircraft'] ?? '')));
$legsFrom = trim((string)($_GET['legs_from'] ?? ''));
$legsTo = trim((string)($_GET['legs_to'] ?? ''));
$legsPage = max(1, (int)($_GET['legs_page'] ?? 1));
$legsLimit = 30;
$legsOffset = ($legsPage - 1) * $legsLimit;
$dispatch = $intake->dispatchRows(
    $legsLimit,
    $legsOffset,
    $legsAircraftFilter !== '' ? $legsAircraftFilter : null,
    $legsFrom !== '' ? $legsFrom : null,
    $legsTo !== '' ? $legsTo : null
);
$legsTotal = (int)($dispatch['total'] ?? count($dispatch['rows']));
$legsPageCount = max(1, (int)($dispatch['page_count'] ?? 1));
$legsPage = max(1, min($legsPageCount, (int)($dispatch['page'] ?? $legsPage)));
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

function cvr_intake_pill(string $text, string $tone = 'muted'): string
{
    $text = trim($text);
    if ($text === '') {
        return '<span class="ml-pill">—</span>';
    }
    $class = match ($tone) {
        'ok', 'usable' => 'ml-pill ml-pill-usable',
        'present', 'info' => 'ml-pill ml-pill-present',
        'warn', 'processing' => 'ml-pill ml-pill-processing',
        'bad', 'failed' => 'ml-pill ml-pill-failed',
        default => 'ml-pill',
    };
    return '<span class="' . $class . '">' . cvr_intake_h($text) . '</span>';
}

function cvr_intake_local_date(PDO $pdo, mixed $value, string $registration = ''): string
{
    $text = trim((string)$value);
    if ($text === '') {
        return '—';
    }
    $timezone = cw_aircraft_operational_timezone_by_registration($pdo, $registration);
    $dt = cw_dt_obj($text, $timezone !== 'UTC' ? $timezone : 'America/Los_Angeles');
    return $dt instanceof DateTimeInterface ? $dt->format('Y-m-d') : '—';
}

function cvr_intake_legs_query(array $overrides = []): string
{
    $params = array_merge(array(
        'tab' => 'dispatch',
        'legs_aircraft' => (string)($GLOBALS['legsAircraftFilter'] ?? ''),
        'legs_from' => (string)($GLOBALS['legsFrom'] ?? ''),
        'legs_to' => (string)($GLOBALS['legsTo'] ?? ''),
        'legs_page' => (string)($GLOBALS['legsPage'] ?? '1'),
    ), $overrides);
    foreach ($params as $key => $value) {
        if ($value === '' || $value === null) {
            unset($params[$key]);
        }
    }
    return '/admin/master_logbook.php?' . http_build_query($params);
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
.intake-audio-filter{display:flex;flex-wrap:wrap;align-items:center;gap:10px;justify-content:flex-end}
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
.intake-table-audio col.intake-col-transcript{width:14%}
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
.intake-audio-transcript-head{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:2px 6px;align-items:center;max-width:100%}
.intake-audio-transcript-head [data-audio-transcription-status]{grid-column:1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.intake-audio-evidence-step{grid-column:1 / -1;display:block;font-size:10px;line-height:1.35;color:#64748b;white-space:normal;overflow:hidden;text-overflow:ellipsis}
.intake-audio-evidence-warning{grid-column:1 / -1;display:grid;gap:6px;margin-top:4px;padding:6px 8px;border:1px solid #fecaca;border-radius:8px;background:#fef2f2;color:#991b1b;font-size:10px;line-height:1.4}
.intake-audio-evidence-warning[hidden]{display:none!important}
.intake-audio-evidence-warning-text{display:block}
.intake-audio-evidence-retry-btn{border:1px solid #fca5a5;border-radius:999px;background:#fff;color:#b91c1c;padding:3px 8px;font-size:9px;font-weight:900;cursor:pointer;justify-self:start}
.intake-audio-evidence-retry-btn:hover{border-color:#ef4444;background:#fff1f2}
.intake-audio-evidence-retry-btn:disabled{opacity:.55;cursor:not-allowed}
.intake-audio-bulk-retry-btn{border:1px solid #f59e0b;border-radius:999px;background:#fffbeb;color:#92400e;padding:6px 12px;font-size:11px;font-weight:800;cursor:pointer;white-space:nowrap}
.intake-audio-bulk-retry-btn:hover{border-color:#d97706;background:#fef3c7}
.intake-audio-bulk-retry-btn:disabled{opacity:.55;cursor:not-allowed}
.intake-audio-transcript-progress{font-size:10px;color:#64748b;font-weight:700;font-variant-numeric:tabular-nums;grid-column:2;grid-row:1;white-space:nowrap}
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
.intake-modal-transcript-review{width:min(1280px,100%)}
.intake-modal-transcript-review .intake-modal-body{display:grid;gap:12px;padding:16px}
.trv-toolbar{display:grid;gap:8px}
.trv-toolbar-row{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}
.trv-layer-label{display:inline-flex;align-items:center;gap:8px;font-size:11px;font-weight:800;color:#475569;text-transform:uppercase;letter-spacing:.04em}
.trv-layer-select{border:1px solid #cbd5e1;border-radius:8px;background:#fff;color:#0f172a;padding:6px 8px;font-size:12px;font-weight:700}
.trv-pipeline-badges{display:flex;gap:6px;flex-wrap:wrap}
.trv-badge{display:inline-flex;border-radius:999px;padding:2px 8px;font-size:10px;font-weight:800;background:#e2e8f0;color:#64748b}
.trv-badge-ok{background:#dcfce7;color:#166534}
.trv-badge-stage{background:#e0e7ff;color:#3730a3}
.trv-workspace{display:grid;grid-template-columns:220px minmax(0,1fr) 260px;gap:12px;min-height:320px}
.trv-workspace[hidden]{display:none}
.trv-outline,.trv-transcript,.trv-sidebar{border:1px solid #e2e8f0;border-radius:12px;background:#f8fafc;padding:10px;min-height:280px;max-height:52vh;overflow:auto}
.trv-panel-title{font-size:11px;font-weight:900;text-transform:uppercase;letter-spacing:.06em;color:#475569;margin-bottom:8px}
.trv-muted{color:#64748b;font-size:11px;line-height:1.45;margin:0}
.trv-chapter-list{list-style:none;margin:0;padding:0;display:grid;gap:6px}
.trv-chapter-btn{width:100%;border:1px solid #dbeafe;border-radius:10px;background:#fff;padding:8px;text-align:left;cursor:pointer;display:grid;gap:3px}
.trv-chapter-btn:hover{border-color:#93c5fd;background:#eff6ff}
.trv-chapter-time{font-size:10px;font-weight:800;color:#64748b;font-variant-numeric:tabular-nums}
.trv-chapter-title{font-size:12px;font-weight:800;color:#0f172a;line-height:1.35}
.trv-chapter-cat{font-size:10px;color:#64748b}
.trv-block-list{display:grid;gap:8px}
.trv-block{display:grid;grid-template-columns:auto 1fr auto;gap:8px;align-items:start;border:1px solid transparent;border-radius:10px;padding:8px;background:#fff}
.trv-block.is-active{border-color:#93c5fd;background:#eff6ff}
.trv-block-time{border:0;background:transparent;color:#64748b;font-size:11px;font-weight:800;font-variant-numeric:tabular-nums;cursor:pointer;padding:0}
.trv-block-time:hover{color:#1d4ed8}
.trv-block-text{font-size:12px;line-height:1.55;color:#0f172a;white-space:pre-wrap}
.trv-block-correct{border:1px solid #cbd5e1;border-radius:8px;background:#fff;color:#475569;font-size:10px;font-weight:800;padding:4px 6px;cursor:pointer}
.trv-block-correct:hover{border-color:#93c5fd;color:#1d4ed8}
.trv-side-panel+ .trv-side-panel{margin-top:12px;padding-top:12px;border-top:1px solid #e2e8f0}
.trv-kv{display:grid;grid-template-columns:auto 1fr;gap:4px 10px;margin:0 0 8px;font-size:11px}
.trv-kv dt{color:#64748b;font-weight:700}
.trv-kv dd{margin:0;color:#0f172a;font-weight:800}
.trv-findings{margin:0;padding-left:0;list-style:none;font-size:11px;color:#475569;display:grid;gap:6px}
.trv-findings-group{margin-top:10px}
.trv-findings-title{font-size:10px;font-weight:900;text-transform:uppercase;letter-spacing:.05em;color:#64748b;margin-bottom:6px}
.trv-finding-btn{width:100%;border:1px solid #e2e8f0;border-radius:8px;background:#fff;padding:6px 8px;text-align:left;cursor:pointer;display:grid;gap:2px}
.trv-finding-btn:hover{border-color:#93c5fd;background:#eff6ff}
.trv-finding-time{font-size:10px;font-weight:800;color:#64748b;font-variant-numeric:tabular-nums}
.trv-finding-label{font-size:11px;font-weight:700;color:#0f172a;line-height:1.35}
.trv-finding-confidence{font-size:10px;color:#64748b}
.trv-finding-preview{font-size:10px;color:#475569;line-height:1.35;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.trv-correction-list{list-style:none;margin:0;padding:0;display:grid;gap:8px}
.trv-correction{border:1px solid #e2e8f0;border-radius:10px;background:#fff;padding:8px;font-size:11px}
.trv-correction-raw{color:#991b1b}
.trv-correction-arrow{color:#64748b}
.trv-correction-fixed{color:#166534;font-weight:700}
.trv-correction-meta{margin-top:6px;color:#64748b;display:flex;gap:6px;align-items:center;flex-wrap:wrap}
.trv-correction-action{border:1px solid #86efac;border-radius:7px;background:#ecfdf5;color:#166534;font-size:10px;font-weight:800;padding:2px 6px;cursor:pointer}
.trv-correction-reject{border-color:#fecaca;background:#fef2f2;color:#991b1b}
.trv-correction-form{display:grid;gap:8px;margin-top:10px}
.trv-correction-form label{display:grid;gap:4px;font-size:10px;font-weight:800;color:#64748b;text-transform:uppercase}
.trv-correction-form input{border:1px solid #cbd5e1;border-radius:8px;padding:7px 8px;font-size:12px}
.trv-correction-form-actions{display:flex;gap:8px}
.trv-btn-primary,.trv-btn-secondary{border-radius:8px;padding:6px 10px;font-size:11px;font-weight:800;cursor:pointer}
.trv-btn-primary{border:0;background:#1d4ed8;color:#fff}
.trv-evidence-warning{border:1px solid #fecaca;border-radius:10px;background:#fef2f2;color:#991b1b;padding:12px;display:grid;gap:8px}
.trv-evidence-warning p{margin:0;font-size:12px;line-height:1.45;color:#7f1d1d}
.trv-btn-secondary{border:1px solid #cbd5e1;background:#fff;color:#334155}
.trv-publish-hint-ready{border-left-color:#86efac;background:#ecfdf5;color:#166534}
@media (max-width:980px){.trv-workspace{grid-template-columns:1fr}.trv-outline,.trv-sidebar{max-height:24vh}}
.intake-modal-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;padding:14px 16px;border-bottom:1px solid #e2e8f0}
.intake-modal-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.intake-modal-reprocess{border:1px solid #fbbf24;border-radius:9px;background:#fffbeb;color:#92400e;padding:7px 10px;font-size:11px;font-weight:800;cursor:pointer}
.intake-modal-reprocess:disabled{opacity:.55;cursor:not-allowed}
.intake-modal-publish{border:1px solid #86efac;border-radius:9px;background:#ecfdf5;color:#166534;padding:7px 10px;font-size:11px;font-weight:800;cursor:pointer}
.intake-modal-publish:disabled{opacity:.55;cursor:not-allowed;color:#64748b;border-color:#cbd5e1;background:#f8fafc}
.intake-transcript-source{display:inline-flex;border-radius:999px;padding:2px 8px;font-size:10px;font-weight:800;background:#e0e7ff;color:#3730a3;margin-left:6px}
.intake-modal-publish-hint{border-left:3px solid #cbd5e1;background:#f8fafc;color:#475569;padding:8px 10px;margin-top:0}
.intake-modal-publish-hint-ready{border-left-color:#86efac;background:#ecfdf5;color:#166534}
.intake-modal-note{margin:0 0 8px;color:#64748b;font-size:11px;line-height:1.45}
.intake-modal-title{margin:0;font-size:16px;color:#0f172a}
.intake-modal-body{display:grid;gap:14px;padding:16px}
.intake-modal-audio{width:100%}
.intake-modal-transcript{max-height:48vh;overflow:auto;border:1px solid #e2e8f0;border-radius:12px;padding:12px;background:#f8fafc;font-size:12px;line-height:1.6;white-space:pre-wrap;color:#0f172a}
.intake-modal-close{border:1px solid #cbd5e1;border-radius:9px;background:#fff;color:#334155;padding:7px 10px;font-size:11px;font-weight:800;cursor:pointer}
.intake-panel-title{margin:0;color:#0f172a;font-size:17px}
/* Operational Legs — IPCA Master Logbook visual language */
.legs-toolbar{display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:flex-end;margin-bottom:12px}
.legs-filters{display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end}
.legs-filters label{display:grid;gap:4px;font-size:10px;font-weight:800;letter-spacing:.04em;text-transform:uppercase;color:#64748b}
.legs-filters .intake-select{min-width:120px;padding:6px 8px;font-size:12px}
.ml-pill{display:inline-flex;align-items:center;gap:4px;border-radius:999px;padding:2px 7px;font-size:9px;font-weight:800;background:#e2e8f0;color:#334155;white-space:nowrap}
.ml-pill-usable{background:#dcfce7;color:#166534}
.ml-pill-present{background:#dbeafe;color:#1e40af}
.ml-pill-processing{background:#fef3c7;color:#92400e}
.ml-pill-failed{background:#fee2e2;color:#991b1b}
.ml-aircraft-pill{display:inline-flex;border-radius:999px;padding:2px 7px;font-size:10px;font-weight:900;border:1px solid #93c5fd;color:#1d4ed8;background:#eff6ff}
.ml-crew{display:grid;gap:3px;font-size:10px;line-height:1.15;min-width:0;max-width:118px}
.ml-crew-member{display:grid;gap:0}
.ml-crew-role{font-size:8px;color:#64748b;text-transform:uppercase;letter-spacing:.04em;font-weight:800}
.ml-crew-name{font-weight:700;color:#0f172a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.legs-table-wrap{overflow-x:auto;border:1px solid #e2e8f0;border-radius:14px;background:#fff}
.legs-table{width:100%;min-width:1480px;border-collapse:collapse;font-size:11px;table-layout:fixed}
.legs-table th,.legs-table td{padding:4px 5px;border-bottom:1px solid #e2e8f0;vertical-align:middle;overflow:hidden}
.legs-table th{position:sticky;top:0;z-index:2;background:#f8fafc;font-size:8px;letter-spacing:.04em;text-transform:uppercase;color:#475569;font-weight:800;white-space:nowrap;text-align:center}
.legs-table td{color:#102845;white-space:nowrap;text-align:center;font-variant-numeric:tabular-nums}
.legs-table td.legs-crew{text-align:left}
.legs-table tbody tr.legs-row{cursor:pointer;transition:background-color .12s ease,box-shadow .12s ease}
.legs-table tbody tr.legs-row:hover{background:#eef6ff;box-shadow:inset 3px 0 0 #2563eb}
.legs-num{font-weight:750;color:#334155}
.legs-blank{color:#cbd5e1;font-weight:700}
.legs-pagination{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-top:12px}
.legs-pagination a,.legs-pagination span{font-size:12px;font-weight:800;color:#334155;text-decoration:none}
.legs-pagination a{color:#1d4ed8}
.legs-link-btn{border:0;background:transparent;color:#1d4ed8;font:inherit;font-weight:800;font-size:9px;cursor:pointer;padding:0;text-decoration:underline}
.legs-link-btn:disabled{color:#94a3b8;cursor:not-allowed;text-decoration:none}
.legs-modal-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:10px}
.legs-modal-grid label{display:grid;gap:4px;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.04em;color:#64748b}
.legs-modal-grid input,.legs-modal-grid select,.legs-modal-grid textarea{border:1px solid #cbd5e1;border-radius:9px;padding:7px 8px;font-size:12px;color:#0f172a;background:#fff}
.legs-crew-editor{display:grid;gap:8px}
.legs-crew-row{display:grid;grid-template-columns:110px 1fr auto;gap:8px}
.legs-copy-box{white-space:pre-wrap;word-break:break-word;background:#0f172a;color:#e2e8f0;border-radius:12px;padding:12px;max-height:55vh;overflow:auto;font-size:12px;line-height:1.45;font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace}
.intake-muted{color:#64748b;font-size:12px}
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
.intake-progress-fill.is-evidence-active{background:linear-gradient(90deg,#2563eb 0%,#7c3aed 50%,#2563eb 100%);background-size:200% 100%;animation:intake-evidence-progress 1.6s linear infinite}
.intake-audio-evidence-step{display:block;font-size:10px;line-height:1.35;margin-top:2px}
@keyframes intake-evidence-progress{0%{background-position:100% 0}100%{background-position:-100% 0}}
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
      <p class="intake-muted">Operational Legs are the authoritative per-leg CVR record (Reservation → Leg). Times display in America/Los_Angeles. Other tabs remain raw intake for audio, Garmin CSV, and reconstruction.</p>
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
        Operational Legs <span class="intake-count"><?= count($dispatch['rows']) ?></span>
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
        <h2 class="intake-panel-title">Operational Legs</h2>
        <div class="intake-muted">Logbook view — one row per checked-in Dispatch leg. Date = Off Block local calendar day. Times use the aircraft operational timezone.</div>
      </div>
    </div>
    <form class="legs-toolbar" method="get" action="/admin/master_logbook.php">
      <input type="hidden" name="tab" value="dispatch">
      <div class="legs-filters">
        <label>Aircraft
          <select class="intake-select" name="legs_aircraft">
            <option value="">All aircraft</option>
            <?php foreach ($aircraftOptions as $aircraftOption): ?>
              <?php $reg = strtoupper(trim((string)($aircraftOption['registration'] ?? ''))); ?>
              <?php if ($reg === '') { continue; } ?>
              <option value="<?= cvr_intake_h($reg) ?>" <?= $legsAircraftFilter === $reg ? 'selected' : '' ?>><?= cvr_intake_h($reg) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>From
          <input class="intake-select" type="date" name="legs_from" value="<?= cvr_intake_h($legsFrom) ?>">
        </label>
        <label>To
          <input class="intake-select" type="date" name="legs_to" value="<?= cvr_intake_h($legsTo) ?>">
        </label>
        <button class="intake-button" type="submit">Apply</button>
        <a class="intake-refresh" href="/admin/master_logbook.php?tab=dispatch">Clear</a>
      </div>
      <div class="intake-muted">
        Showing <?= $legsTotal === 0 ? 0 : (($legsPage - 1) * $legsLimit + 1) ?>–<?= min($legsTotal, $legsPage * $legsLimit) ?>
        of <?= (int)$legsTotal ?> · 30 / page
      </div>
    </form>
    <?php if (!$dispatch['available']): ?>
      <div class="intake-notice"><?= cvr_intake_h($dispatch['message']) ?></div>
    <?php elseif ($dispatch['rows'] === array()): ?>
      <div class="intake-empty">No Dispatch records match these filters.</div>
    <?php else: ?>
      <div class="legs-table-wrap">
        <table class="legs-table" data-operational-legs-table>
          <thead>
            <tr>
              <th>Date</th>
              <th>Aircraft</th>
              <th>Crew</th>
              <th>Dep</th>
              <th>Off Block</th>
              <th>Arr</th>
              <th>Arrival</th>
              <th>Hobbs</th>
              <th>Block</th>
              <th>Tacho</th>
              <th>Flight</th>
              <th>Fuel Dep</th>
              <th>Fuel Ldg</th>
              <th>Fuel Burn</th>
              <th>Oil Dep</th>
              <th>TO/LDG</th>
              <th>Audio</th>
              <th>Flight Data</th>
              <th>Replay</th>
              <th>Debriefing</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($dispatch['rows'] as $row): ?>
            <?php
              $tail = (string)($row['aircraft_registration'] ?? '');
              $startHobbs = $row['starting_hobbs'] ?? null;
              $endHobbs = $row['ending_hobbs'] ?? null;
              $startTacho = $row['starting_tacho'] ?? null;
              $endTacho = $row['ending_tacho'] ?? null;
              $hobbsLabel = (is_numeric($startHobbs) || is_numeric($endHobbs))
                  ? trim((is_numeric($startHobbs) ? number_format((float)$startHobbs, 1) : '—') . '→' . (is_numeric($endHobbs) ? number_format((float)$endHobbs, 1) : '—'))
                  : '—';
              $tachoLabel = (is_numeric($startTacho) || is_numeric($endTacho))
                  ? trim((is_numeric($startTacho) ? number_format((float)$startTacho, 1) : '—') . '→' . (is_numeric($endTacho) ? number_format((float)$endTacho, 1) : '—'))
                  : '—';
              $engine = $row['engine_time_hours'] ?? null;
              $tachoDelta = $row['tacho_delta_hours'] ?? null;
              $airborne = $row['airborne_time_hours'] ?? null;
              $flightTime = is_numeric($tachoDelta) ? (float)$tachoDelta : (is_numeric($airborne) ? (float)$airborne : null);
              $fuelDep = trim((string)($row['fuel_departure'] ?? $row['fuel_onboard'] ?? ''));
              $fuelLdg = trim((string)($row['fuel_landing'] ?? $row['fuel_remaining'] ?? ''));
              $fuelBurn = $row['fuel_consumption'] ?? null;
              $oilDep = trim((string)($row['oil_departure_label'] ?? ''));
              $flightDate = cvr_intake_local_date($pdo, $row['off_block_utc'] ?? null, $tail);
              $crewMembers = is_array($row['crew_members'] ?? null) ? $row['crew_members'] : array();
              $audioLabel = (string)($row['audio_status_label'] ?? 'Stored on Device');
              $audioTone = str_contains(strtolower($audioLabel), 'fail') ? 'bad'
                  : (str_contains(strtolower($audioLabel), 'uploaded') ? 'ok' : 'muted');
              $garminOn = !empty($row['has_garmin_csv']);
              $recordingUid = trim((string)($row['recording_uid'] ?? ''));
              $replayReady = $recordingUid !== '' && strtolower((string)($row['reconstruction_status'] ?? '')) === 'ready';
              if (!$replayReady && $recordingUid !== '') {
                  $replayReady = true; // allow open when recording exists
              }
              $debriefId = (int)($row['debrief_id'] ?? 0);
              $bundleId = (int)($row['bundle_id'] ?? 0);
              $legPayload = array(
                  'dispatch_id' => (int)($row['id'] ?? 0),
                  'dispatch_uuid' => (string)($row['dispatch_uuid'] ?? ''),
                  'workflow_flight_record_uuid' => (string)($row['workflow_flight_record_uuid'] ?? ''),
                  'aircraft_registration' => $tail,
                  'mission_code' => (string)($row['mission_code'] ?? ''),
                  'departure_airport' => (string)($row['departure_airport'] ?? ''),
                  'arrival_airport' => (string)($row['arrival_airport'] ?? ''),
                  'off_block_utc' => (string)($row['off_block_utc'] ?? ''),
                  'on_block_utc' => (string)($row['on_block_utc'] ?? ''),
                  'off_block_local' => '',
                  'starting_hobbs' => $startHobbs,
                  'ending_hobbs' => $endHobbs,
                  'starting_tacho' => $startTacho,
                  'ending_tacho' => $endTacho,
                  'fuel_onboard' => $fuelDep,
                  'fuel_remaining' => $fuelLdg,
                  'oil_percentage' => $row['oil_percentage'] ?? null,
                  'oil_quantity' => $row['oil_quantity'] ?? null,
                  'oil_unit' => (string)($row['oil_unit'] ?? ''),
                  'takeoff_count' => (int)($row['takeoff_count'] ?? 0),
                  'landing_count' => (int)($row['landing_count'] ?? 0),
                  'crew' => $crewMembers,
                  'bundle_id' => $bundleId,
                  'debrief_id' => $debriefId,
                  'recording_uid' => $recordingUid,
                  'timezone' => cvr_intake_california_timezone($pdo, $tail),
              );
              if (!empty($row['off_block_utc'])) {
                  $tzName = $legPayload['timezone'];
                  try {
                      $dt = new DateTimeImmutable((string)$row['off_block_utc'], new DateTimeZone('UTC'));
                      $legPayload['off_block_local'] = $dt->setTimezone(new DateTimeZone($tzName))->format('Y-m-d\TH:i');
                  } catch (Throwable) {
                      $legPayload['off_block_local'] = '';
                  }
              }
            ?>
            <tr class="legs-row" data-leg="<?= cvr_intake_h(json_encode($legPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP)) ?>">
              <td class="legs-num"><?= cvr_intake_h($flightDate) ?></td>
              <td><span class="ml-aircraft-pill"><?= cvr_intake_h($tail !== '' ? $tail : '—') ?></span></td>
              <td class="legs-crew"><div class="ml-crew"><?php
                if ($crewMembers === array()) {
                    echo '<span class="legs-blank">—</span>';
                } else {
                    foreach ($crewMembers as $member) {
                        $role = strtoupper(trim((string)($member['role'] ?? '')));
                        $name = trim((string)($member['name'] ?? ''));
                        echo '<div class="ml-crew-member"><div class="ml-crew-role">' . cvr_intake_h($role !== '' ? $role : 'CREW') . '</div><div class="ml-crew-name" title="' . cvr_intake_h($name) . '">' . cvr_intake_h($name) . '</div></div>';
                    }
                }
              ?></div></td>
              <td class="legs-num"><?= cvr_intake_h(trim((string)($row['departure_airport'] ?? '')) ?: '—') ?></td>
              <td class="legs-num"><?= cvr_intake_h(cvr_intake_local_time($pdo, $row['off_block_utc'] ?? null, $tail)) ?></td>
              <td class="legs-num"><?= cvr_intake_h(trim((string)($row['arrival_airport'] ?? '')) ?: '—') ?></td>
              <td class="legs-num"><?= cvr_intake_h(cvr_intake_local_time($pdo, $row['on_block_utc'] ?? null, $tail)) ?></td>
              <td class="legs-num"><?= cvr_intake_h($hobbsLabel) ?></td>
              <td class="legs-num"><?= cvr_intake_h(is_numeric($engine) ? number_format((float)$engine, 1) : '—') ?></td>
              <td class="legs-num"><?= cvr_intake_h($tachoLabel) ?></td>
              <td class="legs-num"><?= cvr_intake_h(is_numeric($flightTime) ? number_format((float)$flightTime, 1) : '—') ?></td>
              <td class="legs-num"><?= cvr_intake_h($fuelDep !== '' ? $fuelDep : '—') ?></td>
              <td class="legs-num"><?= cvr_intake_h($fuelLdg !== '' ? $fuelLdg : '—') ?></td>
              <td class="legs-num"><?= cvr_intake_h(is_numeric($fuelBurn) ? number_format((float)$fuelBurn, 1) : '—') ?></td>
              <td class="legs-num"><?= cvr_intake_h($oilDep !== '' ? $oilDep : '—') ?></td>
              <td class="legs-num"><?= cvr_intake_h(((int)($row['takeoff_count'] ?? 0)) . '/' . ((int)($row['landing_count'] ?? 0))) ?></td>
              <td><?= cvr_intake_pill($audioLabel, $audioTone) ?></td>
              <td><?= cvr_intake_pill($garminOn ? 'Garmin Uploaded' : 'Garmin Missing', $garminOn ? 'ok' : 'warn') ?></td>
              <td>
                <?php if ($replayReady): ?>
                  <a class="legs-link-btn" href="/admin/cockpit_recorder_replay.php?id=<?= rawurlencode($recordingUid) ?>" data-legs-stop>Replay</a>
                <?php else: ?>
                  <span class="legs-blank">—</span>
                <?php endif; ?>
              </td>
              <td>
                <button type="button" class="legs-link-btn" data-legs-debrief data-debrief-id="<?= $debriefId ?>" data-bundle-id="<?= $bundleId ?>" data-legs-stop>Debriefing</button>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="legs-pagination">
        <div class="intake-muted">Page <?= (int)$legsPage ?> of <?= (int)$legsPageCount ?></div>
        <div style="display:flex;gap:12px;align-items:center">
          <?php if ($legsPage > 1): ?>
            <a href="<?= cvr_intake_h(cvr_intake_legs_query(array('legs_page' => (string)($legsPage - 1)))) ?>">← Previous</a>
          <?php else: ?>
            <span class="legs-blank">← Previous</span>
          <?php endif; ?>
          <?php if ($legsPage < $legsPageCount): ?>
            <a href="<?= cvr_intake_h(cvr_intake_legs_query(array('legs_page' => (string)($legsPage + 1)))) ?>">Next →</a>
          <?php else: ?>
            <span class="legs-blank">Next →</span>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>
  </section>

  <section class="intake-card intake-panel" role="tabpanel" data-intake-panel="audio">
    <div class="intake-panel-head">
      <div>
        <h2 class="intake-panel-title">Cockpit Audio</h2>
        <div class="intake-muted">Audio upload and transcription status received from recorder units.</div>
      </div>
      <?php if ($audio['available'] && $audio['rows'] !== array()): ?>
        <div class="intake-audio-filter">
          <?php if ($audioShortRowCount > 0): ?>
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
          <?php endif; ?>
          <button
            class="intake-audio-bulk-retry-btn"
            type="button"
            data-audio-evidence-retry-all
            hidden
            title="Restart evidence for all stuck recordings in this list"
          >
            Restart stuck evidence
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
                  <span class="intake-audio-evidence-step intake-muted" data-audio-evidence-step hidden></span>
                  <span class="intake-audio-transcript-progress" data-audio-transcription-progress><?= $transcriptionProgress ?>%</span>
                </div>
                <div class="intake-progress-bar" style="margin-top:4px"><div class="intake-progress-fill" data-audio-transcription-fill style="width:<?= $transcriptionProgress ?>%"></div></div>
                <div class="intake-audio-evidence-warning" data-audio-evidence-warning hidden>
                  <span class="intake-audio-evidence-warning-text" data-audio-evidence-warning-text></span>
                  <button type="button" class="intake-audio-evidence-retry-btn" data-audio-evidence-retry>Restart Evidence</button>
                </div>
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
                <?php
                  $bundleReplayReady = strtolower(trim((string)($bundle['reconstruction_status'] ?? ''))) === 'ready'
                    && trim((string)($bundle['recording_uid'] ?? '')) !== '';
                ?>
                <?php if ($bundleReplayReady): ?>
                  <a
                    class="intake-button"
                    href="/admin/cockpit_recorder_replay.php?id=<?= rawurlencode((string)$bundle['recording_uid']) ?>"
                    style="display:inline-block;text-decoration:none"
                  >Open Replay</a>
                <?php endif; ?>
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
                    <div class="intake-muted" style="margin-top:4px">Updates canonical logbook fields from the frozen CSV; it does not rebuild Replay.</div>
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

<div class="intake-modal-backdrop" id="legs-edit-modal" hidden>
  <div class="intake-modal" role="dialog" aria-modal="true" aria-labelledby="legs-edit-title" style="max-width:920px">
    <div class="intake-modal-head">
      <div>
        <h3 class="intake-modal-title" id="legs-edit-title">Edit Operational Leg</h3>
        <div class="intake-muted">Admin correction of the online Dispatch / Check-In record. On Block recalculates from Off Block + Hobbs.</div>
      </div>
      <button class="intake-modal-close" type="button" data-legs-edit-close>Close</button>
    </div>
    <div class="intake-modal-body">
      <form method="post" id="legs-edit-form">
        <input type="hidden" name="action" value="save_operational_leg">
        <input type="hidden" name="csrf_token" value="<?= cvr_intake_h($reconstructionCsrf) ?>">
        <input type="hidden" name="dispatch_id" id="legs-edit-dispatch-id" value="">
        <input type="hidden" name="leg[timezone]" id="legs-edit-timezone" value="America/Los_Angeles">
        <div class="legs-modal-grid">
          <label>Aircraft
            <select name="leg[aircraft_registration]" id="legs-edit-aircraft">
              <?php foreach ($aircraftOptions as $aircraftOption): ?>
                <?php $reg = strtoupper(trim((string)($aircraftOption['registration'] ?? ''))); ?>
                <?php if ($reg === '') { continue; } ?>
                <option value="<?= cvr_intake_h($reg) ?>"><?= cvr_intake_h($reg) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>Mission <input name="leg[mission_code]" id="legs-edit-mission" type="text"></label>
          <label>Departure <input name="leg[departure_airport]" id="legs-edit-dep" type="text" maxlength="8"></label>
          <label>Arrival <input name="leg[arrival_airport]" id="legs-edit-arr" type="text" maxlength="8"></label>
          <label>Off Block (local) <input name="leg[off_block_local]" id="legs-edit-off" type="datetime-local"></label>
          <label>Hobbs Start <input name="leg[starting_hobbs]" id="legs-edit-hobbs-start" type="number" step="0.1"></label>
          <label>Hobbs End <input name="leg[ending_hobbs]" id="legs-edit-hobbs-end" type="number" step="0.1"></label>
          <label>Tacho Start <input name="leg[starting_tacho]" id="legs-edit-tacho-start" type="number" step="0.1"></label>
          <label>Tacho End <input name="leg[ending_tacho]" id="legs-edit-tacho-end" type="number" step="0.1"></label>
          <label>Fuel Departure <input name="leg[fuel_onboard]" id="legs-edit-fuel-dep" type="text"></label>
          <label>Fuel Landing <input name="leg[fuel_remaining]" id="legs-edit-fuel-ldg" type="text"></label>
          <label>Oil Qty <input name="leg[oil_quantity]" id="legs-edit-oil-qty" type="number" step="0.1"></label>
          <label>Oil Unit <input name="leg[oil_unit]" id="legs-edit-oil-unit" type="text" maxlength="16"></label>
          <label>Oil % <input name="leg[oil_percentage]" id="legs-edit-oil-pct" type="number" min="0" max="100"></label>
          <label>Takeoffs <input name="leg[takeoff_count]" id="legs-edit-to" type="number" min="0"></label>
          <label>Landings <input name="leg[landing_count]" id="legs-edit-ldg" type="number" min="0"></label>
        </div>
        <div style="margin-top:12px">
          <div class="intake-muted" style="margin-bottom:6px">Crew</div>
          <div class="legs-crew-editor" id="legs-edit-crew"></div>
          <button class="intake-button" type="button" id="legs-edit-add-crew" style="margin-top:8px;background:#475569">Add crew</button>
        </div>
        <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:14px">
          <button class="intake-modal-close" type="button" data-legs-edit-close>Cancel</button>
          <button class="intake-button" type="submit">Save Leg</button>
        </div>
      </form>
      <form method="post" enctype="multipart/form-data" style="border-top:1px solid #e2e8f0;padding-top:14px;margin-top:4px">
        <input type="hidden" name="action" value="upload_manual_garmin_csv">
        <input type="hidden" name="csrf_token" value="<?= cvr_intake_h($reconstructionCsrf) ?>">
        <input type="hidden" name="workflow_flight_record_uuid" id="legs-csv-flight-uuid" value="">
        <input type="hidden" name="aircraft_registration" id="legs-csv-aircraft" value="">
        <div class="intake-muted" style="margin-bottom:8px">Upload missing Garmin CSV for this leg</div>
        <div class="legs-modal-grid">
          <label>Aircraft ID
            <select name="aircraft_id" id="legs-csv-aircraft-id">
              <?php foreach ($aircraftOptions as $aircraftOption): ?>
                <option value="<?= (int)($aircraftOption['id'] ?? 0) ?>" data-reg="<?= cvr_intake_h(strtoupper(trim((string)($aircraftOption['registration'] ?? '')))) ?>"><?= cvr_intake_h((string)($aircraftOption['registration'] ?? '')) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>Garmin CSV <input type="file" name="garmin_csv" accept=".csv,text/csv" required></label>
        </div>
        <button class="intake-button" type="submit" style="margin-top:10px">Upload CSV</button>
      </form>
    </div>
  </div>
</div>

<div class="intake-modal-backdrop" id="legs-debrief-modal" hidden>
  <div class="intake-modal" role="dialog" aria-modal="true" aria-labelledby="legs-debrief-title" style="max-width:860px">
    <div class="intake-modal-head">
      <div>
        <h3 class="intake-modal-title" id="legs-debrief-title">Debriefing</h3>
        <div class="intake-muted" id="legs-debrief-meta">Structured chronological copy-paste</div>
      </div>
      <div class="intake-modal-actions">
        <button class="intake-button" type="button" id="legs-debrief-copy">Copy all</button>
        <button class="intake-modal-close" type="button" data-legs-debrief-close>Close</button>
      </div>
    </div>
    <div class="intake-modal-body">
      <div class="intake-muted" id="legs-debrief-message"></div>
      <pre class="legs-copy-box" id="legs-debrief-copy-box">Loading…</pre>
      <form method="post" id="legs-debrief-generate-form" hidden>
        <input type="hidden" name="action" value="generate_bundle_debrief">
        <input type="hidden" name="csrf_token" value="<?= cvr_intake_h($reconstructionCsrf) ?>">
        <input type="hidden" name="bundle_id" id="legs-debrief-bundle-id" value="">
        <button class="intake-button" type="submit">Generate Debrief</button>
      </form>
    </div>
  </div>
</div>

<div class="intake-modal-backdrop" id="intake-audio-transcript-modal" hidden>
  <div class="intake-modal intake-modal-transcript-review" role="dialog" aria-modal="true" aria-labelledby="intake-audio-transcript-title">
    <div class="intake-modal-head">
      <div>
        <h3 class="intake-modal-title" id="intake-audio-transcript-title">Cockpit Audio Transcript</h3>
        <div class="intake-muted" id="intake-audio-transcript-meta"></div>
      </div>
      <div class="intake-modal-actions">
        <button class="intake-modal-publish" type="button" data-audio-transcript-publish hidden>Publish Evidence</button>
        <button class="intake-modal-reprocess" type="button" data-audio-transcript-cleanup>Clean Up Transcript</button>
        <button class="intake-modal-reprocess" type="button" data-audio-transcript-reprocess>Re-Process From Audio</button>
        <button class="intake-modal-close" type="button" data-audio-transcript-close>Close</button>
      </div>
    </div>
    <div class="intake-modal-body">
      <p class="intake-modal-note" id="intake-audio-transcript-note">Use <strong>Publish Evidence</strong> to snapshot the Pass 4 readable transcript into an immutable published version (recommended when typed evidence exists). Use <strong>Clean Up</strong> for legacy duplicate removal on the stored cache. Use <strong>Re-Process From Audio</strong> only when you need a fresh transcription from the audio file.</p>
      <p class="intake-modal-note intake-modal-publish-hint" id="intake-audio-transcript-publish-hint" hidden></p>
      <audio class="intake-modal-audio" id="intake-audio-transcript-player" controls preload="none"></audio>
      <div class="trv-toolbar" id="intake-transcript-review-toolbar"></div>
      <div class="trv-workspace" id="intake-transcript-review-workspace" hidden>
        <aside class="trv-outline" id="intake-transcript-review-outline"></aside>
        <main class="trv-transcript" id="intake-transcript-review-transcript"></main>
        <aside class="trv-sidebar" id="intake-transcript-review-sidebar"></aside>
      </div>
      <div class="intake-modal-transcript" id="intake-audio-transcript-body">Loading transcript…</div>
    </div>
  </div>
</div>

<?php
$transcriptReviewJsPath = __DIR__ . '/assets/transcript_review.js';
$transcriptReviewJsVer = is_file($transcriptReviewJsPath) ? (string)filemtime($transcriptReviewJsPath) : (string)time();
?>
<script src="/admin/assets/transcript_review.js?v=<?= cvr_intake_h($transcriptReviewJsVer) ?>"></script>

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
  const transcriptPublishButton = document.querySelector('[data-audio-transcript-publish]');
  const transcriptPublishHint = document.getElementById('intake-audio-transcript-publish-hint');
  const transcriptReview = window.TranscriptReviewWorkspace?.init({
    title: transcriptTitle,
    meta: transcriptMeta,
    note: transcriptNote,
    player: transcriptPlayer,
    toolbar: document.getElementById('intake-transcript-review-toolbar'),
    workspace: document.getElementById('intake-transcript-review-workspace'),
    outline: document.getElementById('intake-transcript-review-outline'),
    transcript: document.getElementById('intake-transcript-review-transcript'),
    sidebar: document.getElementById('intake-transcript-review-sidebar'),
    legacyBody: transcriptBody,
    publishButton: transcriptPublishButton,
    publishHint: transcriptPublishHint,
    reprocessButton: transcriptReprocessButton,
    cleanupButton: transcriptCleanupButton,
  });
  let activeTranscriptRecordingId = null;

  const loadTranscriptModal = async (recordingId, options = {}) => {
    if (!recordingId || !transcriptModal || !transcriptReview) {
      return;
    }
    activeTranscriptRecordingId = recordingId;
    transcriptModal.hidden = false;
    await transcriptReview.load(recordingId, options);
  };

  const closeTranscriptModal = () => {
    if (!transcriptModal) return;
    transcriptReview?.close();
    activeTranscriptRecordingId = null;
    transcriptModal.hidden = true;
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
      transcriptReview?.close();
      if (transcriptNote) transcriptNote.hidden = false;
      if (transcriptReprocessButton) transcriptReprocessButton.disabled = false;
      if (transcriptCleanupButton) transcriptCleanupButton.disabled = false;
      if (transcriptBody) transcriptBody.textContent = 'Loading transcript…';
      try {
        await loadTranscriptModal(recordingId, { allowPoll: true });
      } catch (error) {
        if (transcriptBody) {
          transcriptBody.hidden = false;
          transcriptBody.textContent = error instanceof Error ? error.message : 'Could not load transcript.';
        }
      }
    });
  });

  if (transcriptPublishButton) {
    transcriptPublishButton.addEventListener('click', async () => {
      if (!activeTranscriptRecordingId) {
        return;
      }
      const activePublishableRunId = transcriptReview?.getPublishableRunId?.() || null;
      const message = activePublishableRunId
        ? ('Publish an immutable evidence transcript snapshot from processing run #' + activePublishableRunId + '? This updates the legacy transcript cache from the readable evidence layer (no cleanTranscriptText pass).')
        : 'Publish an immutable evidence transcript snapshot from the latest typed evidence run?';
      if (!window.confirm(message)) {
        return;
      }
      transcriptPublishButton.disabled = true;
      try {
        const formData = new FormData();
        formData.append('recording_id', activeTranscriptRecordingId);
        if (activePublishableRunId) {
          formData.append('processing_run_id', String(activePublishableRunId));
        }
        const response = await fetch('/admin/api/cockpit_evidence_publish_transcript.php', {
          method: 'POST',
          body: formData,
          credentials: 'same-origin',
        });
        const payload = await response.json();
        if (!response.ok || !payload.ok) {
          throw new Error(payload.error || 'Could not publish evidence transcript.');
        }
        await loadTranscriptModal(activeTranscriptRecordingId, { allowPoll: false, silentRefresh: true });
        const blockInfo = payload.display_block_count ? (' · ' + String(payload.display_block_count) + ' timestamped blocks') : '';
        window.alert('Published evidence transcript version ' + (payload.version_uuid || payload.published_transcript_version_id || '') + blockInfo + '.');
      } catch (error) {
        if (transcriptBody) {
          transcriptBody.hidden = false;
          transcriptBody.textContent = error instanceof Error ? error.message : 'Could not publish evidence transcript.';
        }
      }
    });
  }

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
        transcriptReview?.stopPoll?.();
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
      if (['failed', 'error', 'invalid', 'rejected', 'evidence_failed'].includes(normalized)) {
        return 'intake-status-bad';
      }
      if (normalized === '') {
        return 'intake-status-muted';
      }
      return 'intake-status-pending';
    };
    const formatDurationShort = (seconds) => {
      const total = Math.max(0, Math.floor(Number(seconds || 0)));
      if (total < 60) {
        return total + 's';
      }
      const minutes = Math.floor(total / 60);
      const remainder = total % 60;
      if (remainder === 0) {
        return minutes + 'm';
      }
      return minutes + 'm ' + remainder + 's';
    };
    const formatAudioStatusLabel = (status) => {
      const normalized = String(status || '').trim().toLowerCase();
      if (normalized === '') {
        return 'UNKNOWN';
      }
      if (normalized === 'processing_evidence') {
        return 'PROCESSING EVIDENCE';
      }
      if (normalized === 'evidence_failed') {
        return 'EVIDENCE FAILED';
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
    const bulkRetryBtn = page.querySelector('[data-audio-evidence-retry-all]');
    let stuckEvidenceIds = [];

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
        stuckEvidenceIds = [];
        (payload.recordings || []).forEach((recording) => {
          byId[String(recording.id)] = recording;
          if (recording.can_retry_evidence) {
            stuckEvidenceIds.push(String(recording.id));
          }
        });
        if (bulkRetryBtn) {
          if (stuckEvidenceIds.length > 0) {
            bulkRetryBtn.hidden = false;
            bulkRetryBtn.textContent = 'Restart stuck evidence (' + stuckEvidenceIds.length + ')';
            bulkRetryBtn.disabled = !!bulkRetryBtn.dataset.retrying;
          } else {
            bulkRetryBtn.hidden = true;
          }
        }
        audioStatusCells.forEach((cell) => {
          const recordingId = cell.getAttribute('data-audio-recording-id');
          const recording = byId[String(recordingId)];
          if (!recording) {
            return;
          }
          const progress = Math.max(0, Math.min(100, Number(recording.display_progress ?? recording.transcription_progress ?? 0)));
          const displayStatus = String(recording.display_status || recording.pipeline_stage || recording.transcription_status || '');
          const progressEl = cell.querySelector('[data-audio-transcription-progress]');
          const fillEl = cell.querySelector('[data-audio-transcription-fill]');
          const statusEl = cell.querySelector('[data-audio-transcription-status]');
          const evidenceStepEl = cell.querySelector('[data-audio-evidence-step]');
          const evidenceWarningEl = cell.querySelector('[data-audio-evidence-warning]');
          const evidenceWarningTextEl = cell.querySelector('[data-audio-evidence-warning-text]');
          const evidenceRetryBtn = cell.querySelector('[data-audio-evidence-retry]');
          if (progressEl) {
            progressEl.hidden = false;
            progressEl.textContent = progress + '%';
          }
          if (fillEl) {
            fillEl.style.width = progress + '%';
            fillEl.classList.toggle('is-evidence-active', displayStatus === 'processing_evidence');
            fillEl.classList.toggle('is-evidence-failed', displayStatus === 'evidence_failed');
          }
          if (statusEl) {
            statusEl.textContent = formatAudioStatusLabel(displayStatus);
            statusEl.className = 'intake-status ' + audioStatusClass(displayStatus);
          }
          if (evidenceStepEl) {
            const stepLabel = String(recording.evidence_step_label || '').trim();
            const remainingSeconds = Number(recording.evidence_estimated_remaining_seconds || 0);
            const workerFailed = !!recording.evidence_worker_failed;
            if (displayStatus === 'processing_evidence' && stepLabel !== '' && !workerFailed) {
              evidenceStepEl.hidden = false;
              evidenceStepEl.textContent = stepLabel
                + (remainingSeconds > 0 ? (' · ~' + formatDurationShort(remainingSeconds) + ' left') : '');
            } else {
              evidenceStepEl.hidden = true;
              evidenceStepEl.textContent = '';
            }
          }
          if (evidenceWarningEl && evidenceWarningTextEl) {
            const canRetry = !!recording.can_retry_evidence;
            const failureReason = String(recording.evidence_worker_failure_reason || '').trim();
            const failureDetail = String(recording.evidence_worker_failure_detail || '').trim();
            if (canRetry) {
              evidenceWarningEl.hidden = false;
              if (failureReason !== '') {
                evidenceWarningTextEl.textContent = failureDetail !== ''
                  ? (failureReason + ' ' + failureDetail)
                  : failureReason;
              } else {
                evidenceWarningTextEl.textContent = 'Evidence has not finished building yet. Click Restart Evidence to start or resume.';
              }
            } else {
              evidenceWarningEl.hidden = true;
              evidenceWarningTextEl.textContent = '';
            }
          }
          if (evidenceRetryBtn) {
            evidenceRetryBtn.hidden = !recording.can_retry_evidence;
            evidenceRetryBtn.disabled = !!evidenceRetryBtn.dataset.retrying;
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

    if (bulkRetryBtn) {
      bulkRetryBtn.addEventListener('click', async () => {
        if (bulkRetryBtn.disabled || stuckEvidenceIds.length === 0) {
          return;
        }
        if (!window.confirm('Restart evidence for ' + stuckEvidenceIds.length + ' recording(s)? Each will run in the background.')) {
          return;
        }
        bulkRetryBtn.disabled = true;
        bulkRetryBtn.dataset.retrying = '1';
        bulkRetryBtn.textContent = 'Restarting…';
        try {
          const formData = new FormData();
          formData.append('recording_ids', stuckEvidenceIds.join(','));
          const response = await fetch('/admin/api/cockpit_recorder_intake_run_evidence_batch.php', {
            method: 'POST',
            body: formData,
            credentials: 'same-origin',
          });
          const payload = await response.json();
          if (!response.ok || (!payload.ok && Number(payload.started || 0) === 0)) {
            throw new Error(payload.error || 'Could not restart evidence for stuck recordings.');
          }
          await pollAudioStatus();
        } catch (error) {
          window.alert(error instanceof Error ? error.message : 'Could not restart stuck evidence.');
        } finally {
          delete bulkRetryBtn.dataset.retrying;
          bulkRetryBtn.disabled = false;
        }
      });
    }

    page.addEventListener('click', async (event) => {
      const retryButton = event.target instanceof Element
        ? event.target.closest('[data-audio-evidence-retry]')
        : null;
      if (!retryButton || retryButton.disabled) {
        return;
      }
      const cell = retryButton.closest('[data-audio-recording-id]');
      const recordingId = cell ? cell.getAttribute('data-audio-recording-id') : null;
      if (!recordingId || recordingId === '0') {
        return;
      }
      retryButton.disabled = true;
      retryButton.dataset.retrying = '1';
      retryButton.textContent = 'Restarting…';
      try {
        const formData = new FormData();
        formData.append('recording_id', recordingId);
        const response = await fetch('/admin/api/cockpit_recorder_intake_run_evidence.php', {
          method: 'POST',
          body: formData,
          credentials: 'same-origin',
        });
        const payload = await response.json();
        if (!response.ok || !payload.ok) {
          throw new Error(payload.error || payload.inline_error || 'Could not restart evidence processing.');
        }
        await pollAudioStatus();
      } catch (error) {
        window.alert(error instanceof Error ? error.message : 'Could not restart evidence processing.');
      } finally {
        delete retryButton.dataset.retrying;
        retryButton.disabled = false;
        retryButton.textContent = 'Restart Evidence';
      }
    });
  }

  // Operational Legs — edit modal + debrief copy panel
  (function initOperationalLegs() {
    const table = page.querySelector('[data-operational-legs-table]');
    const editModal = document.getElementById('legs-edit-modal');
    const debriefModal = document.getElementById('legs-debrief-modal');
    const crewBox = document.getElementById('legs-edit-crew');
    if (!table || !editModal || !debriefModal || !crewBox) {
      return;
    }

    const openModal = (modal) => { modal.hidden = false; };
    const closeModal = (modal) => { modal.hidden = true; };

    const renderCrew = (crew) => {
      crewBox.innerHTML = '';
      const list = Array.isArray(crew) && crew.length ? crew : [{ role: 'pic', name: '' }];
      list.forEach((member, index) => {
        const row = document.createElement('div');
        row.className = 'legs-crew-row';
        row.innerHTML =
          '<select name="leg[crew][' + index + '][role]">'
          + '<option value="pic">PIC</option>'
          + '<option value="sic">SIC</option>'
          + '<option value="instructor">Instructor</option>'
          + '<option value="student">Student</option>'
          + '<option value="observer">Observer</option>'
          + '</select>'
          + '<input type="text" name="leg[crew][' + index + '][personName]" placeholder="Name" value="">'
          + '<button type="button" class="intake-modal-close" data-legs-crew-remove>Remove</button>';
        const role = String(member.role || '').toLowerCase();
        const select = row.querySelector('select');
        const input = row.querySelector('input');
        if (select && role) {
          select.value = role;
        }
        if (input) {
          input.value = String(member.name || member.personName || '');
        }
        crewBox.appendChild(row);
      });
    };

    const fillEditForm = (leg) => {
      document.getElementById('legs-edit-dispatch-id').value = leg.dispatch_id || '';
      document.getElementById('legs-edit-timezone').value = leg.timezone || 'America/Los_Angeles';
      document.getElementById('legs-edit-aircraft').value = String(leg.aircraft_registration || '').toUpperCase();
      document.getElementById('legs-edit-mission').value = leg.mission_code || '';
      document.getElementById('legs-edit-dep').value = leg.departure_airport || '';
      document.getElementById('legs-edit-arr').value = leg.arrival_airport || '';
      document.getElementById('legs-edit-off').value = leg.off_block_local || '';
      document.getElementById('legs-edit-hobbs-start').value = leg.starting_hobbs ?? '';
      document.getElementById('legs-edit-hobbs-end').value = leg.ending_hobbs ?? '';
      document.getElementById('legs-edit-tacho-start').value = leg.starting_tacho ?? '';
      document.getElementById('legs-edit-tacho-end').value = leg.ending_tacho ?? '';
      document.getElementById('legs-edit-fuel-dep').value = leg.fuel_onboard || '';
      document.getElementById('legs-edit-fuel-ldg').value = leg.fuel_remaining || '';
      document.getElementById('legs-edit-oil-qty').value = leg.oil_quantity ?? '';
      document.getElementById('legs-edit-oil-unit').value = leg.oil_unit || '';
      document.getElementById('legs-edit-oil-pct').value = leg.oil_percentage ?? '';
      document.getElementById('legs-edit-to').value = leg.takeoff_count ?? 0;
      document.getElementById('legs-edit-ldg').value = leg.landing_count ?? 0;
      document.getElementById('legs-csv-flight-uuid').value = leg.workflow_flight_record_uuid || '';
      document.getElementById('legs-csv-aircraft').value = String(leg.aircraft_registration || '').toUpperCase();
      const csvSelect = document.getElementById('legs-csv-aircraft-id');
      if (csvSelect) {
        const reg = String(leg.aircraft_registration || '').toUpperCase();
        Array.from(csvSelect.options).forEach((opt) => {
          if (String(opt.getAttribute('data-reg') || '').toUpperCase() === reg) {
            csvSelect.value = opt.value;
          }
        });
      }
      renderCrew(leg.crew || []);
    };

    table.addEventListener('click', (event) => {
      const stop = event.target instanceof Element ? event.target.closest('[data-legs-stop]') : null;
      if (stop) {
        if (stop.matches('[data-legs-debrief]')) {
          event.preventDefault();
          event.stopPropagation();
          const debriefId = stop.getAttribute('data-debrief-id') || '0';
          const bundleId = stop.getAttribute('data-bundle-id') || '0';
          openDebrief(debriefId, bundleId);
        }
        return;
      }
      const row = event.target instanceof Element ? event.target.closest('tr[data-leg]') : null;
      if (!row) {
        return;
      }
      try {
        const leg = JSON.parse(row.getAttribute('data-leg') || '{}');
        fillEditForm(leg);
        openModal(editModal);
      } catch (error) {
        window.alert('Could not open leg editor.');
      }
    });

    editModal.querySelectorAll('[data-legs-edit-close]').forEach((btn) => {
      btn.addEventListener('click', () => closeModal(editModal));
    });
    debriefModal.querySelectorAll('[data-legs-debrief-close]').forEach((btn) => {
      btn.addEventListener('click', () => closeModal(debriefModal));
    });

    document.getElementById('legs-edit-add-crew')?.addEventListener('click', () => {
      const current = Array.from(crewBox.querySelectorAll('.legs-crew-row')).map((row) => ({
        role: row.querySelector('select')?.value || 'student',
        name: row.querySelector('input')?.value || '',
      }));
      current.push({ role: 'student', name: '' });
      renderCrew(current);
    });
    crewBox.addEventListener('click', (event) => {
      const btn = event.target instanceof Element ? event.target.closest('[data-legs-crew-remove]') : null;
      if (!btn) {
        return;
      }
      btn.closest('.legs-crew-row')?.remove();
      const current = Array.from(crewBox.querySelectorAll('.legs-crew-row')).map((row) => ({
        role: row.querySelector('select')?.value || 'student',
        name: row.querySelector('input')?.value || '',
      }));
      renderCrew(current.length ? current : [{ role: 'pic', name: '' }]);
    });

    async function openDebrief(debriefId, bundleId) {
      const box = document.getElementById('legs-debrief-copy-box');
      const message = document.getElementById('legs-debrief-message');
      const generateForm = document.getElementById('legs-debrief-generate-form');
      const bundleInput = document.getElementById('legs-debrief-bundle-id');
      box.textContent = 'Loading…';
      message.textContent = '';
      generateForm.hidden = true;
      bundleInput.value = bundleId || '';
      openModal(debriefModal);
      try {
        const url = '/admin/api/operational_leg_debrief_copy.php?debrief_id='
          + encodeURIComponent(debriefId || '0')
          + '&bundle_id=' + encodeURIComponent(bundleId || '0');
        const response = await fetch(url, { credentials: 'same-origin' });
        const payload = await response.json();
        if (payload.copy_text) {
          box.textContent = payload.copy_text;
        } else {
          box.textContent = payload.message || 'No debrief text available.';
        }
        message.textContent = payload.ok ? '' : (payload.message || '');
        if (!payload.ok && Number(bundleId) > 0) {
          generateForm.hidden = false;
        }
      } catch (error) {
        box.textContent = error instanceof Error ? error.message : 'Could not load debrief.';
      }
    }

    document.getElementById('legs-debrief-copy')?.addEventListener('click', async () => {
      const text = document.getElementById('legs-debrief-copy-box')?.textContent || '';
      try {
        await navigator.clipboard.writeText(text);
        document.getElementById('legs-debrief-message').textContent = 'Copied to clipboard.';
      } catch (error) {
        window.alert('Copy failed. Select the text manually.');
      }
    });
  })();
})();
</script>
<?php cw_footer(); ?>
