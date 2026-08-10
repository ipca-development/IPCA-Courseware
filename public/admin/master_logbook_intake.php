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
require_once __DIR__ . '/../../src/CvrAdminLegSplitService.php';
require_once __DIR__ . '/../../src/CvrOperationalLegVisibilityService.php';
require_once __DIR__ . '/../../src/CockpitRecorderDebriefQueueService.php';
require_once __DIR__ . '/../../src/MissionCatalogService.php';
require_once __DIR__ . '/../../src/AircraftOperationalConfigService.php';
require_once __DIR__ . '/../../src/AircraftFleetStatusService.php';
require_once __DIR__ . '/../../src/AircraftFuelUpliftService.php';
require_once __DIR__ . '/../../src/CvrFinancialDispatchService.php';

if (!isset($cvrMasterLogbookContext) || !is_array($cvrMasterLogbookContext)) {
    $cvrMasterLogbookContext = array(
        'audience' => 'admin',
        'base_path' => '/admin/master_logbook.php',
        'can_enroll' => true,
        'can_remove' => true,
        'can_edit' => true,
        'show_audio' => true,
        'show_garmin' => true,
        'show_reconstruction' => true,
    );
}

$cvrMlAudience = strtolower(trim((string)($cvrMasterLogbookContext['audience'] ?? 'admin')));
$cvrMlBasePath = trim((string)($cvrMasterLogbookContext['base_path'] ?? '/admin/master_logbook.php'));
if ($cvrMlBasePath === '' || !str_starts_with($cvrMlBasePath, '/')) {
    $cvrMlBasePath = '/admin/master_logbook.php';
}
$cvrMlCanEnroll = !empty($cvrMasterLogbookContext['can_enroll']);
$cvrMlCanRemove = !empty($cvrMasterLogbookContext['can_remove']);
$cvrMlCanEdit = !empty($cvrMasterLogbookContext['can_edit']);
$cvrMlCanManageFuelUplifts = $cvrMlAudience === 'admin';
$cvrMlShowAudio = !empty($cvrMasterLogbookContext['show_audio']);
$cvrMlShowGarmin = !empty($cvrMasterLogbookContext['show_garmin']);
$cvrMlShowReconstruction = !empty($cvrMasterLogbookContext['show_reconstruction']);
$GLOBALS['cvrMlBasePath'] = $cvrMlBasePath;
$GLOBALS['cvrMlCanRemove'] = $cvrMlCanRemove;

if ($cvrMlAudience === 'instructor') {
    cw_require_login();
    $viewer = cw_current_user($pdo) ?: array();
    $viewerRole = strtolower(trim((string)($viewer['role'] ?? '')));
    if (!in_array($viewerRole, array('admin', 'supervisor', 'instructor', 'chief_instructor'), true)) {
        redirect(cw_home_path_for_role($viewerRole));
    }
} else {
    cw_require_admin();
}

$enrollmentResult = null;
$enrollmentError = '';
$enrollmentCsrf = (string)($_SESSION['cvr_intake_enrollment_csrf'] ?? '');
if ($enrollmentCsrf === '') {
    $enrollmentCsrf = bin2hex(random_bytes(24));
    $_SESSION['cvr_intake_enrollment_csrf'] = $enrollmentCsrf;
}
$aircraftOptions = array();
$flightMissions = array();
$crewCatalogUsers = array();
$aircraftUnitMap = array();
$customerBalanceMap = array();
try {
    $aircraftService = new CockpitAircraftService($pdo);
    $aircraftOptions = $aircraftService->activeAircraft();
    $configService = new AircraftOperationalConfigService($pdo);
    foreach ($aircraftOptions as $aircraftOption) {
        $reg = strtoupper(trim((string)($aircraftOption['registration'] ?? '')));
        if ($reg === '') {
            continue;
        }
        $cfg = $configService->configForAircraft((int)($aircraftOption['id'] ?? 0));
        $aircraftUnitMap[$reg] = array(
            'id' => (int)($aircraftOption['id'] ?? 0),
            'fuel_unit' => strtoupper(trim((string)($cfg['fuel_unit'] ?? 'USG'))) ?: 'USG',
            'fuel_capacity' => (float)($cfg['fuel_capacity'] ?? 13.0),
            'oil_unit' => trim((string)($cfg['oil_unit'] ?? '%')) ?: '%',
            'oil_capacity' => (float)($cfg['oil_capacity'] ?? 100.0),
        );
    }
    // Assign distinct pill colors by sorted fleet order (not hash collisions).
    $fleetRegs = array_keys($aircraftUnitMap);
    sort($fleetRegs, SORT_STRING);
    $GLOBALS['cvr_intake_aircraft_pill_index'] = array();
    foreach ($fleetRegs as $fleetIdx => $fleetReg) {
        $GLOBALS['cvr_intake_aircraft_pill_index'][$fleetReg] = $fleetIdx;
    }
    foreach ((new MissionCatalogService($pdo))->listMissions() as $missionRow) {
        $code = strtoupper(trim((string)($missionRow['code'] ?? '')));
        $name = trim((string)($missionRow['name'] ?? ''));
        $description = trim((string)($missionRow['description'] ?? $name));
        $status = strtolower(trim((string)($missionRow['status'] ?? 'active')));
        if ($code === '' || ($status !== '' && $status !== 'active')) {
            continue;
        }
        if (!cvr_intake_is_aircraft_flight_mission($code, $description . ' ' . $name)) {
            continue;
        }
        $flightMissions[] = array(
            'code' => $code,
            'name' => $name !== '' ? $name : $description,
            'label' => $code . ' — ' . ($name !== '' ? $name : $description),
        );
    }
    $userStatement = $pdo->query(
        "SELECT id, COALESCE(NULLIF(TRIM(name), ''), email) AS display_name, role"
        . " FROM users WHERE role IN ('student', 'instructor', 'supervisor', 'chief_instructor')"
        . " AND status = 'active'"
        . ' ORDER BY display_name ASC, id ASC'
    );
    $crewCatalogUsers = $userStatement ? ($userStatement->fetchAll(PDO::FETCH_ASSOC) ?: array()) : array();
    $customerBalanceMap = array();
    try {
        $balanceStatement = $pdo->query('SELECT user_id, balance_usd FROM ipca_user_account_balances');
        foreach (($balanceStatement ? $balanceStatement->fetchAll(PDO::FETCH_ASSOC) : array()) ?: array() as $balanceRow) {
            $customerBalanceMap[(int)($balanceRow['user_id'] ?? 0)] = (float)($balanceRow['balance_usd'] ?? 0);
        }
    } catch (Throwable) {
        $customerBalanceMap = array();
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
        && (string)($_POST['action'] ?? '') === 'create_cvr_enrollment') {
        if (!$cvrMlCanEnroll) {
            throw new RuntimeException('CVR enrollment is not available in this view.');
        }
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
    $reconstructionNotice = 'AI Debrief generation started in the background. Progress updates live below.';
} elseif ((string)($_GET['debrief_generation'] ?? '') === 'running') {
    $reconstructionNotice = 'AI Debrief generation is already running. Progress updates live below.';
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
        $adminOnlyActions = array(
            'freeze_reconstruction_bundle',
            'supersede_reconstruction_bundle',
            'upload_manual_garmin_csv',
            'upload_manual_audio',
            'retry_reconstruction_bundle',
            'lock_bundle_transcript',
            'rebuild_bundle_flight_record',
            'save_debrief_review',
            'verify_debrief',
            'reject_debrief',
            'release_debrief',
            'create_replay_share',
            'revoke_replay_share',
            'generate_bundle_debrief',
            'hide_operational_leg',
            'restore_operational_leg',
            'split_operational_leg',
            'create_fuel_uplift',
            'delete_fuel_uplift',
        );
        if ($cvrMlAudience === 'instructor') {
            if (in_array($action, $adminOnlyActions, true) || $action === 'create_cvr_enrollment') {
                throw new RuntimeException('This action is not available in the instructor Master Logbook.');
            }
            if ($action === 'save_operational_leg' && !$cvrMlCanEdit) {
                throw new RuntimeException('Editing operational legs is not available in this view.');
            }
            if (!in_array($action, array('save_operational_leg', 'unlock_financial_dispatch'), true)) {
                throw new RuntimeException('This action is not available in the instructor Master Logbook.');
            }
        }
        if (in_array($action, array('hide_operational_leg', 'restore_operational_leg'), true) && !$cvrMlCanRemove) {
            throw new RuntimeException('Removing operational legs is not available in this view.');
        }
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
            $dispatchId = (int)($_POST['dispatch_id'] ?? 0);
            $legInput = is_array($_POST['leg'] ?? null) ? $_POST['leg'] : $_POST;
            $segmentInputs = is_array($_POST['leg_segments'] ?? null) ? $_POST['leg_segments'] : array();
            $lockFinancial = !empty($_POST['lock_financial']);
            $financialService = new CvrFinancialDispatchService($pdo);
            $existingFinancial = $financialService->forDispatch($dispatchId);
            if (is_array($existingFinancial) && !empty($existingFinancial['is_locked']) && !$lockFinancial) {
                throw new RuntimeException('Unlock this Operational Leg before editing.');
            }
            $correction = new CvrAdminLegCorrectionService($pdo);
            if ($segmentInputs !== array()) {
                $totalTakeoffs = 0;
                $totalLandings = 0;
                $normalizedSegments = array();
                $ordered = array_values($segmentInputs);
                foreach ($ordered as $index => $segment) {
                    if (!is_array($segment)) {
                        continue;
                    }
                    $seq = max(1, (int)($segment['sequence_number'] ?? ((int)$index + 1)));
                    $takeoffs = max(0, (int)($segment['takeoff_count'] ?? 0));
                    $landings = max(0, (int)($segment['landing_count'] ?? 0));
                    $totalTakeoffs += $takeoffs;
                    $totalLandings += $landings;
                    $normalized = array(
                        'sequence_number' => $seq,
                        'dispatch_id' => (int)($segment['dispatch_id'] ?? 0),
                        'departure_airport' => strtoupper(trim((string)($segment['departure_airport'] ?? ''))),
                        'arrival_airport' => strtoupper(trim((string)($segment['arrival_airport'] ?? ''))),
                        'starting_hobbs' => $segment['starting_hobbs'] ?? null,
                        'ending_hobbs' => $segment['ending_hobbs'] ?? null,
                        'starting_tacho' => $segment['starting_tacho'] ?? null,
                        'ending_tacho' => $segment['ending_tacho'] ?? null,
                        'fuel_onboard' => $segment['fuel_onboard'] ?? null,
                        'fuel_remaining' => $segment['fuel_remaining'] ?? null,
                        'takeoff_count' => $takeoffs,
                        'landing_count' => $landings,
                    );
                    $normalizedSegments[] = $normalized;
                    $segmentDispatchId = (int)($segment['dispatch_id'] ?? 0);
                    if ($segmentDispatchId > 0) {
                        $correction->saveSegmentOperationalValues(
                            $segmentDispatchId,
                            $normalized,
                            $actorUserId > 0 ? $actorUserId : null
                        );
                    }
                }
                if ($normalizedSegments !== array()) {
                    $firstSeg = $normalizedSegments[0];
                    $lastSeg = $normalizedSegments[count($normalizedSegments) - 1];
                    $legInput['starting_hobbs'] = $firstSeg['starting_hobbs'];
                    $legInput['ending_hobbs'] = $lastSeg['ending_hobbs'];
                    $legInput['starting_tacho'] = $firstSeg['starting_tacho'];
                    $legInput['ending_tacho'] = $lastSeg['ending_tacho'];
                    $legInput['fuel_onboard'] = $firstSeg['fuel_onboard'];
                    $legInput['fuel_remaining'] = $lastSeg['fuel_remaining'];
                }
                $legInput['takeoff_count'] = $totalTakeoffs;
                $legInput['landing_count'] = $totalLandings;
                $correction->patchLegSegmentOperationalFields($dispatchId, $normalizedSegments);
            }
            $correction->save(
                $dispatchId,
                $legInput,
                $actorUserId > 0 ? $actorUserId : null
            );
            $financialInput = is_array($_POST['financial'] ?? null) ? $_POST['financial'] : array();
            if ($financialInput !== array() || $lockFinancial) {
                if (is_array($existingFinancial) && !empty($existingFinancial['is_locked']) && !$lockFinancial) {
                    // Locked financial stays unchanged on normal Save Changes.
                } else {
                    $hs = isset($legInput['starting_hobbs']) && is_numeric($legInput['starting_hobbs'])
                        ? (float)$legInput['starting_hobbs'] : null;
                    $he = isset($legInput['ending_hobbs']) && is_numeric($legInput['ending_hobbs'])
                        ? (float)$legInput['ending_hobbs'] : null;
                    if ($hs !== null && $he !== null && $he >= $hs) {
                        $financialInput['aircraft_rental_hours'] = round($he - $hs, 1);
                        $financialInput['hobbs_delta'] = $financialInput['aircraft_rental_hours'];
                    }
                    $financialService->saveDraft(
                        $dispatchId,
                        $financialInput,
                        $actorUserId > 0 ? $actorUserId : null,
                        $lockFinancial
                    );
                }
            }
            $reconstructionNotice = $lockFinancial
                ? 'Operational leg saved and locked.'
                : 'Operational leg updated.';
        } elseif ($action === 'unlock_financial_dispatch') {
            (new CvrFinancialDispatchService($pdo))->unlock(
                (int)($_POST['dispatch_id'] ?? 0),
                $actorUserId > 0 ? $actorUserId : null,
                $cvrMlAudience,
                (string)($_POST['unlock_code'] ?? ''),
                trim((string)($_POST['unlock_reason'] ?? ''))
            );
            $reconstructionNotice = 'Operational leg unlocked for editing.';
        } elseif ($action === 'split_operational_leg') {
            if ($cvrMlAudience !== 'admin' || !$cvrMlCanEdit) {
                throw new RuntimeException('Define Legs is only available to admins.');
            }
            $splitResult = (new CvrAdminLegSplitService($pdo))->apply(
                (int)($_POST['dispatch_id'] ?? 0),
                is_array($_POST['split'] ?? null) ? $_POST['split'] : array(),
                $actorUserId > 0 ? $actorUserId : null
            );
            $reconstructionNotice = 'Annotated continuous Check-In with '
                . (int)($splitResult['leg_count'] ?? 0)
                . ' legs. Replay, CSV, and financials stay on this Operational Leg.';
        } elseif ($action === 'hide_operational_leg') {
            (new CvrOperationalLegVisibilityService($pdo))->hide(
                (int)($_POST['dispatch_id'] ?? 0),
                $actorUserId > 0 ? $actorUserId : null,
                trim((string)($_POST['reason'] ?? '')) ?: 'Removed from Master Logbook list'
            );
            $reconstructionNotice = 'Operational leg removed from the list. Evidence is retained and can be restored.';
        } elseif ($action === 'restore_operational_leg') {
            (new CvrOperationalLegVisibilityService($pdo))->restore(
                (int)($_POST['dispatch_id'] ?? 0),
                $actorUserId > 0 ? $actorUserId : null
            );
            $reconstructionNotice = 'Operational leg restored to the list.';
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
                ? 'AI Debrief generation is already running. Progress updates live below.'
                : 'AI Debrief generation started in the background. Progress updates live below.';
        } elseif ($action === 'create_fuel_uplift') {
            if (!$cvrMlCanManageFuelUplifts) {
                throw new RuntimeException('Fuel uplift logging is only available to admins.');
            }
            $registration = strtoupper(trim((string)($_POST['aircraft_registration'] ?? '')));
            $aircraftId = (int)($_POST['aircraft_id'] ?? 0);
            if ($aircraftId <= 0) {
                foreach ($aircraftOptions as $aircraftOption) {
                    if (strtoupper(trim((string)($aircraftOption['registration'] ?? ''))) === $registration) {
                        $aircraftId = (int)($aircraftOption['id'] ?? 0);
                        break;
                    }
                }
            }
            (new AircraftFuelUpliftService($pdo))->create(
                $aircraftId,
                $registration,
                (float)($_POST['fuel_after_usg'] ?? 0),
                trim((string)($_POST['uplifted_at_local'] ?? '')) !== ''
                    ? trim((string)$_POST['uplifted_at_local'])
                    : null,
                trim((string)($_POST['notes'] ?? '')),
                $actorUserId > 0 ? $actorUserId : null,
                cvr_intake_california_timezone($pdo, $registration)
            );
            $reconstructionNotice = 'Fuel uplift logged for ' . $registration . '.';
        } elseif ($action === 'delete_fuel_uplift') {
            if (!$cvrMlCanManageFuelUplifts) {
                throw new RuntimeException('Deleting fuel uplifts is only available to admins.');
            }
            (new AircraftFuelUpliftService($pdo))->softDelete(
                (int)($_POST['uplift_id'] ?? 0),
                $actorUserId > 0 ? $actorUserId : null
            );
            $reconstructionNotice = 'Fuel uplift deleted.';
        }
    }
} catch (Throwable $e) {
    $reconstructionError = $e->getMessage();
}

$legsAircraftFilter = strtoupper(trim((string)($_GET['legs_aircraft'] ?? '')));
$legsFrom = trim((string)($_GET['legs_from'] ?? ''));
$legsTo = trim((string)($_GET['legs_to'] ?? ''));
$legsShowRemoved = $cvrMlCanRemove && ((string)($_GET['legs_show_removed'] ?? '')) === '1';
$legsPage = max(1, (int)($_GET['legs_page'] ?? 1));
$legsLimit = 30;
$legsOffset = ($legsPage - 1) * $legsLimit;
$dispatch = $intake->dispatchRows(
    $legsLimit,
    $legsOffset,
    $legsAircraftFilter !== '' ? $legsAircraftFilter : null,
    $legsFrom !== '' ? $legsFrom : null,
    $legsTo !== '' ? $legsTo : null,
    'America/Los_Angeles',
    false,
    $legsShowRemoved
);
$legsTotal = (int)($dispatch['total'] ?? count($dispatch['rows']));
$legsPageCount = max(1, (int)($dispatch['page_count'] ?? 1));
$legsPage = max(1, min($legsPageCount, (int)($dispatch['page'] ?? $legsPage)));
$instructionalRates = array();
$aircraftRentalRateMap = array();
$financialByDispatchId = array();
$financialDispatchError = '';
try {
    $financialService = new CvrFinancialDispatchService($pdo);
    $instructionalRates = $financialService->instructionalRates();
    $aircraftRentalRateMap = $financialService->aircraftRentalRateMap();
    $dispatchIds = array();
    foreach (($dispatch['rows'] ?? array()) as $dispatchRow) {
        $dispatchIds[] = (int)($dispatchRow['id'] ?? 0);
    }
    $financialByDispatchId = $financialService->mapForDispatchIds($dispatchIds);
} catch (Throwable $e) {
    $financialDispatchError = $e->getMessage();
    error_log('[CvrFinancialDispatch] ' . $e->getMessage());
}
$fleetStatusCards = array();
$fleetStatusError = '';
try {
    $fleetStatusCards = (new AircraftFleetStatusService($pdo))->cardsForAircraft($aircraftOptions, $aircraftUnitMap);
} catch (Throwable $e) {
    $fleetStatusCards = array();
    $fleetStatusError = $e->getMessage();
    error_log('[AircraftFleetStatus] ' . $e->getMessage());
}
$audioShortThresholdSeconds = 600;
$audioShortRowCount = 0;
$audioVisibleRowCount = 0;
if ($cvrMlShowAudio) {
    $audio = $intake->audioRows();
    if ($audio['available'] && $audio['rows'] !== array()) {
        $audio['rows'] = (new CvrAudioIntakeMetricsService($pdo))->enrichRows($audio['rows']);
    }
    foreach ($audio['rows'] as $audioRow) {
        $audioDurationSeconds = (float)($audioRow['duration_seconds'] ?? 0);
        if ($audioDurationSeconds > 0 && $audioDurationSeconds < $audioShortThresholdSeconds) {
            $audioShortRowCount++;
        }
    }
    $audioVisibleRowCount = max(0, count($audio['rows']) - $audioShortRowCount);
} else {
    $audio = array('available' => false, 'rows' => array(), 'message' => '');
}
$garmin = $cvrMlShowGarmin
    ? $intake->garminRows()
    : array('available' => false, 'rows' => array(), 'message' => '');
$reconstructionBundles = $cvrMlShowReconstruction
    ? $reconstructionService->recentBundles()
    : array();
$selectedDebrief = null;
if ($cvrMlShowReconstruction && (int)($_GET['debrief_id'] ?? 0) > 0) {
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

/** Match CVR unit flight-mission filter (exclude FSTD/sim/ground). */
function cvr_intake_is_aircraft_flight_mission(string $code, string $description): bool
{
    $haystack = strtoupper($description . ' ' . $code);
    foreach (array('FSTD', 'SIMULATOR', 'AATD', 'FNPT', 'BRIEFING', 'THEORY', 'MEETING', 'GROUND SCHOOL', 'CLASSROOM') as $token) {
        if (str_contains($haystack, $token)) {
            return false;
        }
    }
    if (preg_match('/\bLB\b/', $haystack) === 1) {
        $hasFlight = str_contains($haystack, 'DUAL')
            || str_contains($haystack, 'PIC')
            || str_contains($haystack, 'SOLO');
        if (!$hasFlight) {
            return false;
        }
    }
    return str_contains($haystack, 'DUAL')
        || str_contains($haystack, 'PIC')
        || str_contains($haystack, 'SOLO')
        || str_contains($haystack, 'NIGHT')
        || str_contains($haystack, 'X-C')
        || str_contains($haystack, 'XC');
}

/**
 * Distinct background colors per aircraft registration pill.
 * @return array{bg:string,fg:string,border:string}
 */
function cvr_intake_aircraft_pill_palette(): array
{
    // High-contrast fleet colors — avoid adjacent green/teal lookalikes.
    return array(
        array('bg' => '#dbeafe', 'fg' => '#1e3a8a', 'border' => '#60a5fa'), // blue
        array('bg' => '#ffedd5', 'fg' => '#9a3412', 'border' => '#fb923c'), // orange
        array('bg' => '#ede9fe', 'fg' => '#5b21b6', 'border' => '#a78bfa'), // violet
        array('bg' => '#dcfce7', 'fg' => '#14532d', 'border' => '#4ade80'), // green
        array('bg' => '#ffe4e6', 'fg' => '#9f1239', 'border' => '#fb7185'), // rose
        array('bg' => '#fef08a', 'fg' => '#713f12', 'border' => '#eab308'), // yellow
        array('bg' => '#e0e7ff', 'fg' => '#312e81', 'border' => '#818cf8'), // indigo
        array('bg' => '#fce7f3', 'fg' => '#9d174d', 'border' => '#f472b6'), // pink
        array('bg' => '#cffafe', 'fg' => '#155e75', 'border' => '#22d3ee'), // cyan
        array('bg' => '#fecaca', 'fg' => '#7f1d1d', 'border' => '#f87171'), // red
    );
}

function cvr_intake_aircraft_pill_colors(string $registration): array
{
    $palette = cvr_intake_aircraft_pill_palette();
    $reg = strtoupper(trim($registration));
    $map = $GLOBALS['cvr_intake_aircraft_pill_index'] ?? null;
    if (is_array($map) && array_key_exists($reg, $map)) {
        $idx = (int)$map[$reg] % count($palette);
    } else {
        // Stable fallback when registration is outside the active fleet list.
        $idx = abs((int)crc32($reg)) % count($palette);
    }
    return $palette[$idx];
}

function cvr_intake_aircraft_pill_html(string $registration): string
{
    $reg = strtoupper(trim($registration));
    if ($reg === '') {
        return '<span class="legs-blank">—</span>';
    }
    $c = cvr_intake_aircraft_pill_colors($reg);
    return '<span class="ml-aircraft-pill" style="background:' . cvr_intake_h($c['bg'])
        . ';color:' . cvr_intake_h($c['fg'])
        . ';border-color:' . cvr_intake_h($c['border']) . '">'
        . cvr_intake_h($reg) . '</span>';
}

function cvr_intake_meter_cell(mixed $start, mixed $end): string
{
    $startLabel = is_numeric($start) ? number_format((float)$start, 1, '.', '') : '—';
    $endLabel = is_numeric($end) ? number_format((float)$end, 1, '.', '') : '—';
    if ($startLabel === '—' && $endLabel === '—') {
        return '<span class="legs-blank">—</span>';
    }
    return '<div class="legs-meter" title="' . cvr_intake_h($startLabel . ' → ' . $endLabel) . '">'
        . '<span class="legs-meter-start">' . cvr_intake_h($startLabel) . '</span>'
        . '<span class="legs-meter-end">' . cvr_intake_h($endLabel) . '</span>'
        . '</div>';
}

function cvr_intake_crew_role_short(string $role): string
{
    $key = strtolower(trim(str_replace(array(' ', '-'), '_', $role)));
    return match ($key) {
        'pic', 'pilot_in_command' => 'PIC',
        'student' => 'STU',
        'flight_instructor', 'instructor', 'cfi' => 'CFI',
        'supervising_instructor', 'supervisor' => 'SUP',
        'pilot_flying', 'pf' => 'PF',
        'pilot_monitoring', 'pm' => 'PM',
        'examiner' => 'EXM',
        'safety_pilot' => 'SAFE',
        default => strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $role) ?: 'CREW', 0, 4)),
    };
}

function cvr_intake_format_one_decimal(mixed $value): string
{
    if (!is_numeric($value)) {
        return '';
    }
    return number_format((float)$value, 1, '.', '');
}

function cvr_intake_airport_place(string $icao): string
{
    static $places = array(
        'KTRM' => 'Thermal, CA',
        'KPSP' => 'Palm Springs, CA',
        'KHMT' => 'Hemet, CA',
        'KUDD' => 'Bermuda Dunes, CA',
        'KBNG' => 'Banning, CA',
        'KRAL' => 'Riverside, CA',
        'KSBD' => 'San Bernardino, CA',
        'KBLH' => 'Blythe, CA',
        'KCRQ' => 'Carlsbad, CA',
        'KMYF' => 'San Diego, CA',
        'KSAN' => 'San Diego, CA',
        'KONT' => 'Ontario, CA',
        'KLAX' => 'Los Angeles, CA',
        'KBUR' => 'Burbank, CA',
        'KVNY' => 'Van Nuys, CA',
        'KSNA' => 'Santa Ana, CA',
        'KLGB' => 'Long Beach, CA',
        'EBAW' => 'Antwerp, BE',
    );
    $code = strtoupper(trim($icao));
    return $places[$code] ?? '';
}

function cvr_intake_crew_role_pill(string $role): string
{
    $key = strtolower(trim(str_replace(array(' ', '-'), '_', $role)));
    $label = match ($key) {
        'pic', 'pilot_in_command' => 'PIC',
        'student' => 'STUDENT',
        'flight_instructor', 'instructor', 'cfi' => 'INSTRUCTOR',
        'supervising_instructor', 'supervisor' => 'SUPERVISOR',
        'pilot_flying', 'pf' => 'PF',
        'pilot_monitoring', 'pm' => 'PM',
        'examiner' => 'EXAMINER',
        'safety_pilot' => 'SAFETY',
        default => strtoupper($role !== '' ? $role : 'CREW'),
    };
    $tone = match ($label) {
        'PIC' => 'pic',
        'STUDENT' => 'student',
        'INSTRUCTOR', 'SUPERVISOR', 'CFI' => 'instructor',
        default => 'muted',
    };
    return '<span class="legs-role-pill legs-role-' . $tone . '">' . cvr_intake_h($label) . '</span>';
}

/**
 * @return array{pct:float,label:string}
 */
function cvr_intake_oil_gauge(array $row, array $aircraftCfg): array
{
    $unit = strtoupper(trim((string)($aircraftCfg['oil_unit'] ?? $row['oil_unit'] ?? '%')));
    $pct = null;
    if (is_numeric($row['oil_percentage'] ?? null)) {
        $pct = (float)$row['oil_percentage'];
    } elseif (is_numeric($row['oil_quantity'] ?? null) && str_contains($unit, '%')) {
        $pct = (float)$row['oil_quantity'];
    } elseif (is_numeric($row['oil_quantity'] ?? null)) {
        $cap = (float)($aircraftCfg['oil_capacity'] ?? 0);
        if ($cap > 0) {
            $pct = ((float)$row['oil_quantity'] / $cap) * 100.0;
        }
    }
    if ($pct === null) {
        return array('pct' => 0.0, 'label' => '—');
    }
    $pct = max(0.0, min(100.0, $pct));
    return array('pct' => $pct, 'label' => (string)(int)round($pct) . '%');
}

/**
 * Fuel remaining gauge from landing fuel vs aircraft capacity.
 * @return array{pct:float,label:string}
 */
function cvr_intake_fuel_remaining_gauge(mixed $fuelLanding, array $aircraftCfg): array
{
    if (!is_numeric($fuelLanding)) {
        return array('pct' => 0.0, 'label' => '—');
    }
    $cap = (float)($aircraftCfg['fuel_capacity'] ?? 0);
    if ($cap <= 0) {
        $cap = 13.0;
    }
    $pct = max(0.0, min(100.0, ((float)$fuelLanding / $cap) * 100.0));
    return array('pct' => $pct, 'label' => cvr_intake_format_one_decimal($fuelLanding));
}

function cvr_intake_evidence_icon(string $state): string
{
    return match ($state) {
        'ok' => '<span class="legs-ev-icon legs-ev-ok" title="Complete" aria-label="Complete">✓</span>',
        'warn' => '<span class="legs-ev-icon legs-ev-warn" title="Pending" aria-label="Pending">○</span>',
        'bad' => '<span class="legs-ev-icon legs-ev-bad" title="Issue" aria-label="Issue">!</span>',
        default => '<span class="legs-ev-icon legs-ev-na" title="Not available" aria-label="Not available">–</span>',
    };
}

function cvr_intake_duration_minutes(mixed $blockHours, string $offLocal, string $onLocal): string
{
    if (is_numeric($blockHours)) {
        $mins = (int)round(((float)$blockHours) * 60);
        if ($mins >= 0) {
            return $mins . 'm';
        }
    }
    if (preg_match('/^(\d{2}):(\d{2})$/', $offLocal, $a) && preg_match('/^(\d{2}):(\d{2})$/', $onLocal, $b)) {
        $start = ((int)$a[1] * 60) + (int)$a[2];
        $end = ((int)$b[1] * 60) + (int)$b[2];
        $diff = $end - $start;
        if ($diff < 0) {
            $diff += 1440;
        }
        return $diff . 'm';
    }
    return '—';
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
    $timezone = cvr_intake_california_timezone($pdo, $registration);
    return cw_logbook_datetime($text, $timezone);
}

/** Fleet card timestamp: "Thu Aug 6, 2026 - 10:20 LT" */
function cvr_intake_fleet_logged_label(PDO $pdo, mixed $value, string $registration = ''): string
{
    $parts = cvr_intake_fleet_logged_parts($pdo, $value, $registration);
    if ($parts['empty']) {
        return 'No flight logged yet';
    }
    return trim($parts['date'] . ' - ' . $parts['time']);
}

/**
 * Build financial dispatch payload for Edit Operational Leg modal.
 *
 * @param list<array<string,mixed>> $crewMembers
 * @param array<string,mixed>|null $saved
 * @param array<string,array<string,mixed>> $rentalMap
 * @param list<array<string,mixed>> $rates
 * @return array<string,mixed>
 */
function cvr_intake_financial_leg_payload(
    PDO $pdo,
    int $dispatchId,
    string $registration,
    array $crewMembers,
    float $hobbsDelta,
    ?array $saved,
    array $rentalMap,
    array $rates
): array {
    $customerId = 0;
    $customerName = '';
    $instructorId = 0;
    $instructorName = '';
    $firstPilotId = 0;
    $firstPilotName = '';
    $secondPilotId = 0;
    $secondPilotName = '';
    foreach ($crewMembers as $member) {
        if (!is_array($member)) {
            continue;
        }
        $name = trim((string)($member['name'] ?? $member['personName'] ?? $member['person_name'] ?? ''));
        $role = strtolower(trim((string)($member['role'] ?? '')));
        $personId = (int)($member['person_id'] ?? $member['id'] ?? 0);
        if ($name === '' && $personId <= 0) {
            continue;
        }
        if ($firstPilotId <= 0 && $firstPilotName === '' && ($role === '' || str_contains($role, 'student') || str_contains($role, 'pic') || str_contains($role, 'pilot'))) {
            $firstPilotId = $personId;
            $firstPilotName = $name;
        } elseif ($secondPilotId <= 0 && $secondPilotName === '' && $personId !== $firstPilotId) {
            $secondPilotId = $personId;
            $secondPilotName = $name;
        }
        if (str_contains($role, 'student') && $customerId <= 0) {
            $customerId = $personId;
            $customerName = $name;
        }
        if ((str_contains($role, 'instructor') || str_contains($role, 'supervisor')) && $instructorId <= 0) {
            $instructorId = $personId;
            $instructorName = $name;
        }
    }
    if ($customerId <= 0 && $customerName === '') {
        $customerId = $firstPilotId;
        $customerName = $firstPilotName;
    }
    if ($instructorId <= 0 && $instructorName === '') {
        $instructorId = $secondPilotId;
        $instructorName = $secondPilotName;
    }

    $defaultRate = $rates[0] ?? null;
    $rental = $rentalMap[strtoupper($registration)] ?? array(
        'display_label' => $registration,
        'rate_usd_per_hour' => 0.0,
    );
    $balance = 0.0;
    if ($customerId > 0) {
        try {
            $balance = (new CvrFinancialDispatchService($pdo))->balanceForUser($customerId);
        } catch (Throwable) {
            $balance = 0.0;
        }
    }

    $base = array(
        'dispatch_id' => $dispatchId,
        'customer_user_id' => $customerId > 0 ? $customerId : null,
        'customer_name' => $customerName,
        'instructor_user_id' => $instructorId > 0 ? $instructorId : null,
        'instructor_name' => $instructorName,
        'aircraft_registration' => strtoupper($registration),
        'aircraft_label' => (string)($rental['display_label'] ?? $registration),
        'aircraft_rate_usd_per_hour' => (float)($rental['rate_usd_per_hour'] ?? 0),
        'preflight_briefing_hours' => 0.0,
        'flight_instruction_hours' => round(max(0.0, $hobbsDelta), 1),
        'ground_instruction_hours' => CvrFinancialDispatchService::DEFAULT_GROUND_HOURS,
        'instructional_rate_id' => is_array($defaultRate) ? (int)$defaultRate['id'] : null,
        'existing_balance_usd' => $balance,
        'status' => 'draft',
        'is_locked' => false,
        'overview' => null,
    );
    if (!is_array($saved)) {
        return $base;
    }
    return array_merge($base, array(
        'id' => (int)($saved['id'] ?? 0),
        'customer_user_id' => $saved['customer_user_id'] ?? $base['customer_user_id'],
        'customer_name' => ($saved['customer_name'] ?? '') !== '' ? $saved['customer_name'] : $base['customer_name'],
        'instructor_user_id' => $saved['instructor_user_id'] ?? $base['instructor_user_id'],
        'instructor_name' => ($saved['instructor_name'] ?? '') !== '' ? $saved['instructor_name'] : $base['instructor_name'],
        'aircraft_label' => ($saved['aircraft_label'] ?? '') !== '' ? $saved['aircraft_label'] : $base['aircraft_label'],
        'aircraft_rate_usd_per_hour' => (float)($saved['aircraft_rate_usd_per_hour'] ?? $base['aircraft_rate_usd_per_hour']),
        'preflight_briefing_hours' => (float)($saved['preflight_briefing_hours'] ?? 0),
        // Use array_key_exists so an intentional 0.0 instruction time is not replaced by Hobbs.
        'flight_instruction_hours' => array_key_exists('flight_instruction_hours', $saved)
            ? (float)$saved['flight_instruction_hours']
            : (float)$base['flight_instruction_hours'],
        'ground_instruction_hours' => array_key_exists('ground_instruction_hours', $saved)
            ? (float)$saved['ground_instruction_hours']
            : (float)$base['ground_instruction_hours'],
        'instructional_rate_id' => $saved['instructional_rate_id'] ?? $base['instructional_rate_id'],
        'existing_balance_usd' => (float)($saved['existing_balance_usd'] ?? $base['existing_balance_usd']),
        'status' => (string)($saved['status'] ?? 'draft'),
        'is_locked' => !empty($saved['is_locked']),
        'overview' => $saved['overview'] ?? null,
        'grand_total_usd' => $saved['grand_total_usd'] ?? null,
        'session_total_usd' => $saved['session_total_usd'] ?? null,
    ));
}

/**
 * Fleet card timestamp parts for fixed two-line instrument layout.
 *
 * @return array{empty:bool,date:string,time:string}
 */
function cvr_intake_fleet_logged_parts(PDO $pdo, mixed $value, string $registration = ''): array
{
    $text = trim((string)$value);
    if ($text === '') {
        return array('empty' => true, 'date' => 'No flight logged yet', 'time' => '');
    }
    $timezone = cvr_intake_california_timezone($pdo, $registration);
    try {
        $dt = new DateTimeImmutable($text, new DateTimeZone('UTC'));
        $local = $dt->setTimezone(new DateTimeZone($timezone));
        return array(
            'empty' => false,
            'date' => $local->format('D M j, Y'),
            'time' => $local->format('H:i') . ' LT',
        );
    } catch (Throwable) {
        $fallback = cvr_intake_local_datetime($pdo, $text, $registration);
        return array('empty' => false, 'date' => $fallback, 'time' => '');
    }
}

function cvr_intake_local_time(PDO $pdo, mixed $value, string $registration = ''): string
{
    $text = trim((string)$value);
    if ($text === '') {
        return '—';
    }
    // Always display in the aircraft operational timezone; never leave UTC
    // as the UI timezone when config is unset (shows 01:29 instead of 18:29 LT).
    $timezone = cvr_intake_california_timezone($pdo, $registration);
    return cw_logbook_time($text, $timezone);
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
    $timezone = cvr_intake_california_timezone($pdo, $registration);
    $dt = cw_dt_obj($text, $timezone);
    return $dt instanceof DateTimeInterface ? $dt->format('D, M j, Y') : '—';
}

function cvr_intake_legs_query(array $overrides = []): string
{
    $base = (string)($GLOBALS['cvrMlBasePath'] ?? '/admin/master_logbook.php');
    $params = array_merge(array(
        'tab' => 'dispatch',
        'legs_aircraft' => (string)($GLOBALS['legsAircraftFilter'] ?? ''),
        'legs_from' => (string)($GLOBALS['legsFrom'] ?? ''),
        'legs_to' => (string)($GLOBALS['legsTo'] ?? ''),
        'legs_show_removed' => !empty($GLOBALS['legsShowRemoved']) ? '1' : '',
        'legs_page' => (string)($GLOBALS['legsPage'] ?? '1'),
    ), $overrides);
    foreach ($params as $key => $value) {
        if ($value === '' || $value === null) {
            unset($params[$key]);
        }
    }
    return $base . '?' . http_build_query($params);
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
.intake-page{display:grid;gap:16px}
.intake-page .app-section-hero{margin-bottom:0}
.intake-hero-head{display:flex;align-items:flex-start;justify-content:space-between;gap:24px}
.intake-hero-copy{min-width:0}
.intake-hero-title{margin:0;font-size:34px;line-height:1.02;letter-spacing:-0.04em;font-weight:760;color:#fff}
.intake-hero-text{max-width:860px;margin:14px 0 0;color:rgba(255,255,255,.82);font-size:15px;line-height:1.65}
.intake-hero-actions{display:flex;flex-wrap:wrap;gap:10px;justify-content:flex-end}
.intake-hero-actions .app-btn{
  min-height:42px;
  height:42px;
  padding:0 16px;
  border-radius:999px;
  font-size:13px;
  font-weight:680;
  box-sizing:border-box;
}
.intake-hero-actions .app-btn-secondary{
  background:rgba(255,255,255,.08);
  color:#fff;
  border:1px solid rgba(255,255,255,.14);
  box-shadow:none;
}
.intake-hero-actions .app-btn-secondary:hover{
  background:rgba(255,255,255,.14);
  color:#fff;
}
.intake-hero-actions .app-btn-primary{
  background:#fff;
  color:#102440;
  border:1px solid transparent;
  box-shadow:0 10px 22px rgba(15,23,42,.18);
}
.intake-hero-actions .app-btn-primary:hover{
  background:#f8fafc;
  color:#102440;
}
#enrollment-modal .intake-modal{max-width:520px;width:min(520px,96vw)}
#enrollment-modal .intake-enrollment{display:grid;gap:14px;align-items:stretch}
#enrollment-modal .intake-enrollment .intake-field{display:grid;gap:6px;min-width:0}
#enrollment-modal .intake-enrollment .intake-field label{font-size:11px;font-weight:800;color:#334155;letter-spacing:.04em;text-transform:uppercase}
#enrollment-modal .intake-enrollment .app-btn{width:100%;justify-content:center}
#enrollment-modal .intake-code{display:flex;justify-content:center;margin-top:0;width:100%;box-sizing:border-box}
#enrollment-modal .intake-enrollment-result{display:grid;gap:8px;margin-top:4px}
#enrollment-modal .intake-enrollment-result-label{font-size:11px;font-weight:800;letter-spacing:.04em;text-transform:uppercase;color:#166534}
.intake-card{background:#fff;border:1px solid rgba(15,23,42,.08);border-radius:18px;padding:18px 20px;box-shadow:0 10px 22px rgba(15,23,42,.05)}
.intake-muted{color:#64748b;font-size:13px;line-height:1.5}
.intake-tabs{display:flex;gap:8px;flex-wrap:wrap}
.intake-tab{border:1px solid rgba(15,23,42,.10);background:#fff;color:#334155;border-radius:999px;min-height:42px;height:42px;padding:0 14px;font-size:12px;font-weight:800;cursor:pointer;display:inline-flex;align-items:center;box-sizing:border-box}
.intake-tab.is-active{background:linear-gradient(180deg,#17345d 0%,#102440 100%);color:#fff;border-color:transparent;box-shadow:0 8px 16px rgba(16,36,64,.14)}
.intake-count{display:inline-flex;align-items:center;justify-content:center;min-width:22px;height:22px;border-radius:999px;margin-left:6px;padding:0 6px;background:#e2e8f0;color:#334155;font-size:10px;font-weight:800}
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
.legs-filters label{display:grid;gap:6px;font-size:11px;font-weight:750;letter-spacing:.04em;text-transform:uppercase;color:#64748b}
.legs-filters .intake-select,
.legs-filters .app-input,
.legs-filters .app-select{
  min-width:140px;
}
.intake-refresh{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  height:42px;
  min-height:42px;
  padding:0 14px;
  border:1px solid rgba(15,23,42,.10);
  border-radius:12px;
  background:#fff;
  color:#102440;
  text-decoration:none;
  font-size:13px;
  font-weight:700;
  box-sizing:border-box;
  cursor:pointer;
}
.intake-refresh:hover{background:#f9fbfe;color:#102440}
.intake-field{display:grid;gap:6px;min-width:230px;align-content:start}
.intake-field label,
.intake-field > span{font-size:11px;font-weight:750;text-transform:uppercase;letter-spacing:.06em;color:#64748b}
.intake-select,
.intake-page .app-input,
.intake-page .app-select,
.intake-enrollment .intake-select,
.legs-filters .intake-select,
.intake-upload-grid .intake-select,
.reconstruction-grid .intake-select{
  height:42px !important;
  min-height:42px !important;
  max-height:42px;
  padding:0 12px !important;
  border:1px solid rgba(15,23,42,.10);
  border-radius:12px;
  background:#fff;
  color:#0f172a;
  font-size:13px;
  font-weight:600;
  box-sizing:border-box;
  line-height:1.2;
}
.intake-page textarea.intake-select,
.intake-page textarea.app-input{
  height:auto !important;
  min-height:96px !important;
  max-height:none;
  padding:10px 12px !important;
  line-height:1.4;
}
.intake-page input[type="file"].intake-select{
  height:42px !important;
  padding:8px 12px !important;
}
.intake-button,
.intake-enrollment .app-btn,
.legs-filters .app-btn,
.intake-hero-actions .app-btn,
.intake-upload-grid .app-btn,
.reconstruction-actions .app-btn,
.intake-upload-panel .app-btn{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  height:42px !important;
  min-height:42px !important;
  padding:0 16px;
  border:0;
  border-radius:12px;
  background:linear-gradient(180deg,#17345d 0%,#102440 100%);
  color:#fff;
  font-size:13px;
  font-weight:700;
  cursor:pointer;
  box-sizing:border-box;
  box-shadow:0 8px 16px rgba(16,36,64,.12);
  text-decoration:none;
  white-space:nowrap;
}
.intake-button:hover,
.intake-enrollment .app-btn-primary:hover,
.legs-filters .app-btn-primary:hover{
  background:linear-gradient(180deg,#1b3d6c 0%,#15304f 100%);
  color:#fff;
}
.intake-button:disabled{background:#94a3b8;box-shadow:none;cursor:not-allowed}
.legs-filters .app-btn-secondary,
.intake-hero-actions .app-btn-secondary,
a.intake-refresh{
  background:#fff;
  color:#102440;
  border:1px solid rgba(15,23,42,.10);
  box-shadow:none;
}
.legs-filters .app-btn-secondary:hover,
a.intake-refresh:hover{
  background:#f9fbfe;
  color:#102440;
}
.intake-enrollment{display:flex;align-items:end;gap:12px;flex-wrap:wrap}
@media (max-width:900px){
  .intake-hero-head{flex-direction:column}
  .intake-hero-actions{justify-content:flex-start}
}
.ml-pill{display:inline-flex;align-items:center;gap:4px;border-radius:999px;padding:2px 7px;font-size:9px;font-weight:800;background:#e2e8f0;color:#334155;white-space:nowrap}
.ml-pill-usable{background:#dcfce7;color:#166534}
.ml-pill-present{background:#dbeafe;color:#1e40af}
.ml-pill-processing{background:#fef3c7;color:#92400e}
.ml-pill-failed{background:#fee2e2;color:#991b1b}
.ml-aircraft-pill{display:inline-flex;border-radius:999px;padding:2px 7px;font-size:10px;font-weight:900;border:1px solid #93c5fd;color:#1d4ed8;background:#eff6ff}
/* Edit Operational Leg — structured aviation correction modal */
#legs-edit-modal .intake-modal{max-width:980px;width:min(980px,96vw);display:flex;flex-direction:column;max-height:92vh}
#legs-split-modal .intake-modal{max-width:1100px;width:min(1100px,96vw);display:flex;flex-direction:column;max-height:92vh}
.leg-split-notes{display:grid;gap:6px;margin:0 0 12px;padding:10px 12px;border-radius:10px;background:#f8fafc;border:1px solid #dbe3ee;color:#334155;font-size:12px;line-height:1.45}
.leg-split-notes li{margin-left:1rem}
.leg-split-summary{display:flex;flex-wrap:wrap;gap:8px;margin:0 0 12px}
.leg-split-chip{display:inline-flex;align-items:center;gap:6px;border-radius:999px;padding:4px 10px;background:#eef2ff;color:#1e3a8a;font-size:11px;font-weight:800}
.leg-split-table-wrap{overflow:auto}
.leg-split-table{width:100%;border-collapse:collapse;font-size:12px;min-width:980px}
.leg-split-table th,.leg-split-table td{border-bottom:1px solid #e2e8f0;padding:8px 6px;text-align:left;vertical-align:middle}
.leg-split-table th{font-size:10px;text-transform:uppercase;letter-spacing:.04em;color:#64748b;white-space:nowrap}
.leg-split-table input{width:100%;border:1px solid #cbd5e1;border-radius:8px;padding:6px 8px;font-size:12px;font-weight:700;box-sizing:border-box}
.leg-split-delta{font-weight:900;color:#0f766e;font-variant-numeric:tabular-nums;white-space:nowrap}
.leg-split-status{min-height:1.2em;font-size:12px;font-weight:700;color:#b91c1c;margin:8px 0}
.leg-split-status.is-ok{color:#166534}
.leg-split-status.is-muted{color:#64748b}
#legs-edit-modal .intake-modal-body{overflow:auto;padding:16px 18px 8px}
.leg-edit-sections{display:grid;gap:14px}
.leg-edit-card{border:1px solid #dbe3ee;border-radius:14px;background:#fff;padding:14px 14px 12px;box-shadow:0 4px 12px rgba(15,23,42,.04)}
.leg-edit-card h4{margin:0 0 10px;font-size:12px;font-weight:900;letter-spacing:.04em;text-transform:uppercase;color:#0f3a6d}
.leg-edit-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
.leg-edit-grid-3{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}
.leg-edit-grid-4{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}
.leg-edit-field{display:grid;gap:5px;min-width:0}
.leg-edit-field > span{font-size:11px;font-weight:800;color:#334155}
.leg-edit-field > span em{font-style:normal;font-weight:700;color:#64748b}
.leg-edit-field input,.leg-edit-field select{border:1px solid #cbd5e1;border-radius:10px;padding:9px 10px;font-size:14px;font-weight:700;color:#0f172a;background:#fff;width:100%;box-sizing:border-box}
.leg-edit-field input:focus,.leg-edit-field select:focus{outline:2px solid #93c5fd;border-color:#2563eb}
.leg-edit-icao{text-transform:uppercase;letter-spacing:.06em;font-size:16px!important}
.leg-edit-time{font-variant-numeric:tabular-nums;font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace}
.leg-edit-unit{display:flex;align-items:center;gap:8px}
.leg-edit-unit input{flex:1}
.leg-edit-suffix{font-size:13px;font-weight:900;color:#475569;min-width:2.5rem}
.leg-edit-derived{margin-top:10px;padding:10px 12px;border-radius:10px;background:#f8fafc;border:1px dashed #cbd5e1;display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap}
.leg-edit-derived strong{font-size:11px;letter-spacing:.04em;text-transform:uppercase;color:#64748b}
.leg-edit-derived span{font-size:14px;font-weight:850;color:#0f172a;font-variant-numeric:tabular-nums}
.leg-edit-meter{display:grid;gap:8px;padding:10px;border:1px solid #e2e8f0;border-radius:12px;background:#f8fafc}
.leg-edit-meter-title{font-size:11px;font-weight:900;color:#0f3a6d;letter-spacing:.04em}
.leg-edit-meter-row{display:grid;grid-template-columns:1fr auto 1fr;gap:8px;align-items:end}
.leg-edit-meter-arrow{font-weight:900;color:#64748b;padding-bottom:10px}
.leg-edit-error{display:none;margin-top:6px;font-size:12px;font-weight:700;color:#991b1b}
.leg-edit-error.is-visible{display:block}
.leg-edit-crew-row{display:grid;grid-template-columns:minmax(140px,180px) minmax(0,1fr) auto;gap:8px;align-items:center;margin-bottom:8px}
.leg-edit-crew-remove{border:1px solid #fecaca;background:#fef2f2;color:#991b1b;border-radius:9px;padding:8px 10px;font-size:11px;font-weight:800;cursor:pointer}
.leg-edit-add-crew{border:1px solid #cbd5e1;background:#fff;color:#1d4ed8;border-radius:9px;padding:8px 12px;font-size:12px;font-weight:850;cursor:pointer}
.leg-edit-footer{position:sticky;bottom:0;display:flex;justify-content:flex-end;gap:10px;padding:12px 0 4px;background:linear-gradient(180deg,rgba(255,255,255,0),#fff 28%);border-top:1px solid #e2e8f0;margin-top:8px}
.leg-edit-fin-head{border:1px solid #e2e8f0;border-radius:10px;background:#f8fafc;padding:10px 12px;margin-bottom:12px;display:grid;gap:4px}
.leg-edit-fin-head strong{color:#0f172a}
.leg-edit-fin-row{display:grid;grid-template-columns:140px minmax(0,1fr);gap:10px;align-items:center;margin-bottom:10px}
.leg-edit-fin-row label{font-size:12px;font-weight:700;color:#334155;text-align:right}
.leg-edit-fin-controls{display:flex;gap:8px;align-items:center;min-width:0}
.leg-edit-fin-controls select,.leg-edit-fin-controls input{width:100%;border:1px solid #cbd5e1;border-radius:8px;padding:8px 10px;font-size:13px;background:linear-gradient(180deg,#fff,#f8fafc)}
.leg-edit-fin-controls input[type=number]{max-width:88px;flex:0 0 88px}
.leg-edit-fin-controls select[data-fin-rate],.leg-edit-fin-controls select[data-fin-ground-rate]{flex:1 1 auto}
.leg-edit-fin-overview{margin-top:14px;border:1px solid #e2e8f0;border-radius:10px;overflow:hidden}
.leg-edit-fin-table{width:100%;border-collapse:collapse;font-size:12px}
.leg-edit-fin-table th,.leg-edit-fin-table td{padding:8px 10px;border-bottom:1px solid #eef2f7;text-align:left}
.leg-edit-fin-table th{background:#f1f5f9;font-weight:800;color:#0f172a}
.leg-edit-fin-table td.num,.leg-edit-fin-table th.num{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}
.leg-edit-fin-table tr.is-section th{background:#e2e8f0}
.leg-edit-fin-table tr.is-total td{font-weight:900;border-bottom:0}
.leg-edit-fin-locked{display:none;margin:0;padding:8px 10px;border-radius:8px;background:#ecfdf5;border:1px solid #86efac;color:#166534;font-size:12px;font-weight:700}
.leg-edit-fin-locked.is-on{display:block}
.leg-edit-record-lock{display:none;flex-direction:column;gap:10px;margin-top:8px;padding:12px 0 4px;border-top:1px solid #e2e8f0}
.leg-edit-record-lock.is-on{display:flex}
.leg-edit-fin-unlock{display:none;gap:8px;align-items:end;flex-wrap:wrap}
.leg-edit-fin-unlock.is-on{display:flex}
.leg-edit-save-lock{border:0;border-radius:10px;background:#0f766e;color:#fff;padding:10px 14px;font-size:12px;font-weight:900;cursor:pointer}
.leg-edit-save-lock:disabled{opacity:.45;cursor:not-allowed}
@media (max-width:720px){.leg-edit-fin-row{grid-template-columns:1fr}.leg-edit-fin-row label{text-align:left}}
.leg-edit-save{border:0;border-radius:10px;background:#1d4ed8;color:#fff;padding:10px 16px;font-size:13px;font-weight:900;cursor:pointer}
.leg-edit-cancel{border:1px solid #cbd5e1;border-radius:10px;background:#fff;color:#334155;padding:10px 14px;font-size:13px;font-weight:800;cursor:pointer}
.leg-edit-garmin{margin-top:8px;border:1px solid #e2e8f0;border-radius:14px;background:#f8fafc;padding:14px}
.leg-edit-garmin h4{margin:0 0 6px;font-size:12px;font-weight:900;letter-spacing:.04em;text-transform:uppercase;color:#475569}
.leg-edit-garmin p{margin:0 0 10px;font-size:12px;color:#64748b;line-height:1.4}
.leg-edit-briefing{margin-top:12px;border:1px solid #dbe3ee;border-radius:14px;background:#fff;padding:14px;box-shadow:0 4px 12px rgba(15,23,42,.04)}
.leg-edit-briefing-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;margin-bottom:8px;flex-wrap:wrap}
.leg-edit-briefing h4{margin:0;font-size:12px;font-weight:900;letter-spacing:.04em;text-transform:uppercase;color:#0f3a6d}
.leg-edit-briefing p{margin:0 0 10px;font-size:12px;color:#64748b;line-height:1.45}
.leg-edit-briefing textarea{width:100%;min-height:220px;resize:vertical;border:1px solid #cbd5e1;border-radius:12px;padding:12px;background:#f8fafc;color:#0f172a;font:12px/1.55 ui-sans-serif,system-ui,-apple-system,sans-serif;box-sizing:border-box}
.leg-edit-briefing-status{margin-top:8px;font-size:12px;font-weight:700;color:#64748b}
.leg-edit-briefing-status.is-error{color:#991b1b}
.leg-edit-briefing-status.is-ok{color:#166534}
.leg-edit-tz{font-size:12px;font-weight:700;color:#64748b}
.legs-track-head{display:flex;justify-content:space-between;gap:12px;align-items:center;margin-bottom:8px;flex-wrap:wrap}
.legs-track-head h4{margin:0}
.legs-track-open{font-size:12px;font-weight:800;color:#1d4ed8;text-decoration:none}
.legs-track-open:hover{text-decoration:underline}
.legs-track-status{margin:0 0 10px;font-size:12px;font-weight:700;color:#64748b;line-height:1.4}
.legs-track-status[data-tone="ok"]{color:#166534}
.legs-track-status[data-tone="error"]{color:#991b1b}
.legs-track-status[data-tone="loading"]{color:#1d4ed8}
.legs-track-map{height:320px;border:1px solid #dbe3ee;border-radius:12px;overflow:hidden;background:#e8eef6}
.legs-track-player{display:grid;grid-template-columns:auto minmax(0,1fr) auto;gap:10px;align-items:center;margin-top:10px}
.legs-track-play{border:1px solid #cbd5e1;border-radius:9px;background:#fff;color:#0f172a;padding:8px 12px;font-size:12px;font-weight:850;cursor:pointer;min-width:72px}
.legs-track-play:disabled{opacity:.45;cursor:not-allowed}
.legs-track-timeline{width:100%;accent-color:#1d4ed8}
.legs-track-times{display:grid;gap:2px;justify-items:end;font-variant-numeric:tabular-nums;font-size:12px;font-weight:800;color:#334155;min-width:52px}
.legs-track-times span{color:#64748b;font-weight:700}
.legs-track-marker{background:transparent;border:0}
.legs-track-marker span{display:block;border-radius:999px;background:var(--mk,#334155);box-shadow:0 0 0 2px #fff,0 2px 6px rgba(15,23,42,.28)}
.legs-track-marker.is-ownship span{border-radius:4px;transform:rotate(45deg)}
.legs-track-marker em{display:block;margin-top:2px;font-style:normal;font-size:9px;font-weight:800;color:#0f172a;text-shadow:0 0 3px #fff;white-space:nowrap;text-align:center}
.leg-edit-badge{display:inline-flex;border-radius:999px;padding:2px 7px;font-size:10px;font-weight:800;background:#e2e8f0;color:#475569}
.leg-edit-badge-staff{background:#dbeafe;color:#1e40af}
.leg-edit-badge-student{background:#dcfce7;color:#166534}
.leg-edit-badge-legacy{background:#ffedd5;color:#9a3412}
@media (max-width:820px){
  .leg-edit-grid,.leg-edit-grid-3,.leg-edit-grid-4,.leg-edit-meter-row,.leg-edit-crew-row{grid-template-columns:1fr}
  .leg-edit-meter-arrow{display:none}
}
/* Operational Legs — one coherent flight strip per row */
.ml-aircraft-pill{font-size:11px;padding:3px 8px;letter-spacing:.03em;line-height:1.2;white-space:nowrap}
.legs-table-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch;border:1px solid #e2e8f0;border-radius:16px;background:#fff}
.legs-table{width:100%;min-width:1280px;border-collapse:separate;border-spacing:0;font-size:12px;table-layout:fixed}
.legs-table col.legs-col-flight{width:132px}
.legs-table col.legs-col-route{width:92px}
.legs-table col.legs-col-crew{width:168px}
.legs-table col.legs-col-times{width:108px}
.legs-table col.legs-col-meters{width:148px}
.legs-table col.legs-col-fuel{width:150px}
.legs-table col.legs-col-evidence{width:118px}
.legs-table col.legs-col-actions{width:132px}
.legs-table th{position:sticky;top:0;z-index:2;background:#f8fafc;padding:10px 14px;border-bottom:1px solid #e2e8f0;font-size:10px;letter-spacing:.06em;text-transform:uppercase;color:#64748b;font-weight:800;text-align:left;white-space:nowrap}
.legs-table td{padding:14px 14px;border-bottom:1px solid #e8eef5;vertical-align:top;color:#102845;overflow:visible}
.legs-table tbody tr.legs-row{cursor:pointer;transition:background-color .12s ease,box-shadow .12s ease}
.legs-table tbody tr.legs-row:hover{background:#f5f9ff;box-shadow:inset 3px 0 0 #17345d}
.legs-flight{display:grid;gap:6px;align-content:start}
.legs-flight-date{font-size:11px;font-weight:800;color:#0f172a;line-height:1.25;white-space:nowrap}
.legs-flight-mission{font-size:11px;font-weight:700;color:#64748b;line-height:1.25}
.legs-route{display:grid;gap:2px;justify-items:start;font-variant-numeric:tabular-nums}
.legs-route-icao{font-size:14px;font-weight:900;letter-spacing:.05em;color:#0f172a;line-height:1.15}
.legs-route-join{font-size:11px;font-weight:800;color:#94a3b8;line-height:1;padding:1px 0}
.legs-route-place{font-size:10px;font-weight:650;color:#94a3b8;line-height:1.25;margin-top:2px}
.legs-route-via{font-size:11px;font-weight:750;color:#0369a1;margin-top:3px;letter-spacing:.02em}
.leg-edit-segment{border:1px solid #e2e8f0;border-radius:10px;padding:12px 14px;margin-top:12px;background:#f8fafc}
.leg-edit-segment-title{font-size:12px;font-weight:850;letter-spacing:.06em;text-transform:uppercase;color:#0369a1;margin:0 0 10px}
.leg-edit-segments[hidden],.leg-edit-single-route[hidden],.leg-edit-single-meters[hidden],.leg-edit-single-fuel[hidden],.leg-edit-single-ops[hidden]{display:none!important}
.legs-crew{display:grid;gap:10px;min-width:0}
.legs-crew-person{display:grid;gap:1px;min-width:0}
.legs-crew-name{font-size:12px;font-weight:800;color:#0f172a;line-height:1.25;white-space:normal;overflow-wrap:anywhere}
.legs-crew-role{font-size:10px;font-weight:700;color:#64748b;line-height:1.2;text-transform:capitalize}
.legs-times{display:grid;gap:2px;font-variant-numeric:tabular-nums;justify-items:start}
.legs-times-value{font-size:14px;font-weight:900;color:#0f172a;line-height:1.1}
.legs-times-label{font-size:10px;font-weight:700;color:#94a3b8;line-height:1.2}
.legs-times-join{font-size:11px;font-weight:800;color:#94a3b8;line-height:1;padding:2px 0}
.legs-times-ops{margin-top:6px;font-size:10px;font-weight:700;color:#64748b}
.legs-meters{display:grid;gap:10px;font-variant-numeric:tabular-nums}
.legs-meter{display:grid;gap:2px}
.legs-meter-label{font-size:9px;font-weight:800;letter-spacing:.05em;text-transform:uppercase;color:#94a3b8}
.legs-meter-main{font-size:13px;font-weight:850;color:#0f172a;white-space:nowrap}
.legs-meter-delta{font-size:11px;font-weight:700;color:#64748b}
.legs-fuel{display:grid;gap:5px;min-width:0}
.legs-fuel-bar{width:100%;height:8px;border-radius:999px;background:#e5e7eb;overflow:hidden}
.legs-fuel-bar > span{display:block;height:100%;border-radius:999px;background:linear-gradient(90deg,#15803d 0%,#22c55e 45%,#86efac 100%)}
.legs-fuel-vals{font-size:12px;font-weight:800;color:#0f172a;font-variant-numeric:tabular-nums;white-space:nowrap}
.legs-fuel-burn{font-size:11px;font-weight:700;color:#334155}
.legs-oil{font-size:11px;font-weight:700;color:#92400e}
.fleet-status-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:12px;margin:0 0 16px;align-items:stretch}
.fleet-status-card{border:1px solid #dbe3ee;border-radius:14px;background:#fff;padding:14px 14px 12px;box-shadow:0 4px 14px rgba(15,23,42,.04);display:grid;grid-template-rows:auto auto auto 1fr;gap:12px;min-height:210px;cursor:pointer;transition:border-color .15s ease,box-shadow .15s ease}
.fleet-status-card:hover{border-color:#93c5fd;box-shadow:0 6px 16px rgba(37,99,235,.08)}
.fleet-status-card:focus-visible{outline:2px solid #2563eb;outline-offset:2px}
.fleet-status-head{display:flex;align-items:baseline;justify-content:space-between;gap:10px;min-height:22px}
.fleet-status-reg{font-size:18px;font-weight:900;letter-spacing:.02em;color:#0f172a;line-height:1.1}
.fleet-status-uplift-hint{font-size:10px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.05em;white-space:nowrap;flex:0 0 auto}
.fleet-status-instrument{display:grid;gap:6px}
.fleet-status-meters{display:grid;grid-template-columns:1fr 1fr;gap:0;align-items:start}
.fleet-status-meter{display:grid;gap:2px;min-width:0}
.fleet-status-meter.is-tacho{padding-left:12px;border-left:1px solid #e2e8f0}
.fleet-status-meter-label{font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.07em;color:#94a3b8;line-height:1}
.fleet-status-meter-value{font-size:22px;font-weight:900;color:#0f172a;font-variant-numeric:tabular-nums;line-height:1.1;white-space:nowrap}
.fleet-status-logged{display:grid;gap:0;min-height:28px;align-content:start}
.fleet-status-logged-date,.fleet-status-logged-time{font-size:11px;color:#94a3b8;font-weight:600;line-height:1.25;white-space:nowrap}
.fleet-status-logged.is-empty .fleet-status-logged-date{white-space:normal}
.fleet-status-bars{display:grid;gap:10px;align-content:start}
.fleet-status-bar-row{display:grid;gap:4px}
.fleet-status-bar-head{display:flex;justify-content:space-between;gap:8px;align-items:baseline}
.fleet-status-bar-title{font-size:12px;font-weight:800;color:#334155}
.fleet-status-bar-value{font-size:12px;font-weight:800;color:#0f172a;font-variant-numeric:tabular-nums;white-space:nowrap}
.fleet-status-bar{height:6px;background:#e2e8f0;border-radius:999px;overflow:hidden}
.fleet-status-bar>span{display:block;height:100%;border-radius:999px}
.fleet-status-bar.is-oil>span{background:linear-gradient(90deg,#d97706,#fbbf24)}
.fleet-status-bar.is-fuel>span{background:linear-gradient(90deg,#15803d,#4ade80)}
.fleet-status-burn{font-size:11px;font-weight:600;color:#94a3b8;margin-top:2px;min-height:14px;line-height:1.2;white-space:nowrap}
.fleet-uplift-history{margin-top:16px;border-top:1px solid #e2e8f0;padding-top:12px}
.fleet-uplift-history h4{margin:0 0 8px;font-size:13px;font-weight:800;color:#0f172a}
.fleet-uplift-table{width:100%;border-collapse:collapse;font-size:12px}
.fleet-uplift-table th,.fleet-uplift-table td{padding:6px 8px;border-bottom:1px solid #eef2f7;text-align:left;vertical-align:top}
.fleet-uplift-table th{font-size:10px;text-transform:uppercase;letter-spacing:.04em;color:#64748b;font-weight:800}
.fleet-uplift-table td{color:#334155;font-variant-numeric:tabular-nums}
.fleet-uplift-empty{font-size:12px;color:#94a3b8;margin:0}
.legs-evidence{display:grid;gap:4px}
.legs-ev{font-size:11px;font-weight:700;color:#475569;white-space:nowrap}
.legs-ev-mark{font-weight:900}
.legs-ev-ok .legs-ev-mark{color:#15803d}
.legs-ev-warn .legs-ev-mark{color:#c2410c}
.legs-ev-bad .legs-ev-mark{color:#b91c1c}
.legs-ev-na .legs-ev-mark{color:#94a3b8}
.legs-actions{display:grid;gap:8px;justify-items:stretch;min-width:0}
.legs-actions .app-btn{min-height:34px;height:34px;padding:0 12px;border-radius:999px;font-size:11px;font-weight:800;gap:6px;width:100%;box-sizing:border-box}
.legs-actions .app-btn svg{width:12px;height:12px;flex:0 0 12px}
.legs-actions .app-btn-primary{background:linear-gradient(180deg,#17345d 0%,#102440 100%);color:#fff;border-color:transparent;box-shadow:0 8px 16px rgba(16,36,64,.14)}
.legs-actions .app-btn-primary:hover{background:linear-gradient(180deg,#1b3d6c 0%,#15304f 100%);color:#fff}
.legs-actions .app-btn-secondary{background:#fff;color:#102440;border:1px solid rgba(15,23,42,.12);box-shadow:none}
.legs-actions .app-btn-secondary:hover{background:#f9fbfe;color:#102440}
.legs-actions .app-btn.is-disabled,.legs-actions .app-btn:disabled{opacity:.45;cursor:not-allowed;pointer-events:none;transform:none;box-shadow:none}
.legs-actions form{margin:0}
.legs-actions .legs-btn-remove{color:#9a3412;border-color:rgba(154,52,18,.22)}
.legs-actions .legs-btn-remove:hover{background:#fff7ed;color:#9a3412}
.legs-show-removed{display:inline-flex;align-items:center;gap:8px;font-size:12px;font-weight:700;color:#334155;white-space:nowrap;min-height:42px}
.legs-show-removed input{width:16px;height:16px;margin:0}
.legs-table tbody tr.legs-row.is-removed{background:#f8fafc;opacity:.92}
.legs-table tbody tr.legs-row.is-removed:hover{background:#f1f5f9}
.legs-removed-badge{display:inline-flex;align-items:center;margin-top:6px;padding:2px 8px;border-radius:999px;background:#fee2e2;color:#9f1239;font-size:10px;font-weight:800;letter-spacing:.02em;text-transform:uppercase}
.legs-legend{display:flex;flex-wrap:wrap;gap:12px;align-items:center;margin-top:10px;font-size:11px;color:#64748b;font-weight:700}
.legs-num{font-weight:750;color:#334155}
.legs-blank{color:#cbd5e1;font-weight:700}
.legs-pagination{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-top:12px}
.legs-pagination a,.legs-pagination span{font-size:12px;font-weight:800;color:#334155;text-decoration:none}
.legs-pagination a{color:#1d4ed8}
.legs-link-btn{border:0;background:transparent;color:#1d4ed8;font:inherit;font-weight:800;font-size:11px;cursor:pointer;padding:0;text-decoration:underline}
.legs-link-btn:disabled{color:#94a3b8;cursor:not-allowed;text-decoration:none}
.legs-modal-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:10px}
.legs-modal-grid label{display:grid;gap:4px;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.04em;color:#64748b}
.legs-modal-grid input,.legs-modal-grid select{height:42px;min-height:42px;border:1px solid rgba(15,23,42,.10);border-radius:12px;padding:0 12px;font-size:13px;color:#0f172a;background:#fff;box-sizing:border-box}
.legs-modal-grid textarea{border:1px solid rgba(15,23,42,.10);border-radius:12px;padding:10px 12px;font-size:13px;color:#0f172a;background:#fff;min-height:96px;box-sizing:border-box}
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
.intake-select option{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;font-size:11px}
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
  <section class="app-section-hero" aria-label="Master Logbook">
    <div class="hero-overline">Operations · CVR</div>
    <div class="intake-hero-head">
      <div class="intake-hero-copy">
        <h2 class="intake-hero-title">Master Logbook</h2>
        <p class="intake-hero-text">
          <?php if ($cvrMlAudience === 'instructor'): ?>
            Operational Legs are the authoritative per-leg CVR record (Reservation → Leg). Times use the aircraft operational timezone (America/Los_Angeles).
          <?php else: ?>
            Operational Legs are the authoritative per-leg CVR record (Reservation → Leg). Times use the aircraft operational timezone (America/Los_Angeles). Audio, Garmin CSV, and reconstruction remain available as supporting intake evidence.
          <?php endif; ?>
        </p>
      </div>
      <div class="intake-hero-actions">
        <?php if ($cvrMlCanEnroll): ?>
          <button class="app-btn app-btn-primary" type="button" data-enrollment-open>Enroll CVR Unit</button>
        <?php endif; ?>
        <a class="app-btn app-btn-secondary" href="<?= cvr_intake_h($cvrMlBasePath) ?>">Refresh data</a>
      </div>
    </div>
  </section>

  <?php if ($cvrMlShowAudio || $cvrMlShowGarmin || $cvrMlShowReconstruction): ?>
  <section class="intake-card">
    <div class="intake-tabs" role="tablist" aria-label="Data intake sources">
      <button class="intake-tab is-active" type="button" role="tab" aria-selected="true" data-intake-tab="dispatch">
        Operational Legs <span class="intake-count"><?= count($dispatch['rows']) ?></span>
      </button>
      <?php if ($cvrMlShowAudio): ?>
      <button class="intake-tab" type="button" role="tab" aria-selected="false" data-intake-tab="audio">
        Cockpit Audio <span class="intake-count" data-audio-tab-count><?= $audioVisibleRowCount ?></span>
      </button>
      <?php endif; ?>
      <?php if ($cvrMlShowGarmin): ?>
      <button class="intake-tab" type="button" role="tab" aria-selected="false" data-intake-tab="garmin">
        Garmin CSV <span class="intake-count"><?= count($garmin['rows']) ?></span>
      </button>
      <?php endif; ?>
      <?php if ($cvrMlShowReconstruction): ?>
      <button class="intake-tab" type="button" role="tab" aria-selected="false" data-intake-tab="reconstruction">
        Reconstruction <span class="intake-count"><?= count($reconstructionBundles) ?></span>
      </button>
      <?php endif; ?>
    </div>
  </section>
  <?php endif; ?>

  <section class="intake-card intake-panel is-active" role="tabpanel" data-intake-panel="dispatch">
    <div class="intake-panel-head">
      <div>
        <h2 class="intake-panel-title">Operational Legs</h2>
        <div class="intake-muted">Logbook view — one row per checked-in Dispatch leg. Times use the aircraft operational timezone.</div>
      </div>
    </div>

    <?php if ($fleetStatusError !== ''): ?>
      <div class="intake-notice" style="margin-bottom:12px">Fleet status cards unavailable: <?= cvr_intake_h($fleetStatusError) ?></div>
    <?php endif; ?>
    <?php if ($fleetStatusCards !== array()): ?>
      <div class="fleet-status-grid" data-fleet-status data-fleet-can-manage="<?= $cvrMlCanManageFuelUplifts ? '1' : '0' ?>" data-fleet-csrf="<?= cvr_intake_h($reconstructionCsrf) ?>" data-fleet-action-url="<?= cvr_intake_h(cvr_intake_legs_query()) ?>">
        <?php foreach ($fleetStatusCards as $card): ?>
          <?php
            $reg = (string)($card['registration'] ?? '');
            $hobbs = $card['hobbs'];
            $tacho = $card['tacho'];
            $oilPct = $card['oil_percentage'];
            $fuelQty = $card['fuel_quantity'];
            $fuelUnit = (string)($card['fuel_unit'] ?? 'USG');
            $fuelBurn = $card['fuel_burn_avg_usg_per_hour'] ?? $card['fuel_burn'] ?? null;
            $uplifts = is_array($card['uplifts'] ?? null) ? $card['uplifts'] : array();
            $loggedParts = cvr_intake_fleet_logged_parts($pdo, $card['logged_at'] ?? null, $reg);
            $upliftPayload = array();
            foreach ($uplifts as $uplift) {
                $upliftPayload[] = array(
                    'id' => (int)($uplift['id'] ?? 0),
                    'fuel' => cvr_intake_format_one_decimal($uplift['fuel_after_usg'] ?? null),
                    'unit' => (string)($uplift['fuel_unit'] ?? $fuelUnit),
                    'when' => cvr_intake_fleet_logged_label($pdo, $uplift['uplifted_at'] ?? null, $reg),
                    'notes' => trim((string)($uplift['notes'] ?? '')),
                );
            }
            $burnLabel = $fuelBurn !== null
                ? ('Avg ' . cvr_intake_format_one_decimal($fuelBurn) . ' ' . $fuelUnit . '/hr')
                : 'Avg —';
          ?>
          <article
            class="fleet-status-card"
            tabindex="0"
            role="button"
            aria-label="<?= cvr_intake_h($reg) ?> fuel uplift"
            data-fleet-aircraft="<?= cvr_intake_h($reg) ?>"
            data-fleet-uplift-open
            data-aircraft-id="<?= (int)($card['aircraft_id'] ?? 0) ?>"
            data-aircraft-registration="<?= cvr_intake_h($reg) ?>"
            data-fuel-unit="<?= cvr_intake_h($fuelUnit) ?>"
            data-fuel-capacity="<?= cvr_intake_h((string)($card['fuel_capacity'] ?? 13)) ?>"
            data-uplift-count="<?= count($upliftPayload) ?>"
            data-uplifts="<?= cvr_intake_h(json_encode($upliftPayload, JSON_UNESCAPED_SLASHES) ?: '[]') ?>"
          >
            <div class="fleet-status-head">
              <div class="fleet-status-reg"><?= cvr_intake_h($reg) ?></div>
              <div class="fleet-status-uplift-hint">Uplift · <?= (int)count($upliftPayload) ?></div>
            </div>
            <div class="fleet-status-instrument">
              <div class="fleet-status-meters" aria-label="Aircraft meters">
                <div class="fleet-status-meter is-hobbs">
                  <div class="fleet-status-meter-label">Hobbs</div>
                  <div class="fleet-status-meter-value"><?= cvr_intake_h($hobbs !== null ? cvr_intake_format_one_decimal($hobbs) : '—') ?></div>
                </div>
                <div class="fleet-status-meter is-tacho">
                  <div class="fleet-status-meter-label">Tacho</div>
                  <div class="fleet-status-meter-value"><?= cvr_intake_h($tacho !== null ? cvr_intake_format_one_decimal($tacho) : '—') ?></div>
                </div>
              </div>
              <div class="fleet-status-logged<?= !empty($loggedParts['empty']) ? ' is-empty' : '' ?>">
                <div class="fleet-status-logged-date"><?= cvr_intake_h((string)$loggedParts['date']) ?></div>
                <?php if (empty($loggedParts['empty']) && (string)$loggedParts['time'] !== ''): ?>
                  <div class="fleet-status-logged-time"><?= cvr_intake_h((string)$loggedParts['time']) ?></div>
                <?php else: ?>
                  <div class="fleet-status-logged-time">&nbsp;</div>
                <?php endif; ?>
              </div>
            </div>
            <div class="fleet-status-bars">
              <div class="fleet-status-bar-row">
                <div class="fleet-status-bar-head">
                  <span class="fleet-status-bar-title">Oil</span>
                  <span class="fleet-status-bar-value"><?= cvr_intake_h((string)($card['oil_label'] ?? '—')) ?></span>
                </div>
                <div class="fleet-status-bar is-oil" title="Oil <?= cvr_intake_h((string)($card['oil_label'] ?? '—')) ?>">
                  <span style="width:<?= (int)round((float)($oilPct ?? 0)) ?>%"></span>
                </div>
              </div>
              <div class="fleet-status-bar-row">
                <div class="fleet-status-bar-head">
                  <span class="fleet-status-bar-title">Fuel</span>
                  <span class="fleet-status-bar-value"><?= cvr_intake_h($fuelQty !== null ? (cvr_intake_format_one_decimal($fuelQty) . ' ' . $fuelUnit) : '—') ?></span>
                </div>
                <div class="fleet-status-bar is-fuel" title="Fuel <?= cvr_intake_h($fuelQty !== null ? (cvr_intake_format_one_decimal($fuelQty) . ' / ' . cvr_intake_format_one_decimal($card['fuel_capacity'] ?? null) . ' ' . $fuelUnit) : '—') ?>">
                  <span style="width:<?= (int)round((float)($card['fuel_pct'] ?? 0)) ?>%"></span>
                </div>
                <div class="fleet-status-burn"><?= cvr_intake_h($burnLabel) ?></div>
              </div>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <form class="legs-toolbar" method="get" action="<?= cvr_intake_h($cvrMlBasePath) ?>">
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
        <?php if ($cvrMlCanRemove): ?>
        <label class="legs-show-removed" title="Soft-removed legs keep all evidence and can be restored">
          <input type="checkbox" name="legs_show_removed" value="1" <?= $legsShowRemoved ? 'checked' : '' ?> onchange="this.form.submit()">
          Show removed
        </label>
        <?php endif; ?>
        <button class="app-btn app-btn-primary" type="submit">Apply</button>
        <a class="app-btn app-btn-secondary" href="<?= cvr_intake_h(cvr_intake_legs_query(array('legs_aircraft' => '', 'legs_from' => '', 'legs_to' => '', 'legs_show_removed' => '', 'legs_page' => '1'))) ?>">Clear</a>
      </div>
      <div class="intake-muted">
        Showing <?= $legsTotal === 0 ? 0 : (($legsPage - 1) * $legsLimit + 1) ?>–<?= min($legsTotal, $legsPage * $legsLimit) ?>
        of <?= (int)$legsTotal ?> · 30 / page
        <?php if ($legsShowRemoved): ?> · removed only<?php endif; ?>
      </div>
    </form>
    <?php if (!$dispatch['available']): ?>
      <div class="intake-notice"><?= cvr_intake_h($dispatch['message']) ?></div>
    <?php elseif ($dispatch['rows'] === array()): ?>
      <div class="intake-empty"><?= $legsShowRemoved
          ? 'No soft-removed Operational Legs match these filters.'
          : 'No Dispatch records match these filters.' ?></div>
    <?php else: ?>
      <div class="legs-table-wrap">
        <table class="legs-table" data-operational-legs-table>
          <colgroup>
            <col class="legs-col-flight">
            <col class="legs-col-route">
            <col class="legs-col-crew">
            <col class="legs-col-times">
            <col class="legs-col-meters">
            <col class="legs-col-fuel">
            <col class="legs-col-evidence">
            <col class="legs-col-actions">
          </colgroup>
          <thead>
            <tr>
              <th>Aircraft</th>
              <th>Route</th>
              <th>Crew</th>
              <th>Times</th>
              <th>Meters</th>
              <th>Fuel</th>
              <th>Evidence</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($dispatch['rows'] as $row): ?>
            <?php
              $tail = strtoupper(trim((string)($row['aircraft_registration'] ?? '')));
              $acCfg = $aircraftUnitMap[$tail] ?? array('fuel_capacity' => 13.0, 'fuel_unit' => 'USG', 'oil_unit' => '%', 'oil_capacity' => 100.0);
              $startHobbs = $row['starting_hobbs'] ?? null;
              $endHobbs = $row['ending_hobbs'] ?? null;
              $startTacho = $row['starting_tacho'] ?? null;
              $endTacho = $row['ending_tacho'] ?? null;
              $engine = $row['engine_time_hours'] ?? null;
              $tachoDelta = $row['tacho_delta_hours'] ?? null;
              $airborne = $row['airborne_time_hours'] ?? null;
              $flightTime = is_numeric($tachoDelta) ? (float)$tachoDelta : (is_numeric($airborne) ? (float)$airborne : null);
              $fuelDep = trim((string)($row['fuel_departure'] ?? $row['fuel_onboard'] ?? ''));
              $fuelLdg = trim((string)($row['fuel_landing'] ?? $row['fuel_remaining'] ?? ''));
              $fuelBurn = $row['fuel_consumption'] ?? null;
              $fuelUnit = (string)($acCfg['fuel_unit'] ?? 'USG');
              $depAirport = strtoupper(trim((string)($row['departure_airport'] ?? '')));
              $arrAirport = strtoupper(trim((string)($row['arrival_airport'] ?? '')));
              $sameAirport = $depAirport !== '' && $depAirport === $arrAirport;
              $offLocal = cvr_intake_local_time($pdo, $row['off_block_utc'] ?? null, $tail);
              $onLocal = cvr_intake_local_time($pdo, $row['on_block_utc'] ?? null, $tail);
              $flightDate = cvr_intake_local_date($pdo, $row['off_block_utc'] ?? null, $tail);
              $missionCode = trim((string)($row['mission_code'] ?? ''));
              $crewMembers = is_array($row['crew_members'] ?? null) ? $row['crew_members'] : array();
              $audioLabel = (string)($row['audio_status_label'] ?? 'Stored on Device');
              $audioLower = strtolower($audioLabel);
              $audioState = str_contains($audioLower, 'fail') ? 'bad'
                  : (str_contains($audioLower, 'uploaded') ? 'ok'
                  : (str_contains($audioLower, 'device') || str_contains($audioLower, 'pending') ? 'warn' : 'na'));
              $garminOn = !empty($row['has_garmin_csv']);
              $garminState = $garminOn ? 'ok' : 'warn';
              $transcriptRaw = strtolower(trim((string)($row['transcript_status'] ?? $row['transcript_status_label'] ?? '')));
              $transcriptState = match (true) {
                  str_contains($transcriptRaw, 'fail') => 'bad',
                  str_contains($transcriptRaw, 'ready') || str_contains($transcriptRaw, 'complete') || str_contains($transcriptRaw, 'published') => 'ok',
                  str_contains($transcriptRaw, 'transcrib') || str_contains($transcriptRaw, 'queued') || str_contains($transcriptRaw, 'pending') || str_contains($transcriptRaw, 'process') => 'warn',
                  default => 'na',
              };
              $recordingUid = trim((string)($row['recording_uid'] ?? ''));
              $recordingId = (int)($row['recording_id'] ?? 0);
              $reconstructionStatus = strtolower(trim((string)($row['reconstruction_status'] ?? '')));
              $replayReady = $recordingUid !== '' && in_array($reconstructionStatus, array('ready', 'reconstruction_complete', 'complete'), true);
              $replayProcessing = in_array($reconstructionStatus, array('processing', 'queued', 'running'), true);
              $debriefId = (int)($row['debrief_id'] ?? 0);
              $bundleId = (int)($row['bundle_id'] ?? 0);
              $debriefJobStatus = strtolower(trim((string)($row['debrief_job_status'] ?? '')));
              $debriefJobRunning = in_array($debriefJobStatus, array('pending', 'claimed', 'running', 'retry_wait'), true);
              $debriefJobProgress = max(0, min(100, (int)($row['debrief_job_progress'] ?? 0)));
              $debriefJobMessage = trim((string)($row['debrief_job_message'] ?? ''));
              if ($debriefJobRunning && $debriefJobProgress <= 0) {
                  $debriefJobProgress = 5;
                  if ($debriefJobMessage === '') {
                      $debriefJobMessage = 'Queued';
                  }
              }
              $debriefState = match (true) {
                  $debriefJobRunning => 'warn',
                  $debriefJobStatus === 'failed' || $debriefJobStatus === 'dead_letter' => 'bad',
                  $debriefId > 0 || $debriefJobStatus === 'succeeded' => 'ok',
                  default => 'na',
              };
              $toCount = (int)($row['takeoff_count'] ?? 0);
              $ldgCount = (int)($row['landing_count'] ?? 0);
              $hobbsStartLabel = cvr_intake_format_one_decimal($startHobbs);
              $hobbsEndLabel = cvr_intake_format_one_decimal($endHobbs);
              $hobbsDelta = (is_numeric($startHobbs) && is_numeric($endHobbs))
                  ? cvr_intake_format_one_decimal((float)$endHobbs - (float)$startHobbs)
                  : (is_numeric($engine) ? cvr_intake_format_one_decimal($engine) : '');
              $tachoStartLabel = cvr_intake_format_one_decimal($startTacho);
              $tachoEndLabel = cvr_intake_format_one_decimal($endTacho);
              $tachoDeltaLabel = (is_numeric($startTacho) && is_numeric($endTacho))
                  ? cvr_intake_format_one_decimal((float)$endTacho - (float)$startTacho)
                  : (is_numeric($flightTime) ? cvr_intake_format_one_decimal($flightTime) : '');
              $burnLabel = cvr_intake_format_one_decimal($fuelBurn);
              $fuelGauge = cvr_intake_fuel_remaining_gauge($fuelLdg !== '' ? $fuelLdg : null, $acCfg);
              $oilGauge = cvr_intake_oil_gauge($row, $acCfg);
              $depPlace = cvr_intake_airport_place($depAirport);
              $arrPlace = cvr_intake_airport_place($arrAirport);
              $legSegments = is_array($row['leg_segments'] ?? null) ? $row['leg_segments'] : array();
              $viaAirports = is_array($row['via_airports'] ?? null) ? $row['via_airports'] : array();
              if ($viaAirports === array() && count($legSegments) >= 2) {
                  foreach ($legSegments as $segIndex => $segment) {
                      if (!is_array($segment) || $segIndex === 0) {
                          continue;
                      }
                      $viaIcao = strtoupper(trim((string)($segment['departure_airport'] ?? '')));
                      if ($viaIcao !== '' && $viaIcao !== $arrAirport && !in_array($viaIcao, $viaAirports, true)) {
                          $viaAirports[] = $viaIcao;
                      }
                  }
              }
              $legPayload = array(
                  'dispatch_id' => (int)($row['id'] ?? 0),
                  'dispatch_uuid' => (string)($row['dispatch_uuid'] ?? ''),
                  'workflow_flight_record_uuid' => (string)($row['workflow_flight_record_uuid'] ?? ''),
                  'aircraft_registration' => $tail,
                  'mission_code' => $missionCode,
                  'departure_airport' => $depAirport,
                  'arrival_airport' => $arrAirport,
                  'via_airports' => $viaAirports,
                  'leg_segments' => $legSegments,
                  'device_multi_leg_grouped' => !empty($row['device_multi_leg_grouped']),
                  'sibling_dispatch_ids' => is_array($row['sibling_dispatch_ids'] ?? null)
                      ? array_values(array_map('intval', $row['sibling_dispatch_ids']))
                      : array(),
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
                  'oil_unit' => (string)($row['oil_unit'] ?? ($acCfg['oil_unit'] ?? '')),
                  'takeoff_count' => $toCount,
                  'landing_count' => $ldgCount,
                  'crew' => $crewMembers,
                  'bundle_id' => $bundleId,
                  'debrief_id' => $debriefId,
                  'recording_uid' => $recordingUid,
                  'has_garmin_csv' => $garminOn,
                  'is_hidden' => !empty($row['is_hidden']),
                  'reservation_uuid' => (string)($row['reservation_uuid'] ?? ''),
                  'leg_uuid' => (string)($row['leg_uuid'] ?? ''),
                  'identity_source' => (string)($row['identity_source'] ?? ''),
                  'timezone' => cvr_intake_california_timezone($pdo, $tail),
                  'hobbs_delta' => $hobbsDelta !== '' ? (float)$hobbsDelta : null,
                  'financial' => cvr_intake_financial_leg_payload(
                      $pdo,
                      (int)($row['id'] ?? 0),
                      $tail,
                      $crewMembers,
                      $hobbsDelta !== '' ? (float)$hobbsDelta : 0.0,
                      $financialByDispatchId[(int)($row['id'] ?? 0)] ?? null,
                      $aircraftRentalRateMap,
                      $instructionalRates
                  ),
              );
              if (!empty($row['off_block_utc'])) {
                  $tzName = $legPayload['timezone'];
                  try {
                      $dt = new DateTimeImmutable((string)$row['off_block_utc'], new DateTimeZone('UTC'));
                      $legPayload['off_block_local'] = $dt->setTimezone(new DateTimeZone($tzName))->format('Y-m-d\\TH:i');
                  } catch (Throwable) {
                      $legPayload['off_block_local'] = '';
                  }
              }
              $evMark = static function (string $state): string {
                  return match ($state) {
                      'ok' => '✓',
                      'warn' => '⟳',
                      'bad' => '!',
                      default => '–',
                  };
              };
            ?>
            <tr class="legs-row<?= !empty($row['is_hidden']) ? ' is-removed' : '' ?>" data-leg="<?= cvr_intake_h(json_encode($legPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP)) ?>">
              <td>
                <div class="legs-flight">
                  <div class="legs-flight-date"><?= cvr_intake_h($flightDate) ?></div>
                  <?= cvr_intake_aircraft_pill_html($tail) ?>
                  <div class="legs-flight-mission"><?= cvr_intake_h($missionCode !== '' ? $missionCode : '—') ?></div>
                  <?php if (!empty($row['is_hidden'])): ?>
                    <div class="legs-removed-badge">Removed</div>
                  <?php endif; ?>
                </div>
              </td>
              <td>
                <div class="legs-route">
                  <?php if ($sameAirport): ?>
                    <div class="legs-route-icao"><?= cvr_intake_h($depAirport) ?></div>
                    <div class="legs-route-join">↓</div>
                    <div class="legs-route-icao"><?= cvr_intake_h($arrAirport) ?></div>
                    <?php if ($viaAirports !== array()): ?>
                      <div class="legs-route-via">Via <?= cvr_intake_h(implode(', ', $viaAirports)) ?></div>
                    <?php elseif ($depPlace !== ''): ?>
                      <div class="legs-route-place"><?= cvr_intake_h($depPlace) ?></div>
                    <?php endif; ?>
                  <?php else: ?>
                    <div class="legs-route-icao"><?= cvr_intake_h($depAirport !== '' ? $depAirport : '—') ?> → <?= cvr_intake_h($arrAirport !== '' ? $arrAirport : '—') ?></div>
                    <?php if ($viaAirports !== array()): ?>
                      <div class="legs-route-via">Via <?= cvr_intake_h(implode(', ', $viaAirports)) ?></div>
                    <?php elseif ($depPlace !== '' || $arrPlace !== ''): ?>
                      <div class="legs-route-place"><?= cvr_intake_h(trim(($depPlace !== '' ? $depPlace : '—') . ' → ' . ($arrPlace !== '' ? $arrPlace : '—'))) ?></div>
                    <?php endif; ?>
                  <?php endif; ?>
                </div>
              </td>
              <td>
                <div class="legs-crew"><?php
                  if ($crewMembers === array()) {
                      echo '<span class="legs-blank">—</span>';
                  } else {
                      $shown = 0;
                      foreach ($crewMembers as $member) {
                          if ($shown >= 3) {
                              echo '<div class="legs-crew-person"><div class="legs-crew-name">+' . (count($crewMembers) - $shown) . ' more</div></div>';
                              break;
                          }
                          $name = trim((string)($member['name'] ?? ''));
                          $roleKey = strtolower(trim(str_replace(array(' ', '-'), '_', (string)($member['role'] ?? 'crew'))));
                          $roleLabel = match ($roleKey) {
                              'pic', 'pilot_in_command' => 'PIC',
                              'student' => 'Student',
                              'flight_instructor', 'instructor', 'cfi' => 'Instructor',
                              'supervising_instructor', 'supervisor' => 'Supervisor',
                              'pilot_flying', 'pf' => 'Pilot Flying',
                              'pilot_monitoring', 'pm' => 'Pilot Monitoring',
                              'examiner' => 'Examiner',
                              'safety_pilot' => 'Safety Pilot',
                              default => ucwords(str_replace('_', ' ', (string)($member['role'] ?? 'Crew'))),
                          };
                          echo '<div class="legs-crew-person"><div class="legs-crew-name">' . cvr_intake_h($name !== '' ? $name : '—') . '</div><div class="legs-crew-role">' . cvr_intake_h($roleLabel) . '</div></div>';
                          $shown++;
                      }
                  }
                ?></div>
              </td>
              <td>
                <div class="legs-times">
                  <div class="legs-times-value"><?= cvr_intake_h($offLocal) ?></div>
                  <div class="legs-times-label">Off Block</div>
                  <div class="legs-times-join">↓</div>
                  <div class="legs-times-value"><?= cvr_intake_h($onLocal) ?></div>
                  <div class="legs-times-label">On Block</div>
                  <div class="legs-times-ops"><?= (int)$toCount ?>/<?= (int)$ldgCount ?> TO/LDG</div>
                </div>
              </td>
              <td>
                <div class="legs-meters">
                  <div class="legs-meter">
                    <div class="legs-meter-label">Hobbs</div>
                    <div class="legs-meter-main"><?= cvr_intake_h($hobbsStartLabel !== '' ? $hobbsStartLabel : '—') ?> → <?= cvr_intake_h($hobbsEndLabel !== '' ? $hobbsEndLabel : '—') ?></div>
                    <div class="legs-meter-delta">Δ <?= cvr_intake_h($hobbsDelta !== '' ? $hobbsDelta : '—') ?></div>
                  </div>
                  <div class="legs-meter">
                    <div class="legs-meter-label">Tacho</div>
                    <div class="legs-meter-main"><?= cvr_intake_h($tachoStartLabel !== '' ? $tachoStartLabel : '—') ?> → <?= cvr_intake_h($tachoEndLabel !== '' ? $tachoEndLabel : '—') ?></div>
                    <div class="legs-meter-delta">Δ <?= cvr_intake_h($tachoDeltaLabel !== '' ? $tachoDeltaLabel : '—') ?></div>
                  </div>
                </div>
              </td>
              <td>
                <div class="legs-fuel">
                  <div class="legs-fuel-bar" title="Fuel remaining <?= cvr_intake_h($fuelGauge['label']) ?> <?= cvr_intake_h($fuelUnit) ?>"><span style="width:<?= (int)round($fuelGauge['pct']) ?>%"></span></div>
                  <div class="legs-fuel-vals"><?= cvr_intake_h(($fuelDep !== '' ? $fuelDep : '—') . ' → ' . ($fuelLdg !== '' ? $fuelLdg : '—')) ?> <?= cvr_intake_h($fuelUnit) ?></div>
                  <div class="legs-fuel-burn">Burn <?= cvr_intake_h($burnLabel !== '' ? $burnLabel : '—') ?> <?= cvr_intake_h($fuelUnit) ?></div>
                  <div class="legs-oil"><?= cvr_intake_h($oilGauge['label']) ?></div>
                </div>
              </td>
              <td>
                <div class="legs-evidence">
                  <div class="legs-ev legs-ev-<?= cvr_intake_h($audioState) ?>"><span class="legs-ev-mark"><?= $evMark($audioState) ?></span> Audio</div>
                  <div class="legs-ev legs-ev-<?= cvr_intake_h($garminState) ?>"><span class="legs-ev-mark"><?= $evMark($garminState) ?></span> Garmin</div>
                  <div class="legs-ev legs-ev-<?= cvr_intake_h($transcriptState) ?>"><span class="legs-ev-mark"><?= $evMark($transcriptState) ?></span> Transcript</div>
                  <div
                    class="legs-ev legs-ev-<?= cvr_intake_h($debriefState) ?>"
                    data-debrief-bundle-id="<?= $bundleId > 0 ? (int)$bundleId : '' ?>"
                    data-debrief-id="<?= $debriefId > 0 ? (int)$debriefId : '' ?>"
                    data-debrief-status="<?= cvr_intake_h($debriefJobRunning ? $debriefJobStatus : ($debriefId > 0 ? 'ready' : $debriefJobStatus)) ?>"
                  >
                    <span class="legs-ev-mark" data-debrief-mark><?= $evMark($debriefState) ?></span> Debrief
                    <div class="intake-progress" data-debrief-progress <?= $debriefJobRunning ? '' : 'hidden' ?> style="margin-top:4px;min-width:96px">
                      <div class="intake-progress-bar"><div class="intake-progress-fill<?= $debriefJobRunning ? ' is-evidence-active' : '' ?>" data-debrief-progress-fill style="width:<?= (int)$debriefJobProgress ?>%"></div></div>
                      <div class="intake-muted" data-debrief-progress-label style="font-size:10px"><?= cvr_intake_h($debriefJobRunning ? (($debriefJobMessage !== '' ? $debriefJobMessage . ' · ' : '') . $debriefJobProgress . '%') : '') ?></div>
                    </div>
                  </div>
                </div>
              </td>
              <td>
                <div class="legs-actions">
                  <?php
                    $replayReturn = $cvrMlBasePath . '?tab=dispatch';
                  ?>
                  <?php if ($replayReady): ?>
                    <a class="app-btn app-btn-primary" href="/admin/cockpit_recorder_replay.php?id=<?= rawurlencode($recordingUid) ?>&amp;return=<?= rawurlencode($replayReturn) ?>" data-legs-stop title="Open reconstructed flight replay">
                      <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8 5v14l11-7L8 5z"/></svg>
                      <span>Open Replay</span>
                    </a>
                  <?php elseif ($replayProcessing && $recordingUid !== ''): ?>
                    <a class="app-btn app-btn-primary" href="/admin/cockpit_recorder_replay.php?id=<?= rawurlencode($recordingUid) ?>&amp;return=<?= rawurlencode($replayReturn) ?>" data-legs-stop title="Flight reconstruction in progress">
                      <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 4V1L8 5l4 4V6c3.31 0 6 2.69 6 6 0 1.01-.25 1.97-.7 2.8l1.46 1.46C19.54 15.03 20 13.57 20 12c0-4.42-3.58-8-8-8zm0 14c-3.31 0-6-2.69-6-6 0-1.01.25-1.97.7-2.8L5.24 7.74C4.46 8.97 4 10.43 4 12c0 4.42 3.58 8 8 8v3l4-4-4-4v3z"/></svg>
                      <span>Reconstructing…</span>
                    </a>
                  <?php elseif ($bundleId > 0 && $cvrMlCanEdit): ?>
                    <form method="post" action="/admin/api/manual_bundle_reconstruct.php" data-legs-stop>
                      <input type="hidden" name="csrf_token" value="<?= cvr_intake_h($reconstructionCsrf) ?>">
                      <input type="hidden" name="bundle_id" value="<?= (int)$bundleId ?>">
                      <button class="app-btn app-btn-primary" type="submit" title="Start flight reconstruction from Garmin CSV">
                        <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2L4.5 20.29l.71.71L12 18l6.79 3 .71-.71L12 2z"/></svg>
                        <span>Reconstruct Flight</span>
                      </button>
                    </form>
                  <?php else: ?>
                    <span class="app-btn app-btn-primary is-disabled" aria-disabled="true" title="Waiting for Dispatch + Audio + Garmin before reconstruction">
                      <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2L4.5 20.29l.71.71L12 18l6.79 3 .71-.71L12 2z"/></svg>
                      <span>Reconstruct Flight</span>
                    </span>
                  <?php endif; ?>
                  <button type="button" class="app-btn app-btn-secondary" data-legs-details><span>Details</span></button>
                  <?php if ($cvrMlCanRemove): ?>
                  <?php if (!empty($row['is_hidden'])): ?>
                    <form method="post" action="<?= cvr_intake_h(cvr_intake_legs_query()) ?>" data-legs-stop>
                      <input type="hidden" name="csrf_token" value="<?= cvr_intake_h($reconstructionCsrf) ?>">
                      <input type="hidden" name="action" value="restore_operational_leg">
                      <input type="hidden" name="dispatch_id" value="<?= (int)($row['id'] ?? 0) ?>">
                      <button class="app-btn app-btn-secondary" type="submit"><span>Restore</span></button>
                    </form>
                  <?php else: ?>
                    <form method="post" action="<?= cvr_intake_h(cvr_intake_legs_query()) ?>" data-legs-stop onsubmit="return confirm('Remove this leg from the Master Logbook list? Evidence stays on file and can be restored.');">
                      <input type="hidden" name="csrf_token" value="<?= cvr_intake_h($reconstructionCsrf) ?>">
                      <input type="hidden" name="action" value="hide_operational_leg">
                      <input type="hidden" name="dispatch_id" value="<?= (int)($row['id'] ?? 0) ?>">
                      <button class="app-btn app-btn-secondary legs-btn-remove" type="submit"><span>Remove</span></button>
                    </form>
                  <?php endif; ?>
                  <?php endif; ?>
                </div>
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

  <?php if ($cvrMlShowAudio): ?>
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
  <?php endif; ?>

  <?php if ($cvrMlShowGarmin): ?>
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
  <?php endif; ?>

  <?php if ($cvrMlShowReconstruction): ?>
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
                    href="/admin/cockpit_recorder_replay.php?id=<?= rawurlencode((string)$bundle['recording_uid']) ?>&amp;return=<?= rawurlencode('/admin/master_logbook.php?tab=reconstruction') ?>"
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
                <?php
                  $bundleDebriefProgress = max(0, min(100, (int)($bundle['debrief_job_progress'] ?? 0)));
                  $bundleDebriefMessage = trim((string)($bundle['debrief_job_message'] ?? ''));
                  if ($debriefJobRunning && $bundleDebriefProgress <= 0) {
                      $bundleDebriefProgress = 5;
                      if ($bundleDebriefMessage === '') {
                          $bundleDebriefMessage = 'Queued';
                      }
                  }
                ?>
                <form method="post" action="/admin/api/manual_bundle_debrief.php" style="margin-top:6px">
                  <input type="hidden" name="action" value="generate_bundle_debrief">
                  <input type="hidden" name="csrf_token" value="<?= cvr_intake_h($reconstructionCsrf) ?>">
                  <input type="hidden" name="bundle_id" value="<?= (int)$bundle['id'] ?>">
                  <button class="intake-button" type="submit" <?= (!$transcriptGate['ready'] || $debriefJobRunning) ? 'disabled title="' . cvr_intake_h($debriefJobRunning ? 'Debrief generation is running.' : $transcriptGate['reason']) . '"' : '' ?>><?= $debriefJobRunning ? 'Generating Debrief…' : ($latestDebrief ? 'Regenerate Debrief' : 'Generate Debrief') ?></button>
                </form>
                <div
                  data-debrief-bundle-id="<?= (int)$bundle['id'] ?>"
                  data-debrief-id="<?= is_array($latestDebrief) ? (int)$latestDebrief['id'] : '' ?>"
                  data-debrief-status="<?= cvr_intake_h($debriefJobRunning ? (string)$bundle['debrief_job_status'] : (is_array($latestDebrief) ? 'ready' : (string)($bundle['debrief_job_status'] ?? ''))) ?>"
                  style="margin-top:6px"
                >
                  <div class="intake-progress" data-debrief-progress <?= $debriefJobRunning ? '' : 'hidden' ?>>
                    <div class="intake-progress-bar"><div class="intake-progress-fill<?= $debriefJobRunning ? ' is-evidence-active' : '' ?>" data-debrief-progress-fill style="width:<?= (int)$bundleDebriefProgress ?>%"></div></div>
                    <div class="intake-muted" data-debrief-progress-label><?= cvr_intake_h($debriefJobRunning ? (($bundleDebriefMessage !== '' ? $bundleDebriefMessage . ' · ' : '') . $bundleDebriefProgress . '%') : '') ?></div>
                  </div>
                  <?php if (!empty($bundle['debrief_job_id'])): ?>
                    <div class="intake-muted" style="margin-top:4px" data-debrief-job-meta>Debrief job #<?= (int)$bundle['debrief_job_id'] ?> · <span data-debrief-job-status><?= cvr_intake_h(strtoupper((string)$bundle['debrief_job_status'])) ?></span></div>
                  <?php endif; ?>
                  <?php if (!empty($bundle['debrief_job_error'])): ?>
                    <div class="intake-error" style="margin-top:4px" data-debrief-job-error><?= cvr_intake_h($bundle['debrief_job_error']) ?></div>
                  <?php endif; ?>
                  <?php if (is_array($latestDebrief)): ?>
                    <a class="intake-refresh" style="margin-top:6px" data-debrief-review-link href="/admin/master_logbook.php?tab=reconstruction&debrief_id=<?= (int)$latestDebrief['id'] ?>">Review Debrief · <?= cvr_intake_h($latestDebrief['suggested_overall']) ?></a>
                  <?php else: ?>
                    <a class="intake-refresh" style="margin-top:6px;display:none" data-debrief-review-link href="#">Review Debrief</a>
                  <?php endif; ?>
                </div>
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
  <?php endif; ?>
</div>

<?php if ($cvrMlCanEnroll): ?>
<div class="intake-modal-backdrop" id="enrollment-modal"<?= (is_array($enrollmentResult) || $enrollmentError !== '') ? '' : ' hidden' ?>>
  <div class="intake-modal" role="dialog" aria-modal="true" aria-labelledby="enrollment-modal-title">
    <div class="intake-modal-head">
      <div>
        <h3 class="intake-modal-title" id="enrollment-modal-title">CVR Unit Enrollment</h3>
        <div class="intake-muted">Generate a one-time code, then enter it in the iOS app under CVR Unit Admin. The code expires after 60 minutes.</div>
      </div>
      <button class="intake-modal-close" type="button" data-enrollment-close aria-label="Close">Close</button>
    </div>
    <div class="intake-modal-body">
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
        <button class="app-btn app-btn-primary" type="submit">Generate Enrollment Code</button>
      </form>
      <?php if (is_array($enrollmentResult)): ?>
        <div class="intake-enrollment-result">
          <div class="intake-enrollment-result-label">Enrollment code</div>
          <div class="intake-code"><?= cvr_intake_h($enrollmentResult['enrollment_code'] ?? '') ?></div>
        </div>
      <?php endif; ?>
      <?php if ($enrollmentError !== ''): ?>
        <div class="intake-notice" style="margin-top:10px"><?= cvr_intake_h($enrollmentError) ?></div>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="intake-modal-backdrop" id="legs-edit-modal" hidden>
  <div class="intake-modal" role="dialog" aria-modal="true" aria-labelledby="legs-edit-title">
    <div class="intake-modal-head">
      <div>
        <h3 class="intake-modal-title" id="legs-edit-title">Edit Operational Leg</h3>
        <div class="intake-muted">Administrative correction. On Block is calculated from Off Block + Hobbs. Times use the aircraft operational timezone.</div>
      </div>
      <button class="intake-modal-close" type="button" data-legs-edit-close aria-label="Close">Close</button>
    </div>
    <div class="intake-modal-body">
      <form method="post" id="legs-edit-form" novalidate>
        <input type="hidden" name="action" value="save_operational_leg">
        <input type="hidden" name="csrf_token" value="<?= cvr_intake_h($reconstructionCsrf) ?>">
        <input type="hidden" name="dispatch_id" id="legs-edit-dispatch-id" value="">
        <input type="hidden" name="leg[timezone]" id="legs-edit-timezone" value="America/Los_Angeles">
        <input type="hidden" name="leg[off_block_local]" id="legs-edit-off-combined" value="">
        <input type="hidden" name="leg[oil_unit]" id="legs-edit-oil-unit" value="%">
        <input type="hidden" id="legs-edit-fuel-unit" value="USG">
        <div class="leg-edit-sections">
          <section class="leg-edit-card" data-leg-section="identity">
            <h4>Flight Identity</h4>
            <div class="leg-edit-grid">
              <label class="leg-edit-field"><span>Aircraft</span>
                <select name="leg[aircraft_registration]" id="legs-edit-aircraft" required>
                  <?php foreach ($aircraftOptions as $aircraftOption): ?>
                    <?php $reg = strtoupper(trim((string)($aircraftOption['registration'] ?? ''))); ?>
                    <?php if ($reg === '') { continue; } ?>
                    <option value="<?= cvr_intake_h($reg) ?>"
                      data-fuel-unit="<?= cvr_intake_h($aircraftUnitMap[$reg]['fuel_unit'] ?? 'USG') ?>"
                      data-oil-unit="<?= cvr_intake_h($aircraftUnitMap[$reg]['oil_unit'] ?? '%') ?>"
                      data-aircraft-id="<?= (int)($aircraftUnitMap[$reg]['id'] ?? $aircraftOption['id'] ?? 0) ?>"
                    ><?= cvr_intake_h($reg) ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
              <label class="leg-edit-field"><span>Mission</span>
                <select name="leg[mission_code]" id="legs-edit-mission" required>
                  <option value="">Select a mission</option>
                  <?php foreach ($flightMissions as $mission): ?>
                    <option value="<?= cvr_intake_h($mission['code']) ?>"><?= cvr_intake_h($mission['label']) ?></option>
                  <?php endforeach; ?>
                </select>
                <div class="leg-edit-error" data-error-for="mission"></div>
              </label>
            </div>
          </section>

          <section class="leg-edit-card" data-leg-section="route">
            <h4>Route and Time</h4>
            <div class="leg-edit-single-route" id="legs-edit-single-route">
              <div class="leg-edit-grid">
                <label class="leg-edit-field"><span>Departure Airport</span>
                  <input class="leg-edit-icao" name="leg[departure_airport]" id="legs-edit-dep" type="text" maxlength="4" minlength="3" autocomplete="off" required>
                  <div class="leg-edit-error" data-error-for="dep"></div>
                </label>
                <label class="leg-edit-field"><span>Arrival Airport</span>
                  <input class="leg-edit-icao" name="leg[arrival_airport]" id="legs-edit-arr" type="text" maxlength="4" minlength="3" autocomplete="off" required>
                  <div class="leg-edit-error" data-error-for="arr"></div>
                </label>
                <label class="leg-edit-field"><span>Date</span>
                  <input name="leg_date" id="legs-edit-date" type="date" required>
                  <div class="leg-edit-error" data-error-for="date"></div>
                </label>
                <label class="leg-edit-field">
                  <span>Off Block Time — Local <em id="legs-edit-tz-label">(America/Los_Angeles)</em></span>
                  <input class="leg-edit-time" name="leg_off_time" id="legs-edit-off-time" type="text" inputmode="numeric" maxlength="5" placeholder="18:29" pattern="([01][0-9]|2[0-3]):[0-5][0-9]" required>
                  <div class="leg-edit-error" data-error-for="off"></div>
                </label>
              </div>
              <div class="leg-edit-derived">
                <div><strong>Calculated On Block</strong><div><span id="legs-edit-onblock">—</span> <span class="leg-edit-tz">Local</span></div></div>
                <div class="leg-edit-tz">Timezone: <span id="legs-edit-tz-value">America/Los_Angeles</span></div>
              </div>
            </div>
            <div class="leg-edit-segments" id="legs-edit-route-segments" hidden></div>
          </section>

          <section class="leg-edit-card" data-leg-section="track" id="legs-track-panel">
            <div class="legs-track-head">
              <h4>GPS Track and Traffic</h4>
              <a class="legs-track-open" id="legs-track-open-replay" href="#" target="_blank" rel="noopener" hidden>Open full replay</a>
            </div>
            <p class="legs-track-status" id="legs-track-status" data-tone="muted">Open a Flight Record with a linked CVR recording to preview GPS and traffic.</p>
            <div class="legs-track-map" id="legs-track-map" role="img" aria-label="GPS track map with traffic overlay"></div>
            <div class="legs-track-player">
              <button type="button" class="legs-track-play" id="legs-track-play" disabled aria-pressed="false">Play</button>
              <input class="legs-track-timeline" id="legs-track-timeline" type="range" min="0" max="1" step="0.1" value="0" disabled aria-label="Track time scrubber">
              <div class="legs-track-times">
                <strong id="legs-track-current">00:00</strong>
                <span id="legs-track-end">00:00</span>
              </div>
            </div>
          </section>

          <section class="leg-edit-card" data-leg-section="meters">
            <h4>Aircraft Meters</h4>
            <div class="leg-edit-single-meters" id="legs-edit-single-meters">
              <div class="leg-edit-grid">
                <div class="leg-edit-meter">
                  <div class="leg-edit-meter-title">Hobbs</div>
                  <div class="leg-edit-meter-row">
                    <label class="leg-edit-field"><span>Start</span><input name="leg[starting_hobbs]" id="legs-edit-hobbs-start" type="number" step="0.1" min="0" inputmode="decimal" required></label>
                    <div class="leg-edit-meter-arrow">→</div>
                    <label class="leg-edit-field"><span>End</span><input name="leg[ending_hobbs]" id="legs-edit-hobbs-end" type="number" step="0.1" min="0" inputmode="decimal" required></label>
                  </div>
                  <div class="leg-edit-derived"><strong>Difference</strong><span id="legs-edit-hobbs-diff">—</span></div>
                  <div class="leg-edit-error" data-error-for="hobbs"></div>
                </div>
                <div class="leg-edit-meter">
                  <div class="leg-edit-meter-title">Tacho</div>
                  <div class="leg-edit-meter-row">
                    <label class="leg-edit-field"><span>Start</span><input name="leg[starting_tacho]" id="legs-edit-tacho-start" type="number" step="0.1" min="0" inputmode="decimal" required></label>
                    <div class="leg-edit-meter-arrow">→</div>
                    <label class="leg-edit-field"><span>End</span><input name="leg[ending_tacho]" id="legs-edit-tacho-end" type="number" step="0.1" min="0" inputmode="decimal" required></label>
                  </div>
                  <div class="leg-edit-derived"><strong>Difference</strong><span id="legs-edit-tacho-diff">—</span></div>
                  <div class="leg-edit-error" data-error-for="tacho"></div>
                </div>
              </div>
            </div>
            <div class="leg-edit-segments" id="legs-edit-meter-segments" hidden></div>
          </section>

          <section class="leg-edit-card" data-leg-section="fuel">
            <h4>Fuel and Oil</h4>
            <div class="leg-edit-single-fuel" id="legs-edit-single-fuel">
              <div class="leg-edit-grid-3">
                <label class="leg-edit-field"><span>Fuel Departure</span>
                  <div class="leg-edit-unit"><input name="leg[fuel_onboard]" id="legs-edit-fuel-dep" type="number" step="0.1" min="0" inputmode="decimal" required><span class="leg-edit-suffix" data-fuel-suffix>USG</span></div>
                </label>
                <label class="leg-edit-field"><span>Fuel Landing</span>
                  <div class="leg-edit-unit"><input name="leg[fuel_remaining]" id="legs-edit-fuel-ldg" type="number" step="0.1" min="0" inputmode="decimal" required><span class="leg-edit-suffix" data-fuel-suffix>USG</span></div>
                </label>
                <div class="leg-edit-field"><span>Fuel Burn</span>
                  <div class="leg-edit-derived" style="margin:0"><span id="legs-edit-fuel-burn">—</span>&nbsp;<span class="leg-edit-suffix" data-fuel-suffix>USG</span></div>
                </div>
              </div>
              <div class="leg-edit-error" data-error-for="fuel"></div>
            </div>
            <div class="leg-edit-segments" id="legs-edit-fuel-segments" hidden></div>
            <div style="margin-top:12px;max-width:280px">
              <label class="leg-edit-field"><span>Oil Quantity</span>
                <div class="leg-edit-unit">
                  <input name="leg[oil_value]" id="legs-edit-oil-value" type="number" step="0.1" min="0" inputmode="decimal">
                  <span class="leg-edit-suffix" id="legs-edit-oil-suffix">%</span>
                </div>
              </label>
            </div>
          </section>

          <section class="leg-edit-card" data-leg-section="ops">
            <h4>Takeoffs and Landings</h4>
            <div class="leg-edit-single-ops" id="legs-edit-single-ops">
              <div class="leg-edit-grid" style="max-width:420px">
                <label class="leg-edit-field"><span>Takeoffs</span><input name="leg[takeoff_count]" id="legs-edit-to" type="number" min="0" step="1" inputmode="numeric" required></label>
                <label class="leg-edit-field"><span>Landings</span><input name="leg[landing_count]" id="legs-edit-ldg" type="number" min="0" step="1" inputmode="numeric" required></label>
              </div>
            </div>
            <div class="leg-edit-segments" id="legs-edit-ops-segments" hidden></div>
          </section>

          <section class="leg-edit-card" data-leg-section="crew">
            <h4>Crew</h4>
            <div class="legs-crew-editor" id="legs-edit-crew"></div>
            <button class="leg-edit-add-crew" type="button" id="legs-edit-add-crew">+ Add Crew Member</button>
            <div class="leg-edit-error" data-error-for="crew"></div>
          </section>

          <section class="leg-edit-card" data-leg-section="financial" id="legs-edit-financial">
            <h4>Financial Dispatch</h4>
            <?php if ($financialDispatchError !== ''): ?>
              <div class="intake-notice" style="margin-bottom:10px"><?= cvr_intake_h($financialDispatchError) ?></div>
            <?php endif; ?>
            <div class="leg-edit-fin-locked" id="legs-fin-locked-banner">This Operational Leg is locked.</div>
            <div class="leg-edit-fin-head">
              <div><strong>Customer:</strong> <span id="legs-fin-head-customer">—</span></div>
              <div><strong>Aircraft:</strong> <span id="legs-fin-head-aircraft">—</span></div>
            </div>
            <input type="hidden" name="financial[workflow_flight_record_uuid]" id="legs-fin-flight-uuid" value="">
            <input type="hidden" name="financial[aircraft_registration]" id="legs-fin-aircraft-reg" value="">
            <input type="hidden" name="financial[customer_name]" id="legs-fin-customer-name" value="">
            <input type="hidden" name="financial[instructor_name]" id="legs-fin-instructor-name" value="">
            <input type="hidden" name="lock_financial" id="legs-fin-lock-flag" value="">

            <div class="leg-edit-fin-row">
              <label for="legs-fin-customer">Customer</label>
              <div class="leg-edit-fin-controls">
                <select name="financial[customer_user_id]" id="legs-fin-customer" data-fin-field>
                  <option value="">Select customer</option>
                  <?php foreach ($crewCatalogUsers as $user): ?>
                    <option value="<?= (int)$user['id'] ?>"><?= cvr_intake_h((string)$user['display_name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
            <div class="leg-edit-fin-row">
              <label for="legs-fin-instructor">Instructor</label>
              <div class="leg-edit-fin-controls">
                <select name="financial[instructor_user_id]" id="legs-fin-instructor" data-fin-field>
                  <option value="">Select instructor</option>
                  <?php foreach ($crewCatalogUsers as $user): ?>
                    <?php
                      $role = strtolower((string)($user['role'] ?? ''));
                      if (!in_array($role, array('instructor', 'supervisor', 'chief_instructor'), true)) { continue; }
                      $label = (string)$user['display_name'];
                      if ($role === 'instructor') { $label .= ' (Instr)'; }
                    ?>
                    <option value="<?= (int)$user['id'] ?>"><?= cvr_intake_h($label) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
            <div class="leg-edit-fin-row">
              <label for="legs-fin-preflight">Preflight Briefing</label>
              <div class="leg-edit-fin-controls">
                <input name="financial[preflight_briefing_hours]" id="legs-fin-preflight" type="number" min="0" step="0.1" value="0" data-fin-field>
                <span class="intake-muted">hours</span>
              </div>
            </div>
            <div class="leg-edit-fin-row">
              <label for="legs-fin-flight-hours">Flight Instruction</label>
              <div class="leg-edit-fin-controls">
                <input name="financial[flight_instruction_hours]" id="legs-fin-flight-hours" type="number" min="0" step="0.1" value="0" data-fin-field>
                <select name="financial[instructional_rate_id]" id="legs-fin-rate" data-fin-rate data-fin-field>
                  <?php foreach ($instructionalRates as $rate): ?>
                    <option value="<?= (int)$rate['id'] ?>" data-rate="<?= cvr_intake_h((string)$rate['rate_usd_per_hour']) ?>">
                      <?= cvr_intake_h((string)$rate['option_label']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
            <div class="leg-edit-fin-row">
              <label for="legs-fin-ground-hours">Ground Instruction</label>
              <div class="leg-edit-fin-controls">
                <input name="financial[ground_instruction_hours]" id="legs-fin-ground-hours" type="number" min="0" step="0.1" value="0.3" data-fin-field>
                <select id="legs-fin-ground-rate" data-fin-ground-rate disabled aria-label="Ground instruction rate mirrors flight rate">
                  <?php foreach ($instructionalRates as $rate): ?>
                    <option value="<?= (int)$rate['id'] ?>"><?= cvr_intake_h((string)$rate['option_label']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>

            <div class="leg-edit-fin-overview">
              <table class="leg-edit-fin-table" id="legs-fin-overview-table">
                <thead>
                  <tr class="is-section"><th>Aircraft Rental</th><th class="num">Hours</th><th class="num">Total</th></tr>
                </thead>
                <tbody id="legs-fin-overview-body">
                  <tr><td colspan="3" class="intake-muted">Select customer and instructor to preview charges.</td></tr>
                </tbody>
              </table>
            </div>
          </section>
        </div>
        <div class="leg-edit-record-lock" id="legs-record-lock">
          <div class="leg-edit-fin-locked" id="legs-fin-locked-banner">This Operational Leg is locked.</div>
          <div class="leg-edit-fin-unlock" id="legs-fin-unlock-box">
            <?php if ($cvrMlAudience !== 'admin'): ?>
              <label class="leg-edit-field" style="margin:0"><span>Unlock code</span>
                <input type="password" id="legs-fin-unlock-code" autocomplete="off" placeholder="Special unlock code">
              </label>
            <?php endif; ?>
            <label class="leg-edit-field" style="margin:0;min-width:180px"><span>Reason</span>
              <input type="text" id="legs-fin-unlock-reason" maxlength="500" placeholder="Optional">
            </label>
            <button class="app-btn app-btn-secondary" type="button" id="legs-fin-unlock-btn"><span>Unlock Record</span></button>
          </div>
        </div>
        <div class="leg-edit-footer">
          <button class="leg-edit-cancel" type="button" data-legs-edit-close>Cancel</button>
          <?php if ($cvrMlAudience === 'admin' && $cvrMlCanEdit): ?>
            <button class="app-btn app-btn-secondary" type="button" id="legs-split-btn">Define Legs</button>
          <?php endif; ?>
          <?php if ($cvrMlCanEdit): ?>
            <button class="leg-edit-save" type="submit" id="legs-edit-save-btn">Save Changes</button>
            <button class="leg-edit-save-lock" type="button" id="legs-edit-save-lock-btn" disabled>Save and LOCK</button>
          <?php endif; ?>
        </div>
      </form>

      <?php if ($cvrMlShowGarmin): ?>
      <div class="leg-edit-garmin">
        <h4>Garmin CSV Recovery</h4>
        <p>Attach a missing Garmin CSV to this operational leg. This does not save or discard other edits above.</p>
        <form method="post" enctype="multipart/form-data" id="legs-garmin-form">
          <input type="hidden" name="action" value="upload_manual_garmin_csv">
          <input type="hidden" name="csrf_token" value="<?= cvr_intake_h($reconstructionCsrf) ?>">
          <input type="hidden" name="workflow_flight_record_uuid" id="legs-csv-flight-uuid" value="">
          <input type="hidden" name="aircraft_registration" id="legs-csv-aircraft" value="">
          <input type="hidden" name="aircraft_id" id="legs-csv-aircraft-id" value="">
          <div class="leg-edit-grid">
            <div class="leg-edit-field"><span>Aircraft</span><div id="legs-csv-aircraft-label" style="font-weight:850;color:#0f172a">—</div></div>
            <div class="leg-edit-field"><span>Flight Record</span><div id="legs-csv-flight-label" class="intake-mono" style="font-size:11px;word-break:break-all">—</div></div>
            <label class="leg-edit-field"><span>Garmin CSV file</span><input type="file" name="garmin_csv" accept=".csv,text/csv" required></label>
          </div>
          <div style="margin-top:10px"><span class="leg-edit-badge" id="legs-csv-status">Status unknown</span></div>
          <button class="intake-button" type="submit" style="margin-top:12px;background:#475569">Upload CSV</button>
        </form>
      </div>
      <?php else: ?>
        <input type="hidden" id="legs-csv-flight-uuid" value="">
        <input type="hidden" id="legs-csv-aircraft" value="">
        <input type="hidden" id="legs-csv-aircraft-id" value="">
        <span id="legs-csv-aircraft-label" hidden></span>
        <span id="legs-csv-flight-label" hidden></span>
        <span id="legs-csv-status" hidden></span>
      <?php endif; ?>

      <div class="leg-edit-briefing" data-leg-section="generic-briefing">
        <div class="leg-edit-briefing-head">
          <div>
            <h4>Generic Briefing</h4>
            <p>Copy-paste student-facing narrative from the debriefing generator (General + chronological review only). Grades and mission standards are excluded.</p>
          </div>
          <button class="app-btn app-btn-secondary" type="button" id="legs-briefing-copy" data-legs-stop disabled>Copy Briefing</button>
        </div>
        <textarea id="legs-briefing-copy-box" readonly placeholder="Open a leg with a generated debrief to load the Generic Briefing."></textarea>
        <div class="leg-edit-briefing-status" id="legs-briefing-status"></div>
      </div>
    </div>
  </div>
</div>

<div class="intake-modal-backdrop" id="legs-split-modal" hidden>
  <div class="intake-modal" role="dialog" aria-modal="true" aria-labelledby="legs-split-title">
    <div class="intake-modal-head">
      <div>
        <h3 id="legs-split-title">Define Legs</h3>
        <p class="intake-muted" style="margin:4px 0 0">Annotate this continuous Check-In with multi-leg detail. One Operational Leg is kept — replay, CSV, debrief, and financials stay whole.</p>
      </div>
      <button type="button" class="intake-modal-close" id="legs-split-close" aria-label="Close">&times;</button>
    </div>
    <div class="intake-modal-body">
      <div class="leg-split-status is-muted" id="legs-split-status">Loading suggestions…</div>
      <ul class="leg-split-notes" id="legs-split-notes" hidden></ul>
      <div class="leg-split-summary" id="legs-split-summary" hidden></div>
      <div id="legs-split-table-wrap" class="leg-split-table-wrap" hidden>
        <table class="leg-split-table">
          <thead>
            <tr>
              <th>#</th>
              <th>From</th>
              <th>To</th>
              <th>Off Block UTC</th>
              <th>Hobbs</th>
              <th>ΔH</th>
              <th>Tacho</th>
              <th>ΔT</th>
              <th>TO</th>
              <th>LDG</th>
              <th>Fuel Start</th>
              <th>Fuel End</th>
              <th>Burn</th>
            </tr>
          </thead>
          <tbody id="legs-split-tbody"></tbody>
        </table>
      </div>
    </div>
    <div class="leg-edit-footer">
      <button class="leg-edit-cancel" type="button" id="legs-split-cancel">Cancel</button>
      <button class="leg-edit-save" type="button" id="legs-split-apply" disabled>Confirm Legs</button>
    </div>
  </div>
</div>

<script type="application/json" id="legs-crew-catalog"><?= json_encode(array_map(static function (array $u): array {
    $role = strtolower((string)($u['role'] ?? ''));
    return array(
        'id' => (int)$u['id'],
        'name' => (string)($u['display_name'] ?? ''),
        'role' => $role,
        'kind' => in_array($role, array('instructor', 'supervisor', 'chief_instructor'), true) ? 'staff' : 'student',
    );
}, $crewCatalogUsers), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
<script type="application/json" id="legs-financial-rates"><?= json_encode($instructionalRates, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
<script type="application/json" id="legs-aircraft-rental-rates"><?= json_encode($aircraftRentalRateMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
<script type="application/json" id="legs-customer-balances"><?= json_encode($customerBalanceMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
<script type="application/json" id="legs-flight-missions"><?= json_encode($flightMissions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>

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

<div class="intake-modal-backdrop" id="fleet-uplift-modal" hidden>
  <div class="intake-modal" role="dialog" aria-modal="true" aria-labelledby="fleet-uplift-title" style="max-width:640px">
    <div class="intake-modal-head">
      <div>
        <h3 class="intake-modal-title" id="fleet-uplift-title">Fuel Uplift</h3>
        <div class="intake-muted">Add an uplift and review historical fuel after refueling.</div>
      </div>
      <button class="intake-modal-close" type="button" data-fleet-uplift-close>Close</button>
    </div>
    <div class="intake-modal-body">
      <?php if ($cvrMlCanManageFuelUplifts): ?>
      <form method="post" action="<?= cvr_intake_h(cvr_intake_legs_query()) ?>" id="fleet-uplift-form">
        <input type="hidden" name="csrf_token" value="<?= cvr_intake_h($reconstructionCsrf) ?>">
        <input type="hidden" name="action" value="create_fuel_uplift">
        <input type="hidden" name="aircraft_id" id="fleet-uplift-aircraft-id" value="">
        <input type="hidden" name="aircraft_registration" id="fleet-uplift-aircraft-registration" value="">
        <div class="intake-field" style="margin-bottom:12px">
          <label>Aircraft</label>
          <div class="intake-primary" id="fleet-uplift-aircraft-label">—</div>
        </div>
        <div class="intake-field" style="margin-bottom:12px">
          <label for="fleet-uplift-fuel">Fuel after refueling (<span id="fleet-uplift-unit">USG</span>)</label>
          <input class="intake-select" type="number" min="0" max="500" step="0.1" name="fuel_after_usg" id="fleet-uplift-fuel" required>
          <div class="intake-muted" id="fleet-uplift-capacity-hint" style="margin-top:4px"></div>
        </div>
        <div class="intake-field" style="margin-bottom:12px">
          <label for="fleet-uplift-when">When (local, optional)</label>
          <input class="intake-select" type="datetime-local" name="uplifted_at_local" id="fleet-uplift-when">
        </div>
        <div class="intake-field" style="margin-bottom:14px">
          <label for="fleet-uplift-notes">Notes (optional)</label>
          <input class="intake-select" type="text" name="notes" id="fleet-uplift-notes" maxlength="500" placeholder="e.g. Self-serve KHHR">
        </div>
        <button class="intake-button" type="submit">Save Uplift</button>
      </form>
      <?php else: ?>
        <div class="intake-field" style="margin-bottom:12px">
          <label>Aircraft</label>
          <div class="intake-primary" id="fleet-uplift-aircraft-label">—</div>
        </div>
        <p class="intake-muted" style="margin:0 0 8px">Fuel uplift logging is only available to admins.</p>
      <?php endif; ?>
      <div class="fleet-uplift-history">
        <h4>Uplift history</h4>
        <div id="fleet-uplift-history-body">
          <p class="fleet-uplift-empty">No fuel uplifts logged yet.</p>
        </div>
      </div>
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
$legTrackJsPath = __DIR__ . '/assets/leg_track_chart.js';
$legTrackJsVer = is_file($legTrackJsPath) ? (string)filemtime($legTrackJsPath) : (string)time();
?>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
<script src="/admin/assets/leg_track_chart.js?v=<?= cvr_intake_h($legTrackJsVer) ?>"></script>
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

  (function initEnrollmentModal() {
    const modal = document.getElementById('enrollment-modal');
    if (!modal) return;
    const open = () => { modal.hidden = false; };
    const close = () => { modal.hidden = true; };
    document.querySelectorAll('[data-enrollment-open]').forEach((btn) => {
      btn.addEventListener('click', open);
    });
    modal.querySelectorAll('[data-enrollment-close]').forEach((btn) => {
      btn.addEventListener('click', close);
    });
    modal.addEventListener('click', (event) => {
      if (event.target === modal) close();
    });
  })();

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

  // Fleet status cards — compact meters; uplift modal with add form + history table
  (function initFleetStatusCards() {
    const grid = page.querySelector('[data-fleet-status]');
    if (!grid) {
      return;
    }
    const canManage = grid.getAttribute('data-fleet-can-manage') === '1';
    const csrf = grid.getAttribute('data-fleet-csrf') || '';
    const actionUrl = grid.getAttribute('data-fleet-action-url') || '';
    const escapeHtml = (value) => String(value ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');

    const renderHistory = (rows) => {
      const body = document.getElementById('fleet-uplift-history-body');
      if (!body) {
        return;
      }
      if (!Array.isArray(rows) || rows.length === 0) {
        body.innerHTML = '<p class="fleet-uplift-empty">No fuel uplifts logged yet.</p>';
        return;
      }
      const head = '<table class="fleet-uplift-table"><thead><tr><th>When</th><th>Fuel after</th><th>Notes</th>'
        + (canManage ? '<th></th>' : '')
        + '</tr></thead><tbody>';
      const cells = rows.map((row) => {
        const fuel = escapeHtml((row.fuel || '—') + ' ' + (row.unit || 'USG'));
        const when = escapeHtml(row.when || '—');
        const notes = escapeHtml(row.notes || '—');
        let action = '';
        if (canManage && Number(row.id || 0) > 0) {
          action = '<td><form method="post" action="' + escapeHtml(actionUrl) + '" onsubmit="return confirm(\'Delete this fuel uplift?\');">'
            + '<input type="hidden" name="csrf_token" value="' + escapeHtml(csrf) + '">'
            + '<input type="hidden" name="action" value="delete_fuel_uplift">'
            + '<input type="hidden" name="uplift_id" value="' + escapeHtml(String(row.id)) + '">'
            + '<button class="app-btn app-btn-secondary legs-btn-remove" type="submit"><span>Delete</span></button>'
            + '</form></td>';
        } else if (canManage) {
          action = '<td></td>';
        }
        return '<tr><td>' + when + '</td><td>' + fuel + '</td><td>' + notes + '</td>' + action + '</tr>';
      }).join('');
      body.innerHTML = head + cells + '</tbody></table>';
    };

    const openModal = (openEl) => {
      const modal = document.getElementById('fleet-uplift-modal');
      if (!modal || !openEl) {
        return;
      }
      const reg = openEl.getAttribute('data-aircraft-registration') || '';
      const unit = openEl.getAttribute('data-fuel-unit') || 'USG';
      const capacity = openEl.getAttribute('data-fuel-capacity') || '';
      let rows = [];
      try {
        rows = JSON.parse(openEl.getAttribute('data-uplifts') || '[]');
      } catch (_err) {
        rows = [];
      }
      const label = document.getElementById('fleet-uplift-aircraft-label');
      if (label) {
        label.textContent = reg || '—';
      }
      const aircraftId = document.getElementById('fleet-uplift-aircraft-id');
      const aircraftReg = document.getElementById('fleet-uplift-aircraft-registration');
      const unitEl = document.getElementById('fleet-uplift-unit');
      const hint = document.getElementById('fleet-uplift-capacity-hint');
      const fuelInput = document.getElementById('fleet-uplift-fuel');
      const notesInput = document.getElementById('fleet-uplift-notes');
      const whenInput = document.getElementById('fleet-uplift-when');
      if (aircraftId) {
        aircraftId.value = openEl.getAttribute('data-aircraft-id') || '';
      }
      if (aircraftReg) {
        aircraftReg.value = reg;
      }
      if (unitEl) {
        unitEl.textContent = unit;
      }
      if (hint) {
        hint.textContent = capacity !== '' ? ('Aircraft capacity ≈ ' + capacity + ' ' + unit) : '';
      }
      if (fuelInput) {
        fuelInput.value = '';
        if (capacity !== '') {
          fuelInput.max = capacity;
        }
      }
      if (notesInput) {
        notesInput.value = '';
      }
      if (whenInput) {
        whenInput.value = '';
      }
      renderHistory(rows);
      modal.hidden = false;
    };

    grid.addEventListener('click', (event) => {
      const openEl = event.target instanceof Element ? event.target.closest('[data-fleet-uplift-open]') : null;
      if (!openEl) {
        return;
      }
      openModal(openEl);
    });
    grid.addEventListener('keydown', (event) => {
      if (event.key !== 'Enter' && event.key !== ' ') {
        return;
      }
      const openEl = event.target instanceof Element ? event.target.closest('[data-fleet-uplift-open]') : null;
      if (!openEl) {
        return;
      }
      event.preventDefault();
      openModal(openEl);
    });
    const modal = document.getElementById('fleet-uplift-modal');
    if (modal) {
      modal.addEventListener('click', (event) => {
        if (event.target === modal || (event.target instanceof Element && event.target.closest('[data-fleet-uplift-close]'))) {
          modal.hidden = true;
        }
      });
    }
  })();

  // Live Debrief generation progress (Operational Legs + Reconstruction). No page refresh required.
  (function initDebriefProgressPoller() {
    const nodes = () => Array.from(page.querySelectorAll('[data-debrief-bundle-id]')).filter((el) => {
      const id = Number(el.getAttribute('data-debrief-bundle-id') || 0);
      return id > 0;
    });
    if (nodes().length === 0) {
      return;
    }

    const runningStatuses = new Set(['pending', 'claimed', 'running', 'retry_wait']);
    const markHtml = {
      ok: '<span class="legs-ev-icon legs-ev-ok" title="Complete" aria-label="Complete">✓</span>',
      warn: '<span class="legs-ev-icon legs-ev-warn" title="Pending" aria-label="Pending">○</span>',
      bad: '<span class="legs-ev-icon legs-ev-bad" title="Issue" aria-label="Issue">!</span>',
      na: '<span class="legs-ev-icon legs-ev-na" title="Not available" aria-label="Not available">–</span>',
    };

    const applyJob = (el, job) => {
      const status = String(job.status || '').toLowerCase();
      const running = !!job.running || runningStatuses.has(status);
      const progress = Math.max(0, Math.min(100, Number(job.progress || 0)));
      const message = String(job.progress_message || '').trim();
      const error = String(job.error || '').trim();
      const debriefId = Number(job.debrief_id || 0);
      const progressWrap = el.querySelector('[data-debrief-progress]');
      const fill = el.querySelector('[data-debrief-progress-fill]');
      const label = el.querySelector('[data-debrief-progress-label]');
      const mark = el.querySelector('[data-debrief-mark]');
      const jobStatus = el.querySelector('[data-debrief-job-status]');
      const jobError = el.querySelector('[data-debrief-job-error]');
      const review = el.querySelector('[data-debrief-review-link]');

      el.setAttribute('data-debrief-status', running ? status : (debriefId > 0 || status === 'succeeded' ? 'ready' : status));
      if (debriefId > 0) {
        el.setAttribute('data-debrief-id', String(debriefId));
      }

      let state = 'na';
      if (running) {
        state = 'warn';
      } else if (status === 'failed' || status === 'dead_letter') {
        state = 'bad';
      } else if (debriefId > 0 || status === 'succeeded') {
        state = 'ok';
      }

      el.classList.remove('legs-ev-ok', 'legs-ev-warn', 'legs-ev-bad', 'legs-ev-na');
      if (el.classList.contains('legs-ev')) {
        el.classList.add('legs-ev-' + state);
      }
      if (mark) {
        mark.innerHTML = markHtml[state] || markHtml.na;
      }
      if (progressWrap) {
        progressWrap.hidden = !running;
      }
      if (fill) {
        fill.style.width = progress + '%';
        fill.classList.toggle('is-evidence-active', running);
      }
      if (label) {
        label.textContent = running
          ? ((message !== '' ? message + ' · ' : '') + progress + '%')
          : (status === 'failed' && error !== '' ? error : '');
      }
      if (jobStatus) {
        jobStatus.textContent = status !== '' ? status.toUpperCase() : '';
      }
      if (jobError) {
        jobError.textContent = error;
        jobError.hidden = error === '';
      }
      if (review && debriefId > 0) {
        review.style.display = '';
        review.href = '/admin/master_logbook.php?tab=reconstruction&debrief_id=' + encodeURIComponent(String(debriefId));
        if (!review.textContent || review.textContent.trim() === 'Review Debrief') {
          review.textContent = 'Review Debrief';
        }
      }

      const generateBtn = el.closest('td')?.querySelector('form[action*="manual_bundle_debrief"] button[type="submit"]');
      if (generateBtn) {
        if (running) {
          generateBtn.disabled = true;
          generateBtn.textContent = 'Generating Debrief…';
          generateBtn.title = 'Debrief generation is running.';
        } else if (status === 'succeeded' || debriefId > 0) {
          generateBtn.disabled = false;
          generateBtn.textContent = 'Regenerate Debrief';
          generateBtn.removeAttribute('title');
        }
      }
    };

    let timer = null;
    const poll = async () => {
      const els = nodes();
      if (els.length === 0) {
        return;
      }
      const ids = Array.from(new Set(els.map((el) => Number(el.getAttribute('data-debrief-bundle-id') || 0)).filter((id) => id > 0)));
      if (ids.length === 0) {
        return;
      }
      try {
        const response = await fetch('/admin/api/structured_debrief_job_status.php?bundle_ids=' + encodeURIComponent(ids.join(',')), {
          credentials: 'same-origin',
        });
        const payload = await response.json();
        if (!response.ok || !payload.ok || !Array.isArray(payload.jobs)) {
          return;
        }
        const byBundle = new Map();
        payload.jobs.forEach((job) => {
          const bundleId = Number(job.bundle_id || 0);
          if (bundleId > 0) {
            byBundle.set(bundleId, job);
          }
        });
        let anyRunning = false;
        els.forEach((el) => {
          const bundleId = Number(el.getAttribute('data-debrief-bundle-id') || 0);
          const job = byBundle.get(bundleId);
          if (!job) {
            return;
          }
          applyJob(el, job);
          if (job.running || runningStatuses.has(String(job.status || '').toLowerCase())) {
            anyRunning = true;
          }
        });
        if (timer) {
          window.clearInterval(timer);
          timer = null;
        }
        timer = window.setInterval(poll, anyRunning ? 2000 : 5000);
      } catch (error) {
        // Ignore transient polling failures; retry on next tick.
      }
    };

    poll();
    timer = window.setInterval(poll, 2000);
  })();

  // Operational Legs — structured edit modal + debrief copy panel
  (function initOperationalLegs() {
    const table = page.querySelector('[data-operational-legs-table]');
    const editModal = document.getElementById('legs-edit-modal');
    const debriefModal = document.getElementById('legs-debrief-modal');
    const crewBox = document.getElementById('legs-edit-crew');
    const form = document.getElementById('legs-edit-form');
    const canEditLegs = <?= $cvrMlCanEdit ? 'true' : 'false' ?>;
    const syncSegmentOperationTotals = () => {
      const opsSeg = document.getElementById('legs-edit-ops-segments');
      if (!opsSeg || opsSeg.hidden) return;
      let takeoffs = 0;
      let landings = 0;
      opsSeg.querySelectorAll('[data-segment-ops="takeoff"]').forEach((el) => {
        takeoffs += Math.max(0, Number(el.value) || 0);
      });
      opsSeg.querySelectorAll('[data-segment-ops="landing"]').forEach((el) => {
        landings += Math.max(0, Number(el.value) || 0);
      });
      const toInput = document.getElementById('legs-edit-to');
      const ldgInput = document.getElementById('legs-edit-ldg');
      if (toInput) toInput.value = String(takeoffs);
      if (ldgInput) ldgInput.value = String(landings);
    };

    const syncSegmentMeterFuelTotals = () => {
      const meterSeg = document.getElementById('legs-edit-meter-segments');
      const fuelSeg = document.getElementById('legs-edit-fuel-segments');
      if (!meterSeg || meterSeg.hidden) return;
      const hobbsStarts = Array.from(meterSeg.querySelectorAll('[data-segment-meter="hobbs-start"]'));
      const hobbsEnds = Array.from(meterSeg.querySelectorAll('[data-segment-meter="hobbs-end"]'));
      const tachoStarts = Array.from(meterSeg.querySelectorAll('[data-segment-meter="tacho-start"]'));
      const tachoEnds = Array.from(meterSeg.querySelectorAll('[data-segment-meter="tacho-end"]'));
      if (hobbsStarts.length && hobbsEnds.length) {
        document.getElementById('legs-edit-hobbs-start').value = oneDecimal(hobbsStarts[0].value);
        document.getElementById('legs-edit-hobbs-end').value = oneDecimal(hobbsEnds[hobbsEnds.length - 1].value);
      }
      if (tachoStarts.length && tachoEnds.length) {
        document.getElementById('legs-edit-tacho-start').value = oneDecimal(tachoStarts[0].value);
        document.getElementById('legs-edit-tacho-end').value = oneDecimal(tachoEnds[tachoEnds.length - 1].value);
      }
      meterSeg.querySelectorAll('.leg-edit-segment').forEach((card) => {
        const hs = Number(card.querySelector('[data-segment-meter="hobbs-start"]')?.value);
        const he = Number(card.querySelector('[data-segment-meter="hobbs-end"]')?.value);
        const ts = Number(card.querySelector('[data-segment-meter="tacho-start"]')?.value);
        const te = Number(card.querySelector('[data-segment-meter="tacho-end"]')?.value);
        const hd = card.querySelector('[data-segment-diff="hobbs"]');
        const td = card.querySelector('[data-segment-diff="tacho"]');
        if (hd) hd.textContent = Number.isFinite(hs) && Number.isFinite(he) && he >= hs ? oneDecimal(he - hs) : '—';
        if (td) td.textContent = Number.isFinite(ts) && Number.isFinite(te) && te >= ts ? oneDecimal(te - ts) : '—';
      });
      if (fuelSeg && !fuelSeg.hidden) {
        const fuelDeps = Array.from(fuelSeg.querySelectorAll('[data-segment-fuel="dep"]'));
        const fuelLdgs = Array.from(fuelSeg.querySelectorAll('[data-segment-fuel="ldg"]'));
        if (fuelDeps.length && fuelLdgs.length) {
          document.getElementById('legs-edit-fuel-dep').value = oneDecimal(fuelDeps[0].value);
          document.getElementById('legs-edit-fuel-ldg').value = oneDecimal(fuelLdgs[fuelLdgs.length - 1].value);
        }
        fuelSeg.querySelectorAll('.leg-edit-segment').forEach((card) => {
          const fd = Number(card.querySelector('[data-segment-fuel="dep"]')?.value);
          const fl = Number(card.querySelector('[data-segment-fuel="ldg"]')?.value);
          const burn = card.querySelector('[data-segment-diff="fuel"]');
          if (burn) burn.textContent = Number.isFinite(fd) && Number.isFinite(fl) ? oneDecimal(fd - fl) : '—';
        });
      }
      updateDerived();
      syncFinancialFlightHoursFromHobbs();
    };

    const segmentFieldsEditable = () => canEditLegs && !financialLocked;

    const applyRecordLockState = () => {
      const editable = canEditLegs && !financialLocked;
      form.querySelectorAll('input, select, textarea, button').forEach((el) => {
        if (!(el instanceof HTMLElement)) return;
        if (el.closest('.leg-edit-fin-unlock')) return;
        if (el.matches('[data-legs-edit-close], .leg-edit-cancel')) return;
        if (el.type === 'hidden') return;
        if (el.id === 'legs-edit-save-btn' || el.id === 'legs-edit-save-lock-btn') return;
        if (el.tagName === 'BUTTON' || el.tagName === 'SELECT' || el.type === 'checkbox' || el.type === 'radio' || el.type === 'file' || el.type === 'date') {
          el.disabled = !editable;
          return;
        }
        el.readOnly = !editable;
        el.disabled = !editable;
      });
      form.querySelectorAll('[data-segment-field]').forEach((el) => {
        el.readOnly = !editable;
        el.disabled = !editable;
      });
      const addCrew = document.getElementById('legs-edit-add-crew');
      if (addCrew) addCrew.disabled = !editable;
      const splitBtn = document.getElementById('legs-split-btn');
      if (splitBtn) splitBtn.disabled = !editable || splitBtn.hidden;
      const saveBtn = document.getElementById('legs-edit-save-btn');
      if (saveBtn) saveBtn.disabled = !editable;
      const lockBtn = document.getElementById('legs-edit-save-lock-btn');
      if (lockBtn) {
        lockBtn.disabled = financialLocked || !isFinancialComplete();
      }
    };

    const applySegmentFieldLockState = () => {
      applyRecordLockState();
    };
    if (!table || !editModal || !debriefModal || !crewBox || !form) {
      return;
    }

    const crewCatalog = JSON.parse(document.getElementById('legs-crew-catalog')?.textContent || '[]');
    const flightMissions = JSON.parse(document.getElementById('legs-flight-missions')?.textContent || '[]');
    const instructionalRates = JSON.parse(document.getElementById('legs-financial-rates')?.textContent || '[]');
    const aircraftRentalRates = JSON.parse(document.getElementById('legs-aircraft-rental-rates')?.textContent || '{}');
    const customerBalances = JSON.parse(document.getElementById('legs-customer-balances')?.textContent || '{}');
    const isAdminAudience = <?= json_encode($cvrMlAudience === 'admin') ?>;
    let financialLocked = false;
    let financialFlightHoursTouched = false;
    const roleOptions = [
      ['student', 'Student'],
      ['pic', 'PIC'],
      ['pilot_flying', 'Pilot Flying'],
      ['pilot_monitoring', 'Pilot Monitoring'],
      ['flight_instructor', 'Flight Instructor'],
      ['supervising_instructor', 'Supervising Instructor'],
      ['examiner', 'Examiner'],
      ['safety_pilot', 'Safety Pilot'],
    ];

    let baselineSnapshot = '';
    let currentLeg = null;
    const trackPanel = document.getElementById('legs-track-panel');
    const trackChart = (window.IPCALegTrackChart && trackPanel)
      ? window.IPCALegTrackChart.create(trackPanel)
      : null;

    const openModal = (modal) => {
      modal.hidden = false;
      if (modal === editModal && trackChart) {
        trackChart.invalidate();
      }
    };
    const closeModal = (modal) => {
      if (modal === editModal && trackChart) {
        trackChart.reset();
      }
      modal.hidden = true;
    };
    const oneDecimal = (v) => {
      const n = Number(v);
      return Number.isFinite(n) ? (Math.round(n * 10) / 10).toFixed(1) : '';
    };
    const showError = (key, message) => {
      const el = form.querySelector('[data-error-for="' + key + '"]');
      if (!el) return;
      el.textContent = message || '';
      el.classList.toggle('is-visible', !!message);
    };
    const clearErrors = () => {
      form.querySelectorAll('[data-error-for]').forEach((el) => {
        el.textContent = '';
        el.classList.remove('is-visible');
      });
    };
    const snapshotForm = () => new FormData(form);

    const serializeSnapshot = () => {
      const data = new FormData(form);
      const pairs = [];
      data.forEach((value, key) => { pairs.push(key + '=' + String(value)); });
      return pairs.sort().join('&');
    };

    const isDirty = () => serializeSnapshot() !== baselineSnapshot;

    const applyAircraftUnits = () => {
      const select = document.getElementById('legs-edit-aircraft');
      const opt = select?.selectedOptions?.[0];
      const fuelUnit = opt?.getAttribute('data-fuel-unit') || 'USG';
      const oilUnit = opt?.getAttribute('data-oil-unit') || '%';
      const aircraftId = opt?.getAttribute('data-aircraft-id') || '';
      document.getElementById('legs-edit-fuel-unit').value = fuelUnit;
      document.getElementById('legs-edit-oil-unit').value = oilUnit;
      document.querySelectorAll('[data-fuel-suffix]').forEach((el) => { el.textContent = fuelUnit; });
      document.getElementById('legs-edit-oil-suffix').textContent = oilUnit;
      document.getElementById('legs-csv-aircraft-id').value = aircraftId;
      document.getElementById('legs-csv-aircraft').value = select?.value || '';
      document.getElementById('legs-csv-aircraft-label').textContent = select?.value || '—';
    };

    const ensureMissionOption = (code, label) => {
      const select = document.getElementById('legs-edit-mission');
      if (!select || !code) return;
      const exists = Array.from(select.options).some((o) => o.value === code);
      if (!exists) {
        const opt = document.createElement('option');
        opt.value = code;
        opt.textContent = (label || code) + ' (Legacy mission)';
        opt.dataset.legacy = '1';
        select.appendChild(opt);
      }
      select.value = code;
    };

    const updateDerived = () => {
      const hs = Number(document.getElementById('legs-edit-hobbs-start').value);
      const he = Number(document.getElementById('legs-edit-hobbs-end').value);
      const ts = Number(document.getElementById('legs-edit-tacho-start').value);
      const te = Number(document.getElementById('legs-edit-tacho-end').value);
      const fd = Number(document.getElementById('legs-edit-fuel-dep').value);
      const fl = Number(document.getElementById('legs-edit-fuel-ldg').value);
      document.getElementById('legs-edit-hobbs-diff').textContent =
        Number.isFinite(hs) && Number.isFinite(he) && he >= hs ? oneDecimal(he - hs) : '—';
      document.getElementById('legs-edit-tacho-diff').textContent =
        Number.isFinite(ts) && Number.isFinite(te) && te >= ts ? oneDecimal(te - ts) : '—';
      document.getElementById('legs-edit-fuel-burn').textContent =
        Number.isFinite(fd) && Number.isFinite(fl) ? oneDecimal(fd - fl) : '—';

      const date = document.getElementById('legs-edit-date').value;
      const time = document.getElementById('legs-edit-off-time').value;
      const hobbsDiff = Number.isFinite(hs) && Number.isFinite(he) ? (he - hs) : NaN;
      if (date && /^([01]\d|2[0-3]):[0-5]\d$/.test(time) && Number.isFinite(hobbsDiff) && hobbsDiff >= 0) {
        const [hh, mm] = time.split(':').map(Number);
        const totalMin = hh * 60 + mm + Math.round(hobbsDiff * 60);
        const onH = Math.floor((((totalMin % 1440) + 1440) % 1440) / 60);
        const onM = ((totalMin % 1440) + 1440) % 60;
        document.getElementById('legs-edit-onblock').textContent =
          String(onH).padStart(2, '0') + ':' + String(onM).padStart(2, '0');
      } else {
        document.getElementById('legs-edit-onblock').textContent = '—';
      }
      document.getElementById('legs-edit-off-combined').value =
        date && time ? (date + ' ' + time) : '';
      syncFinancialFlightHoursFromHobbs();
      refreshFinancialOverview();
    };

    const money = (n) => {
      const v = Number(n);
      if (!Number.isFinite(v)) return '$0.00';
      return '$' + v.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    };

    const selectedOptionLabel = (select) => {
      const opt = select?.selectedOptions?.[0];
      if (!opt) return '';
      return String(opt.textContent || '').replace(/\s\(Instr\)$/, '').trim();
    };

    const setFinancialLockedState = (locked) => {
      financialLocked = !!locked;
      const banner = document.getElementById('legs-fin-locked-banner');
      const unlockBox = document.getElementById('legs-fin-unlock-box');
      const recordLock = document.getElementById('legs-record-lock');
      banner?.classList.toggle('is-on', financialLocked);
      unlockBox?.classList.toggle('is-on', financialLocked);
      recordLock?.classList.toggle('is-on', financialLocked);
      applyRecordLockState();
    };

    const isFinancialComplete = () => {
      const customer = Number(document.getElementById('legs-fin-customer')?.value || 0);
      const instructor = Number(document.getElementById('legs-fin-instructor')?.value || 0);
      const rate = Number(document.getElementById('legs-fin-rate')?.value || 0);
      const flightHours = Number(document.getElementById('legs-fin-flight-hours')?.value);
      const groundHours = Number(document.getElementById('legs-fin-ground-hours')?.value || 0);
      const preflightHours = Number(document.getElementById('legs-fin-preflight')?.value || 0);
      if (!(customer > 0) || !Number.isFinite(flightHours) || flightHours < 0) return false;
      const instructionHours = (Number.isFinite(flightHours) ? flightHours : 0)
        + (Number.isFinite(groundHours) ? groundHours : 0)
        + (Number.isFinite(preflightHours) ? preflightHours : 0);
      // Rental-only / experience building: instructor + rate not required when instruction is 0.
      if (instructionHours <= 0) return true;
      return instructor > 0 && rate > 0;
    };

    const syncFinancialFlightHoursFromHobbs = () => {
      if (financialLocked || financialFlightHoursTouched) return;
      const hs = Number(document.getElementById('legs-edit-hobbs-start').value);
      const he = Number(document.getElementById('legs-edit-hobbs-end').value);
      const flightInput = document.getElementById('legs-fin-flight-hours');
      if (!flightInput) return;
      if (Number.isFinite(hs) && Number.isFinite(he) && he >= hs) {
        flightInput.value = oneDecimal(he - hs);
      }
    };

    const financialHasPersistedInstructionHours = (fin) => {
      if (!fin || typeof fin !== 'object') return false;
      if (Number(fin.id || 0) > 0) return true;
      if (fin.is_locked || fin.status === 'locked') return true;
      if (fin.session_total_usd != null || fin.grand_total_usd != null) return true;
      if (fin.overview != null) return true;
      return false;
    };

    const financialRentalHoursFromHobbs = () => {
      const hs = Number(document.getElementById('legs-edit-hobbs-start')?.value);
      const he = Number(document.getElementById('legs-edit-hobbs-end')?.value);
      if (Number.isFinite(hs) && Number.isFinite(he) && he >= hs) {
        return Math.round((he - hs) * 10) / 10;
      }
      return 0;
    };

    const refreshFinancialOverview = () => {
      const body = document.getElementById('legs-fin-overview-body');
      const lockBtn = document.getElementById('legs-edit-save-lock-btn');
      if (!body) return;
      const reg = String(document.getElementById('legs-fin-aircraft-reg')?.value || document.getElementById('legs-edit-aircraft')?.value || '').toUpperCase();
      const rental = aircraftRentalRates[reg] || { display_label: reg || 'Aircraft', rate_usd_per_hour: 0 };
      const rateSelect = document.getElementById('legs-fin-rate');
      const groundRateSelect = document.getElementById('legs-fin-ground-rate');
      if (groundRateSelect && rateSelect) {
        groundRateSelect.value = rateSelect.value;
      }
      const rateOpt = rateSelect?.selectedOptions?.[0];
      const instrRate = Number(rateOpt?.getAttribute('data-rate') || 0);
      const aircraftRate = Number(rental.rate_usd_per_hour || 0);
      const rentalHours = financialRentalHoursFromHobbs();
      const flightHours = Number(document.getElementById('legs-fin-flight-hours')?.value || 0);
      const groundHours = Number(document.getElementById('legs-fin-ground-hours')?.value || 0);
      const preflightHours = Number(document.getElementById('legs-fin-preflight')?.value || 0);
      const customerSelect = document.getElementById('legs-fin-customer');
      const instructorSelect = document.getElementById('legs-fin-instructor');
      const customerId = Number(customerSelect?.value || 0);
      const instructorName = selectedOptionLabel(instructorSelect) || 'Instructor';
      const customerName = selectedOptionLabel(customerSelect) || '—';
      const aircraftLabel = String(rental.display_label || reg || '—');
      document.getElementById('legs-fin-head-customer').textContent = customerName;
      document.getElementById('legs-fin-head-aircraft').textContent = aircraftLabel;
      document.getElementById('legs-fin-customer-name').value = customerName === '—' ? '' : customerName;
      document.getElementById('legs-fin-instructor-name').value = instructorName === 'Instructor' && !instructorSelect?.value ? '' : instructorName;
      document.getElementById('legs-fin-aircraft-reg').value = reg;

      const aircraftTotal = Math.round(rentalHours * aircraftRate * 100) / 100;
      const flightTotal = Math.round(flightHours * instrRate * 100) / 100;
      const groundTotal = Math.round(groundHours * instrRate * 100) / 100;
      const preflightTotal = Math.round(preflightHours * instrRate * 100) / 100;
      const sessionTotal = Math.round((aircraftTotal + flightTotal + groundTotal + preflightTotal) * 100) / 100;
      const existing = Number(customerBalances[String(customerId)] || customerBalances[customerId] || 0);
      const grand = Math.round((existing + sessionTotal) * 100) / 100;

      let html = '';
      html += '<tr class="is-section"><th>Aircraft Rental</th><th class="num">Hours</th><th class="num">Total</th></tr>';
      html += '<tr><td>#' + (reg || '—') + ' ' + aircraftLabel + '</td><td class="num">' + oneDecimal(rentalHours || 0) + '</td><td class="num">' + money(aircraftTotal) + '</td></tr>';
      html += '<tr class="is-section"><th>Instruction</th><th class="num">Hours</th><th class="num">Total</th></tr>';
      if (flightHours > 0) {
        html += '<tr><td>Flight Time (' + instructorName + ')</td><td class="num">' + oneDecimal(flightHours) + '</td><td class="num">' + money(flightTotal) + '</td></tr>';
      }
      if (groundHours > 0) {
        html += '<tr><td>Ground Instruction (' + instructorName + ')</td><td class="num">' + oneDecimal(groundHours) + '</td><td class="num">' + money(groundTotal) + '</td></tr>';
      }
      if (preflightHours > 0) {
        html += '<tr><td>Preflight Briefing (' + instructorName + ')</td><td class="num">' + oneDecimal(preflightHours) + '</td><td class="num">' + money(preflightTotal) + '</td></tr>';
      }
      if (!(flightHours > 0) && !(groundHours > 0) && !(preflightHours > 0)) {
        html += '<tr><td colspan="3" class="intake-muted">No instruction hours yet.</td></tr>';
      }
      html += '<tr><td>Existing Balance</td><td class="num"></td><td class="num">' + money(existing) + '</td></tr>';
      html += '<tr class="is-total"><td></td><td class="num">Grand total</td><td class="num">' + money(grand) + '</td></tr>';
      body.innerHTML = html;
      if (lockBtn) {
        lockBtn.disabled = financialLocked || !isFinancialComplete();
      }
    };

    const fillFinancial = (leg) => {
      const fin = leg?.financial || {};
      financialFlightHoursTouched = false;
      document.getElementById('legs-fin-flight-uuid').value = leg?.workflow_flight_record_uuid || '';
      document.getElementById('legs-fin-aircraft-reg').value = String(leg?.aircraft_registration || '').toUpperCase();
      document.getElementById('legs-fin-lock-flag').value = '';
      const customer = document.getElementById('legs-fin-customer');
      const instructor = document.getElementById('legs-fin-instructor');
      const rate = document.getElementById('legs-fin-rate');
      if (customer) {
        customer.value = fin.customer_user_id ? String(fin.customer_user_id) : '';
        if (!customer.value && fin.customer_name) {
          const match = Array.from(customer.options).find((o) => String(o.textContent || '').trim().toLowerCase() === String(fin.customer_name).toLowerCase());
          if (match) customer.value = match.value;
        }
      }
      if (instructor) {
        instructor.value = fin.instructor_user_id ? String(fin.instructor_user_id) : '';
        if (!instructor.value && fin.instructor_name) {
          const target = String(fin.instructor_name).toLowerCase();
          const match = Array.from(instructor.options).find((o) => String(o.textContent || '').replace(/\s\(Instr\)$/, '').trim().toLowerCase() === target);
          if (match) instructor.value = match.value;
        }
      }
      if (rate && fin.instructional_rate_id) rate.value = String(fin.instructional_rate_id);
      document.getElementById('legs-fin-preflight').value = oneDecimal(fin.preflight_briefing_hours ?? 0) || '0.0';
      const persisted = financialHasPersistedInstructionHours(fin);
      const instructionHours = persisted
        ? (fin.flight_instruction_hours ?? 0)
        : (fin.flight_instruction_hours ?? leg?.hobbs_delta ?? 0);
      document.getElementById('legs-fin-flight-hours').value = oneDecimal(instructionHours) || '0.0';
      // Keep intentional values (including 0.0) from being overwritten by Hobbs auto-sync.
      financialFlightHoursTouched = persisted;
      document.getElementById('legs-fin-ground-hours').value = oneDecimal(fin.ground_instruction_hours ?? 0.3) || '0.3';
      if (fin.customer_name) document.getElementById('legs-fin-customer-name').value = fin.customer_name;
      if (fin.instructor_name) document.getElementById('legs-fin-instructor-name').value = fin.instructor_name;
      setFinancialLockedState(!!fin.is_locked);
      refreshFinancialOverview();
    };

    const normalizeTimeInput = (input) => {
      let v = String(input.value || '').replace(/[^\d:]/g, '');
      if (v.length === 3 && !v.includes(':')) v = v.slice(0, 1) + ':' + v.slice(1);
      if (v.length === 4 && !v.includes(':')) v = v.slice(0, 2) + ':' + v.slice(2);
      if (v.length > 5) v = v.slice(0, 5);
      input.value = v;
    };

    const renderCrew = (crew) => {
      crewBox.innerHTML = '';
      const list = Array.isArray(crew) && crew.length ? crew : [{ role: 'student', name: '', person_id: null, historical: false }];
      list.forEach((member, index) => {
        const row = document.createElement('div');
        row.className = 'leg-edit-crew-row';
        const role = String(member.role || 'student').toLowerCase();
        const name = String(member.name || member.personName || '');
        const personId = member.person_id || member.id || '';
        const matched = crewCatalog.find((u) => Number(u.id) === Number(personId)
          || String(u.name).toLowerCase() === name.toLowerCase());
        const historical = !matched && name !== '';
        let roleHtml = '<select name="leg[crew][' + index + '][role]">';
        roleOptions.forEach(([value, label]) => {
          roleHtml += '<option value="' + value + '"' + (role === value || (role === 'instructor' && value === 'flight_instructor') ? ' selected' : '') + '>' + label + '</option>';
        });
        roleHtml += '</select>';
        let userHtml = '<select name="leg[crew][' + index + '][person_id]" data-crew-user>';
        userHtml += '<option value="">Select crew member</option>';
        if (historical) {
          userHtml += '<option value="historical" selected data-name="' + name.replace(/"/g, '&quot;') + '">' + name + ' (Historical crew entry)</option>';
        }
        crewCatalog.forEach((u) => {
          const selected = matched && Number(matched.id) === Number(u.id) ? ' selected' : '';
          const badge = u.kind === 'staff' ? 'Staff' : 'Student';
          userHtml += '<option value="' + u.id + '" data-name="' + String(u.name).replace(/"/g, '&quot;') + '"' + selected + '>'
            + u.name + ' · ' + badge + '</option>';
        });
        userHtml += '</select>';
        userHtml += '<input type="hidden" name="leg[crew][' + index + '][personName]" value="' + (matched ? matched.name : name).replace(/"/g, '&quot;') + '">';
        row.innerHTML = roleHtml + userHtml
          + '<button type="button" class="leg-edit-crew-remove" data-legs-crew-remove aria-label="Remove crew member">Remove</button>';
        crewBox.appendChild(row);
      });
    };

    const syncCrewHiddenNames = () => {
      crewBox.querySelectorAll('.leg-edit-crew-row').forEach((row) => {
        const userSelect = row.querySelector('[data-crew-user]');
        const hidden = row.querySelector('input[type="hidden"]');
        if (!userSelect || !hidden) return;
        const opt = userSelect.selectedOptions[0];
        hidden.value = opt?.getAttribute('data-name') || opt?.textContent?.replace(/\s·\s(Staff|Student).*$/, '').replace(/\s\(Historical.*\)$/, '') || '';
      });
    };

    const briefingBox = document.getElementById('legs-briefing-copy-box');
    const briefingStatus = document.getElementById('legs-briefing-status');
    const briefingCopyBtn = document.getElementById('legs-briefing-copy');
    let briefingLoadToken = 0;

    const setBriefingStatus = (text, kind) => {
      if (!briefingStatus) return;
      briefingStatus.textContent = text || '';
      briefingStatus.classList.toggle('is-error', kind === 'error');
      briefingStatus.classList.toggle('is-ok', kind === 'ok');
    };

    const loadGenericBriefing = async (leg) => {
      if (!briefingBox || !briefingCopyBtn) return;
      const token = ++briefingLoadToken;
      briefingBox.value = '';
      briefingCopyBtn.disabled = true;
      setBriefingStatus('Loading Generic Briefing…', '');
      const debriefId = Number(leg?.debrief_id || 0);
      const bundleId = Number(leg?.bundle_id || 0);
      if (debriefId <= 0 && bundleId <= 0) {
        setBriefingStatus('No debrief is linked to this leg yet. Generate one from Reconstruction.', 'error');
        return;
      }
      try {
        const url = '/admin/api/operational_leg_debrief_copy.php?format=generic_briefing'
          + '&debrief_id=' + encodeURIComponent(String(debriefId || 0))
          + '&bundle_id=' + encodeURIComponent(String(bundleId || 0));
        const response = await fetch(url, { credentials: 'same-origin' });
        const payload = await response.json();
        if (token !== briefingLoadToken) return;
        if (payload.ok && payload.copy_text) {
          briefingBox.value = payload.copy_text;
          briefingCopyBtn.disabled = false;
          setBriefingStatus('Generic Briefing ready to copy.', 'ok');
        } else {
          briefingBox.value = '';
          setBriefingStatus(payload.message || 'Generic Briefing is not available for this leg.', 'error');
        }
      } catch (error) {
        if (token !== briefingLoadToken) return;
        setBriefingStatus(error instanceof Error ? error.message : 'Could not load Generic Briefing.', 'error');
      }
    };

    const formatSegmentLocal = (utc, timezone) => {
      const raw = String(utc || '').trim();
      if (!raw) return { date: '', time: '—' };
      try {
        const dt = new Date(raw.includes('T') ? raw + (raw.endsWith('Z') ? '' : 'Z') : raw.replace(' ', 'T') + 'Z');
        if (Number.isNaN(dt.getTime())) return { date: '', time: '—' };
        const fmt = new Intl.DateTimeFormat('en-US', {
          timeZone: timezone || 'America/Los_Angeles',
          year: 'numeric',
          month: '2-digit',
          day: '2-digit',
          hour: '2-digit',
          minute: '2-digit',
          hour12: false,
        });
        const parts = Object.fromEntries(fmt.formatToParts(dt).map((p) => [p.type, p.value]));
        return {
          date: `${parts.year}-${parts.month}-${parts.day}`,
          time: `${parts.hour}:${parts.minute}`,
        };
      } catch (e) {
        return { date: '', time: '—' };
      }
    };

    const renderAnnotatedSegments = (leg) => {
      const segments = Array.isArray(leg?.leg_segments) ? leg.leg_segments : [];
      const hasSegments = segments.length >= 2;
      const singleRoute = document.getElementById('legs-edit-single-route');
      const singleMeters = document.getElementById('legs-edit-single-meters');
      const singleFuel = document.getElementById('legs-edit-single-fuel');
      const singleOps = document.getElementById('legs-edit-single-ops');
      const routeSeg = document.getElementById('legs-edit-route-segments');
      const meterSeg = document.getElementById('legs-edit-meter-segments');
      const fuelSeg = document.getElementById('legs-edit-fuel-segments');
      const opsSeg = document.getElementById('legs-edit-ops-segments');
      const fuelUnit = document.getElementById('legs-edit-fuel-unit')?.value || 'USG';
      const toggleRequired = (ids, on) => {
        ids.forEach((id) => {
          const el = document.getElementById(id);
          if (!el) return;
          if (on) el.setAttribute('required', 'required');
          else el.removeAttribute('required');
        });
      };
      if (singleRoute) singleRoute.hidden = hasSegments;
      if (singleMeters) singleMeters.hidden = hasSegments;
      if (singleFuel) singleFuel.hidden = hasSegments;
      if (singleOps) singleOps.hidden = hasSegments;
      toggleRequired([
        'legs-edit-dep', 'legs-edit-arr', 'legs-edit-date', 'legs-edit-off-time',
        'legs-edit-hobbs-start', 'legs-edit-hobbs-end', 'legs-edit-tacho-start', 'legs-edit-tacho-end',
        'legs-edit-fuel-dep', 'legs-edit-fuel-ldg',
        'legs-edit-to', 'legs-edit-ldg',
      ], !hasSegments);
      // Session totals remain posted even when segment cards are shown.
      if (hasSegments) {
        ['legs-edit-dep', 'legs-edit-arr', 'legs-edit-date', 'legs-edit-off-time',
          'legs-edit-hobbs-start', 'legs-edit-hobbs-end', 'legs-edit-tacho-start', 'legs-edit-tacho-end',
          'legs-edit-fuel-dep', 'legs-edit-fuel-ldg',
          'legs-edit-to', 'legs-edit-ldg'].forEach((id) => {
          const el = document.getElementById(id);
          if (el) el.disabled = false;
        });
      }
      if (!hasSegments) {
        if (routeSeg) { routeSeg.hidden = true; routeSeg.innerHTML = ''; }
        if (meterSeg) { meterSeg.hidden = true; meterSeg.innerHTML = ''; }
        if (fuelSeg) { fuelSeg.hidden = true; fuelSeg.innerHTML = ''; }
        if (opsSeg) { opsSeg.hidden = true; opsSeg.innerHTML = ''; }
        applySegmentFieldLockState();
        return;
      }
      const tz = leg.timezone || 'America/Los_Angeles';
      const esc = (value) => String(value ?? '')
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
      routeSeg.innerHTML = segments.map((seg, index) => {
        const n = Number(seg.sequence_number || (index + 1));
        const off = formatSegmentLocal(seg.off_block_utc, tz);
        const on = formatSegmentLocal(seg.on_block_utc, tz);
        return `<div class="leg-edit-segment">
          <div class="leg-edit-segment-title">Leg ${n}</div>
          <div class="leg-edit-grid">
            <div class="leg-edit-field"><span>Departure Airport</span><div style="font-weight:850">${esc(seg.departure_airport || '—')}</div></div>
            <div class="leg-edit-field"><span>Arrival Airport</span><div style="font-weight:850">${esc(seg.arrival_airport || '—')}</div></div>
            <div class="leg-edit-field"><span>Date</span><div style="font-weight:850">${esc(off.date || '—')}</div></div>
            <div class="leg-edit-field"><span>Off Block Time</span><div style="font-weight:850">${esc(off.time)} Local</div></div>
            <div class="leg-edit-field"><span>On Block Time</span><div style="font-weight:850">${esc(on.time)} Local</div></div>
          </div>
        </div>`;
      }).join('');
      routeSeg.hidden = false;

      const editableAttr = segmentFieldsEditable() ? '' : 'readonly';
      meterSeg.innerHTML = segments.map((seg, index) => {
        const n = Number(seg.sequence_number || (index + 1));
        const route = `${seg.departure_airport || '—'} - ${seg.arrival_airport || '—'}`;
        const dispatchId = Number(seg.dispatch_id || 0);
        const hs = oneDecimal(seg.starting_hobbs);
        const he = oneDecimal(seg.ending_hobbs);
        const hd = oneDecimal(seg.hobbs_delta != null ? seg.hobbs_delta : (Number(he) - Number(hs)));
        const ts = oneDecimal(seg.starting_tacho);
        const te = oneDecimal(seg.ending_tacho);
        const td = oneDecimal(seg.tacho_delta != null ? seg.tacho_delta : (Number(te) - Number(ts)));
        return `<div class="leg-edit-segment">
          <div class="leg-edit-segment-title">Leg ${n} (${esc(route)})</div>
          <input type="hidden" name="leg_segments[${index}][sequence_number]" value="${esc(n)}">
          <input type="hidden" name="leg_segments[${index}][dispatch_id]" value="${esc(dispatchId || '')}">
          <input type="hidden" name="leg_segments[${index}][departure_airport]" value="${esc(seg.departure_airport || '')}">
          <input type="hidden" name="leg_segments[${index}][arrival_airport]" value="${esc(seg.arrival_airport || '')}">
          <div class="leg-edit-grid">
            <div class="leg-edit-meter">
              <div class="leg-edit-meter-title">Hobbs</div>
              <div class="leg-edit-meter-row">
                <label class="leg-edit-field"><span>Start</span>
                  <input name="leg_segments[${index}][starting_hobbs]" data-segment-field data-segment-meter="hobbs-start" type="number" step="0.1" min="0" inputmode="decimal" value="${esc(hs)}" ${editableAttr}>
                </label>
                <div class="leg-edit-meter-arrow">→</div>
                <label class="leg-edit-field"><span>End</span>
                  <input name="leg_segments[${index}][ending_hobbs]" data-segment-field data-segment-meter="hobbs-end" type="number" step="0.1" min="0" inputmode="decimal" value="${esc(he)}" ${editableAttr}>
                </label>
              </div>
              <div class="leg-edit-derived"><strong>Difference</strong><span data-segment-diff="hobbs">${esc(hd || '—')}</span></div>
            </div>
            <div class="leg-edit-meter">
              <div class="leg-edit-meter-title">Tacho</div>
              <div class="leg-edit-meter-row">
                <label class="leg-edit-field"><span>Start</span>
                  <input name="leg_segments[${index}][starting_tacho]" data-segment-field data-segment-meter="tacho-start" type="number" step="0.1" min="0" inputmode="decimal" value="${esc(ts)}" ${editableAttr}>
                </label>
                <div class="leg-edit-meter-arrow">→</div>
                <label class="leg-edit-field"><span>End</span>
                  <input name="leg_segments[${index}][ending_tacho]" data-segment-field data-segment-meter="tacho-end" type="number" step="0.1" min="0" inputmode="decimal" value="${esc(te)}" ${editableAttr}>
                </label>
              </div>
              <div class="leg-edit-derived"><strong>Difference</strong><span data-segment-diff="tacho">${esc(td || '—')}</span></div>
            </div>
          </div>
        </div>`;
      }).join('');
      meterSeg.hidden = false;

      fuelSeg.innerHTML = segments.map((seg, index) => {
        const n = Number(seg.sequence_number || (index + 1));
        const route = `${seg.departure_airport || '—'} - ${seg.arrival_airport || '—'}`;
        const start = oneDecimal(seg.fuel_onboard);
        const end = oneDecimal(seg.fuel_remaining);
        const burn = oneDecimal(
          seg.fuel_burn != null
            ? seg.fuel_burn
            : (start !== '' && end !== '' ? Number(start) - Number(end) : '')
        );
        return `<div class="leg-edit-segment">
          <div class="leg-edit-segment-title">Leg ${n} (${esc(route)})</div>
          <div class="leg-edit-grid-3">
            <label class="leg-edit-field"><span>Fuel Departure</span>
              <div class="leg-edit-unit">
                <input name="leg_segments[${index}][fuel_onboard]" data-segment-field data-segment-fuel="dep" type="number" step="0.1" min="0" inputmode="decimal" value="${esc(start)}" ${editableAttr}>
                <span class="leg-edit-suffix">${esc(fuelUnit)}</span>
              </div>
            </label>
            <label class="leg-edit-field"><span>Fuel Landing</span>
              <div class="leg-edit-unit">
                <input name="leg_segments[${index}][fuel_remaining]" data-segment-field data-segment-fuel="ldg" type="number" step="0.1" min="0" inputmode="decimal" value="${esc(end)}" ${editableAttr}>
                <span class="leg-edit-suffix">${esc(fuelUnit)}</span>
              </div>
            </label>
            <div class="leg-edit-field"><span>Fuel Burn</span>
              <div class="leg-edit-derived" style="margin:0"><span data-segment-diff="fuel">${esc(burn || '—')}</span>&nbsp;<span class="leg-edit-suffix">${esc(fuelUnit)}</span></div>
            </div>
          </div>
        </div>`;
      }).join('');
      fuelSeg.hidden = false;

      opsSeg.innerHTML = segments.map((seg, index) => {
        const n = Number(seg.sequence_number || (index + 1));
        const route = `${seg.departure_airport || '—'} - ${seg.arrival_airport || '—'}`;
        const to = Number(seg.takeoff_count ?? 0);
        const ldg = Number(seg.landing_count ?? 0);
        return `<div class="leg-edit-segment">
          <div class="leg-edit-segment-title">Leg ${n} — ${esc(route)}</div>
          <div class="leg-edit-grid" style="max-width:420px">
            <label class="leg-edit-field"><span>Takeoffs</span>
              <input name="leg_segments[${index}][takeoff_count]" data-segment-field data-segment-ops="takeoff" type="number" min="0" step="1" inputmode="numeric" value="${esc(to)}" ${editableAttr}>
            </label>
            <label class="leg-edit-field"><span>Landings</span>
              <input name="leg_segments[${index}][landing_count]" data-segment-field data-segment-ops="landing" type="number" min="0" step="1" inputmode="numeric" value="${esc(ldg)}" ${editableAttr}>
            </label>
          </div>
        </div>`;
      }).join('');
      opsSeg.hidden = false;
      syncSegmentOperationTotals();
      syncSegmentMeterFuelTotals();
      applySegmentFieldLockState();
      const bindSegmentInputs = (root) => {
        root?.querySelectorAll('[data-segment-field]').forEach((input) => {
          input.addEventListener('input', () => {
            syncSegmentOperationTotals();
            syncSegmentMeterFuelTotals();
          });
          input.addEventListener('change', () => {
            syncSegmentOperationTotals();
            syncSegmentMeterFuelTotals();
          });
        });
      };
      bindSegmentInputs(meterSeg);
      bindSegmentInputs(fuelSeg);
      bindSegmentInputs(opsSeg);
    };

    const fillEditForm = (leg) => {
      currentLeg = leg;
      clearErrors();
      document.getElementById('legs-edit-dispatch-id').value = leg.dispatch_id || '';
      const tz = leg.timezone || 'America/Los_Angeles';
      document.getElementById('legs-edit-timezone').value = tz;
      document.getElementById('legs-edit-tz-label').textContent = '(' + tz + ')';
      document.getElementById('legs-edit-tz-value').textContent = tz;
      document.getElementById('legs-edit-aircraft').value = String(leg.aircraft_registration || '').toUpperCase();
      applyAircraftUnits();
      ensureMissionOption(String(leg.mission_code || '').toUpperCase(), String(leg.mission_code || ''));
      document.getElementById('legs-edit-dep').value = String(leg.departure_airport || '').toUpperCase();
      document.getElementById('legs-edit-arr').value = String(leg.arrival_airport || '').toUpperCase();
      const local = String(leg.off_block_local || '');
      const datePart = local.slice(0, 10);
      const timePart = local.includes('T') ? local.slice(11, 16) : (local.slice(11, 16) || '');
      document.getElementById('legs-edit-date').value = datePart;
      document.getElementById('legs-edit-off-time').value = timePart;
      document.getElementById('legs-edit-hobbs-start').value = oneDecimal(leg.starting_hobbs);
      document.getElementById('legs-edit-hobbs-end').value = oneDecimal(leg.ending_hobbs);
      document.getElementById('legs-edit-tacho-start').value = oneDecimal(leg.starting_tacho);
      document.getElementById('legs-edit-tacho-end').value = oneDecimal(leg.ending_tacho);
      document.getElementById('legs-edit-fuel-dep').value = oneDecimal(leg.fuel_onboard);
      document.getElementById('legs-edit-fuel-ldg').value = oneDecimal(leg.fuel_remaining);
      const oilUnit = document.getElementById('legs-edit-oil-unit').value || '%';
      const oilVal = oilUnit === '%' ? (leg.oil_percentage ?? '') : (leg.oil_quantity ?? '');
      document.getElementById('legs-edit-oil-value').value = oilUnit === '%' ? (oilVal === '' ? '' : String(Math.round(Number(oilVal)))) : oneDecimal(oilVal);
      document.getElementById('legs-edit-to').value = leg.takeoff_count ?? 0;
      document.getElementById('legs-edit-ldg').value = leg.landing_count ?? 0;
      document.getElementById('legs-csv-flight-uuid').value = leg.workflow_flight_record_uuid || '';
      document.getElementById('legs-csv-flight-label').textContent = leg.workflow_flight_record_uuid || '—';
      document.getElementById('legs-csv-status').textContent = leg.has_garmin_csv ? 'Garmin Uploaded' : 'Garmin Missing';
      renderCrew(leg.crew || []);
      fillFinancial(leg);
      renderAnnotatedSegments(leg);
      const splitBtn = document.getElementById('legs-split-btn');
      if (splitBtn) {
        const alreadyMulti = !!(leg.device_multi_leg_grouped)
          || (Array.isArray(leg.leg_segments) && leg.leg_segments.length >= 2);
        splitBtn.hidden = alreadyMulti;
        splitBtn.disabled = alreadyMulti;
      }
      updateDerived();
      baselineSnapshot = serializeSnapshot();
      loadGenericBriefing(leg);
      if (trackChart) {
        trackChart.load(leg);
      }
    };

    const validate = () => {
      clearErrors();
      let ok = true;
      if (!document.getElementById('legs-edit-mission').value) {
        showError('mission', 'Select a mission.');
        ok = false;
      }
      const dep = document.getElementById('legs-edit-dep').value.trim().toUpperCase();
      const arr = document.getElementById('legs-edit-arr').value.trim().toUpperCase();
      document.getElementById('legs-edit-dep').value = dep;
      document.getElementById('legs-edit-arr').value = arr;
      if (!/^[A-Z0-9]{3,4}$/.test(dep)) { showError('dep', 'Enter a valid departure airport (e.g. KTRM or L08).'); ok = false; }
      if (!/^[A-Z0-9]{3,4}$/.test(arr)) { showError('arr', 'Enter a valid arrival airport (e.g. KTRM or L08).'); ok = false; }
      if (!document.getElementById('legs-edit-date').value) { showError('date', 'Enter the flight date.'); ok = false; }
      const time = document.getElementById('legs-edit-off-time').value.trim();
      if (!/^([01]\d|2[0-3]):[0-5]\d$/.test(time)) {
        showError('off', 'Enter Off Block time using the 24-hour clock.');
        ok = false;
      }
      const hs = Number(document.getElementById('legs-edit-hobbs-start').value);
      const he = Number(document.getElementById('legs-edit-hobbs-end').value);
      const ts = Number(document.getElementById('legs-edit-tacho-start').value);
      const te = Number(document.getElementById('legs-edit-tacho-end').value);
      if (!(he >= hs)) { showError('hobbs', 'Hobbs End cannot be lower than Hobbs Start.'); ok = false; }
      if (!(te >= ts)) { showError('tacho', 'Tacho End cannot be lower than Tacho Start.'); ok = false; }
      const fd = Number(document.getElementById('legs-edit-fuel-dep').value);
      const fl = Number(document.getElementById('legs-edit-fuel-ldg').value);
      if (Number.isFinite(fd) && Number.isFinite(fl) && fl > fd) {
        showError('fuel', 'Landing fuel cannot exceed departure fuel.');
        ok = false;
      }
      syncCrewHiddenNames();
      const crewRows = Array.from(crewBox.querySelectorAll('.leg-edit-crew-row'));
      if (crewRows.length === 0) { showError('crew', 'Select a crew member.'); ok = false; }
      crewRows.forEach((row) => {
        const userSelect = row.querySelector('[data-crew-user]');
        if (!userSelect?.value) { showError('crew', 'Select a crew member.'); ok = false; }
      });
      updateDerived();
      return ok;
    };

    const requestClose = () => {
      if (isDirty() && !window.confirm('Discard unsaved changes?\n\nContinue Editing: Cancel this dialog.\nDiscard Changes: Close without saving.')) {
        return;
      }
      // Native confirm: OK = discard, Cancel = continue editing
      // Re-prompt with clearer UX:
    };

    const attemptClose = () => {
      if (!isDirty()) {
        closeModal(editModal);
        return;
      }
      const discard = window.confirm('Discard unsaved changes?');
      if (discard) {
        closeModal(editModal);
      }
    };

    table.addEventListener('click', (event) => {
      const stop = event.target instanceof Element ? event.target.closest('[data-legs-stop]') : null;
      if (stop) {
        if (stop.matches('[data-legs-debrief]')) {
          event.preventDefault();
          event.stopPropagation();
          openDebrief(stop.getAttribute('data-debrief-id') || '0', stop.getAttribute('data-bundle-id') || '0');
        }
        return;
      }
      const row = event.target instanceof Element ? event.target.closest('tr[data-leg]') : null;
      if (!row) return;
      try {
        fillEditForm(JSON.parse(row.getAttribute('data-leg') || '{}'));
        openModal(editModal);
      } catch (error) {
        window.alert('Could not open leg editor.');
      }
    });

    editModal.querySelectorAll('[data-legs-edit-close]').forEach((btn) => {
      btn.addEventListener('click', attemptClose);
    });
    debriefModal.querySelectorAll('[data-legs-debrief-close]').forEach((btn) => {
      btn.addEventListener('click', () => closeModal(debriefModal));
    });

    document.getElementById('legs-edit-aircraft')?.addEventListener('change', () => {
      applyAircraftUnits();
      document.getElementById('legs-fin-aircraft-reg').value = String(document.getElementById('legs-edit-aircraft')?.value || '').toUpperCase();
      updateDerived();
    });
    ['legs-edit-hobbs-start','legs-edit-hobbs-end','legs-edit-tacho-start','legs-edit-tacho-end','legs-edit-fuel-dep','legs-edit-fuel-ldg','legs-edit-date','legs-edit-off-time']
      .forEach((id) => document.getElementById(id)?.addEventListener('input', updateDerived));
    document.getElementById('legs-edit-off-time')?.addEventListener('blur', (e) => {
      normalizeTimeInput(e.target);
      updateDerived();
    });
    document.getElementById('legs-edit-dep')?.addEventListener('input', (e) => {
      e.target.value = String(e.target.value || '').toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 4);
    });
    document.getElementById('legs-edit-arr')?.addEventListener('input', (e) => {
      e.target.value = String(e.target.value || '').toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 4);
    });

    document.getElementById('legs-fin-flight-hours')?.addEventListener('input', () => {
      financialFlightHoursTouched = true;
      refreshFinancialOverview();
    });
    ['legs-fin-customer','legs-fin-instructor','legs-fin-rate','legs-fin-preflight','legs-fin-ground-hours']
      .forEach((id) => document.getElementById(id)?.addEventListener('input', refreshFinancialOverview));
    ['legs-fin-customer','legs-fin-instructor','legs-fin-rate']
      .forEach((id) => document.getElementById(id)?.addEventListener('change', refreshFinancialOverview));

    document.getElementById('legs-edit-save-lock-btn')?.addEventListener('click', () => {
      if (financialLocked) return;
      if (!isFinancialComplete()) {
        window.alert('Complete Customer before locking. Instructor and rate are required when instruction hours are greater than zero.');
        return;
      }
      if (!window.confirm('Save and LOCK this Operational Leg?\n\nAfter lock, identity, route, meters, fuel, crew, and financials cannot be edited until an admin (or instructor with the unlock code) unlocks the record.')) {
        return;
      }
      document.getElementById('legs-fin-lock-flag').value = '1';
      if (validate()) {
        form.requestSubmit();
      } else {
        document.getElementById('legs-fin-lock-flag').value = '';
      }
    });

    document.getElementById('legs-fin-unlock-btn')?.addEventListener('click', () => {
      const dispatchId = document.getElementById('legs-edit-dispatch-id')?.value || '';
      if (!dispatchId) return;
      const unlockForm = document.createElement('form');
      unlockForm.method = 'post';
      unlockForm.style.display = 'none';
      const fields = {
        action: 'unlock_financial_dispatch',
        csrf_token: form.querySelector('input[name="csrf_token"]')?.value || '',
        dispatch_id: dispatchId,
        unlock_code: document.getElementById('legs-fin-unlock-code')?.value || '',
        unlock_reason: document.getElementById('legs-fin-unlock-reason')?.value || '',
      };
      Object.entries(fields).forEach(([name, value]) => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        input.value = String(value);
        unlockForm.appendChild(input);
      });
      document.body.appendChild(unlockForm);
      unlockForm.submit();
    });

    const splitModal = document.getElementById('legs-split-modal');
    const splitStatus = document.getElementById('legs-split-status');
    const splitNotes = document.getElementById('legs-split-notes');
    const splitSummary = document.getElementById('legs-split-summary');
    const splitTableWrap = document.getElementById('legs-split-table-wrap');
    const splitTbody = document.getElementById('legs-split-tbody');
    const splitApply = document.getElementById('legs-split-apply');
    let splitPreview = null;

    const setSplitStatus = (message, tone) => {
      if (!splitStatus) return;
      splitStatus.textContent = message || '';
      splitStatus.classList.remove('is-ok', 'is-muted');
      if (tone === 'ok') splitStatus.classList.add('is-ok');
      if (tone === 'muted') splitStatus.classList.add('is-muted');
    };

    const closeSplitModal = () => {
      if (splitModal) splitModal.hidden = true;
      splitPreview = null;
      if (splitApply) splitApply.disabled = true;
    };

    const oneDec = (value) => {
      const n = Number(value);
      return Number.isFinite(n) ? (Math.round(n * 10) / 10).toFixed(1) : '';
    };

    const refreshSplitDerived = () => {
      if (!splitTbody) return;
      const rows = Array.from(splitTbody.querySelectorAll('tr'));
      rows.forEach((tr) => {
        const hs = Number(tr.querySelector('[data-split-field="starting_hobbs"]')?.value);
        const he = Number(tr.querySelector('[data-split-field="ending_hobbs"]')?.value);
        const ts = Number(tr.querySelector('[data-split-field="starting_tacho"]')?.value);
        const te = Number(tr.querySelector('[data-split-field="ending_tacho"]')?.value);
        const fs = Number(tr.querySelector('[data-split-field="fuel_onboard"]')?.value);
        const fe = Number(tr.querySelector('[data-split-field="fuel_remaining"]')?.value);
        const hobbsEl = tr.querySelector('[data-split-delta="hobbs"]');
        const tachoEl = tr.querySelector('[data-split-delta="tacho"]');
        const burnEl = tr.querySelector('[data-split-delta="burn"]');
        if (hobbsEl) hobbsEl.textContent = Number.isFinite(hs) && Number.isFinite(he) ? ('+' + oneDec(he - hs)) : '—';
        if (tachoEl) tachoEl.textContent = Number.isFinite(ts) && Number.isFinite(te) ? ('+' + oneDec(te - ts)) : '—';
        if (burnEl) burnEl.textContent = Number.isFinite(fs) && Number.isFinite(fe) ? oneDec(Math.max(0, fs - fe)) : '—';
      });
    };

    const renderSplitPreview = (preview) => {
      splitPreview = preview;
      if (splitNotes) {
        splitNotes.innerHTML = '';
        (preview.notes || []).forEach((note) => {
          const li = document.createElement('li');
          li.textContent = String(note);
          splitNotes.appendChild(li);
        });
        splitNotes.hidden = !(preview.notes || []).length;
      }
      if (splitSummary) {
        const hobbsTotal = oneDec((Number(preview.ending_hobbs) || 0) - (Number(preview.starting_hobbs) || 0));
        const tachoTotal = oneDec((Number(preview.ending_tacho) || 0) - (Number(preview.starting_tacho) || 0));
        const chips = [
          'Total ΔH ' + hobbsTotal,
          'Total ΔT ' + tachoTotal,
        ];
        if (preview.fuel_burn_total != null) {
          chips.push('Total burn ' + oneDec(preview.fuel_burn_total) + ' USG');
        }
        if (preview.verified_takeoff_count || preview.verified_landing_count) {
          chips.push('Check-In TO/LDG ' + (preview.verified_takeoff_count || 0) + '/' + (preview.verified_landing_count || 0));
        }
        splitSummary.innerHTML = chips.map((c) => '<span class="leg-split-chip">' + c + '</span>').join('');
        splitSummary.hidden = false;
      }
      if (splitTbody) {
        splitTbody.innerHTML = '';
        (preview.proposed_legs || []).forEach((leg, index) => {
          const tr = document.createElement('tr');
          const esc = (v) => String(v ?? '').replace(/"/g, '&quot;');
          tr.innerHTML = `
            <td>${index + 1}</td>
            <td><input data-split-field="departure_airport" value="${esc(leg.departure_airport)}" maxlength="4" style="text-transform:uppercase"></td>
            <td><input data-split-field="arrival_airport" value="${esc(leg.arrival_airport)}" maxlength="4" style="text-transform:uppercase"></td>
            <td><input data-split-field="off_block_utc" value="${esc(leg.off_block_utc)}"></td>
            <td>
              <div style="display:grid;gap:4px">
                <input data-split-field="starting_hobbs" type="number" step="0.1" value="${esc(leg.starting_hobbs)}" title="Hobbs start">
                <input data-split-field="ending_hobbs" type="number" step="0.1" value="${esc(leg.ending_hobbs)}" title="Hobbs end">
              </div>
            </td>
            <td class="leg-split-delta" data-split-delta="hobbs">+${esc(leg.hobbs_delta)}</td>
            <td>
              <div style="display:grid;gap:4px">
                <input data-split-field="starting_tacho" type="number" step="0.1" value="${esc(leg.starting_tacho)}" title="Tacho start">
                <input data-split-field="ending_tacho" type="number" step="0.1" value="${esc(leg.ending_tacho)}" title="Tacho end">
              </div>
            </td>
            <td class="leg-split-delta" data-split-delta="tacho">+${esc(leg.tacho_delta)}</td>
            <td><input data-split-field="takeoff_count" type="number" min="0" step="1" value="${esc(leg.takeoff_count)}" style="width:3.2rem"></td>
            <td><input data-split-field="landing_count" type="number" min="0" step="1" value="${esc(leg.landing_count)}" style="width:3.2rem"></td>
            <td><input data-split-field="fuel_onboard" type="number" step="0.1" value="${esc(leg.fuel_onboard)}"></td>
            <td><input data-split-field="fuel_remaining" type="number" step="0.1" value="${esc(leg.fuel_remaining)}"></td>
            <td class="leg-split-delta" data-split-delta="burn">${esc(leg.fuel_burn)}</td>
          `;
          splitTbody.appendChild(tr);
        });
        splitTbody.querySelectorAll('input').forEach((input) => {
          input.addEventListener('input', refreshSplitDerived);
        });
        refreshSplitDerived();
      }
      if (splitTableWrap) splitTableWrap.hidden = false;
      if (splitApply) splitApply.disabled = !(preview.proposed_legs || []).length;
      setSplitStatus('Review deltas, TO/LDG, and fuel burn, then confirm. Final meters stay locked to Check-In.', 'ok');
    };

    const collectSplitLegs = () => {
      if (!splitTbody) return [];
      return Array.from(splitTbody.querySelectorAll('tr')).map((tr) => {
        const read = (name) => tr.querySelector(`[data-split-field="${name}"]`)?.value || '';
        return {
          departure_airport: String(read('departure_airport')).trim().toUpperCase(),
          arrival_airport: String(read('arrival_airport')).trim().toUpperCase(),
          off_block_utc: String(read('off_block_utc')).trim(),
          starting_hobbs: read('starting_hobbs'),
          ending_hobbs: read('ending_hobbs'),
          starting_tacho: read('starting_tacho'),
          ending_tacho: read('ending_tacho'),
          takeoff_count: read('takeoff_count') || '1',
          landing_count: read('landing_count') || '1',
          fuel_onboard: read('fuel_onboard'),
          fuel_remaining: read('fuel_remaining'),
        };
      });
    };

    document.getElementById('legs-split-btn')?.addEventListener('click', async () => {
      const dispatchId = document.getElementById('legs-edit-dispatch-id')?.value || '';
      if (!dispatchId || !currentLeg) return;
      if (currentLeg.financial?.is_locked) {
        window.alert('Unlock this Operational Leg before splitting it.');
        return;
      }
      if (!splitModal) return;
      splitModal.hidden = false;
      if (splitTableWrap) splitTableWrap.hidden = true;
      if (splitNotes) splitNotes.hidden = true;
      if (splitSummary) splitSummary.hidden = true;
      if (splitApply) splitApply.disabled = true;
      setSplitStatus('Loading suggestions from schedule, CSV, and GPS…', 'muted');
      try {
        const response = await fetch('/admin/api/operational_leg_split_preview.php?dispatch_id=' + encodeURIComponent(dispatchId), {
          credentials: 'same-origin',
        });
        const payload = await response.json();
        if (!payload.ok || !payload.preview) {
          throw new Error(payload.message || 'Could not build split suggestions.');
        }
        renderSplitPreview(payload.preview);
      } catch (error) {
        setSplitStatus(error instanceof Error ? error.message : 'Could not build split suggestions.', 'error');
        if (splitApply) splitApply.disabled = true;
      }
    });

    document.getElementById('legs-split-close')?.addEventListener('click', closeSplitModal);
    document.getElementById('legs-split-cancel')?.addEventListener('click', closeSplitModal);

    splitApply?.addEventListener('click', () => {
      const dispatchId = document.getElementById('legs-edit-dispatch-id')?.value || '';
      if (!dispatchId) return;
      const legs = collectSplitLegs();
      if (legs.length < 2) {
        window.alert('At least two legs are required.');
        return;
      }
      if (!window.confirm(
        'Annotate this Check-In with ' + legs.length + ' legs?\n\n'
        + 'One Operational Leg is kept. Replay, CSV recovery, debrief, and financials stay on this Flight Record.'
      )) {
        return;
      }
      const applyForm = document.createElement('form');
      applyForm.method = 'post';
      applyForm.style.display = 'none';
      const csrf = form.querySelector('input[name="csrf_token"]')?.value || '';
      const append = (name, value) => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        input.value = String(value);
        applyForm.appendChild(input);
      };
      append('action', 'split_operational_leg');
      append('csrf_token', csrf);
      append('dispatch_id', dispatchId);
      legs.forEach((leg, index) => {
        Object.entries(leg).forEach(([key, value]) => {
          append(`split[legs][${index}][${key}]`, value);
        });
      });
      document.body.appendChild(applyForm);
      applyForm.submit();
    });

    document.getElementById('legs-edit-add-crew')?.addEventListener('click', () => {
      const current = Array.from(crewBox.querySelectorAll('.leg-edit-crew-row')).map((row) => ({
        role: row.querySelector('select[name*="[role]"]')?.value || 'student',
        person_id: row.querySelector('[data-crew-user]')?.value || '',
        name: row.querySelector('input[type="hidden"]')?.value || '',
      }));
      current.push({ role: 'student', name: '', person_id: '' });
      renderCrew(current);
    });
    crewBox.addEventListener('change', (event) => {
      if (event.target && event.target.matches('[data-crew-user]')) {
        syncCrewHiddenNames();
      }
    });
    crewBox.addEventListener('click', (event) => {
      const btn = event.target instanceof Element ? event.target.closest('[data-legs-crew-remove]') : null;
      if (!btn) return;
      btn.closest('.leg-edit-crew-row')?.remove();
      const current = Array.from(crewBox.querySelectorAll('.leg-edit-crew-row')).map((row) => ({
        role: row.querySelector('select[name*="[role]"]')?.value || 'student',
        person_id: row.querySelector('[data-crew-user]')?.value || '',
        name: row.querySelector('input[type="hidden"]')?.value || '',
      }));
      renderCrew(current.length ? current : [{ role: 'student', name: '', person_id: '' }]);
    });

    form.addEventListener('submit', (event) => {
      const lockingNow = document.getElementById('legs-fin-lock-flag')?.value === '1';
      if (financialLocked && !lockingNow) {
        event.preventDefault();
        window.alert('Unlock this Operational Leg before editing.');
        return;
      }
      // Preserve the Flight Instruction field as entered (including 0.0) — do not re-pull Hobbs.
      financialFlightHoursTouched = true;
      syncSegmentOperationTotals();
      syncSegmentMeterFuelTotals();
      // Normalize meter decimals before submit
      ['legs-edit-hobbs-start','legs-edit-hobbs-end','legs-edit-tacho-start','legs-edit-tacho-end','legs-edit-fuel-dep','legs-edit-fuel-ldg']
        .forEach((id) => {
          const el = document.getElementById(id);
          if (el && el.value !== '') el.value = oneDecimal(el.value);
        });
      form.querySelectorAll('[data-segment-meter], [data-segment-fuel]').forEach((el) => {
        if (el.value !== '') el.value = oneDecimal(el.value);
      });
      ['legs-fin-preflight','legs-fin-flight-hours','legs-fin-ground-hours'].forEach((id) => {
        const el = document.getElementById(id);
        if (el && el.value !== '') el.value = oneDecimal(el.value);
      });
      syncCrewHiddenNames();
      updateDerived();
      refreshFinancialOverview();
      // Map oil value into percentage or quantity via oil_unit already set
      const oilUnit = document.getElementById('legs-edit-oil-unit').value || '%';
      const oilVal = document.getElementById('legs-edit-oil-value').value;
      // hidden fields injected dynamically
      let pct = form.querySelector('input[name="leg[oil_percentage]"]');
      let qty = form.querySelector('input[name="leg[oil_quantity]"]');
      let oval = form.querySelector('input[name="leg[oil_value]"]');
      if (!pct) {
        pct = document.createElement('input');
        pct.type = 'hidden';
        pct.name = 'leg[oil_percentage]';
        form.appendChild(pct);
      }
      if (!qty) {
        qty = document.createElement('input');
        qty.type = 'hidden';
        qty.name = 'leg[oil_quantity]';
        form.appendChild(qty);
      }
      if (!oval) {
        oval = document.createElement('input');
        oval.type = 'hidden';
        oval.name = 'leg[oil_value]';
        form.appendChild(oval);
      }
      oval.value = oilVal;
      if (oilUnit === '%') {
        pct.value = oilVal;
        qty.value = '';
      } else {
        qty.value = oilVal;
        pct.value = '';
      }
      // Convert historical crew person_id
      crewBox.querySelectorAll('[data-crew-user]').forEach((sel) => {
        if (sel.value === 'historical') sel.value = '';
      });
      if (!validate()) {
        event.preventDefault();
      }
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
        box.textContent = payload.copy_text || payload.message || 'No debrief text available.';
        message.textContent = payload.ok ? '' : (payload.message || '');
        if (!payload.ok && Number(bundleId) > 0) generateForm.hidden = false;
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

    briefingCopyBtn?.addEventListener('click', async () => {
      const text = briefingBox?.value || '';
      if (!text) return;
      try {
        await navigator.clipboard.writeText(text);
        setBriefingStatus('Copied to clipboard.', 'ok');
        const original = briefingCopyBtn.textContent;
        briefingCopyBtn.textContent = 'Copied';
        window.setTimeout(() => { briefingCopyBtn.textContent = original || 'Copy Briefing'; }, 1800);
      } catch (error) {
        briefingBox.focus();
        briefingBox.select();
        document.execCommand('copy');
        setBriefingStatus('Copied to clipboard.', 'ok');
      }
    });
  })();
})();
</script>
<?php cw_footer(); ?>
