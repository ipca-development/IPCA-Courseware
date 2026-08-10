<?php
declare(strict_types=1);

/**
 * Phase 3 ETL: E-gle (READ ONLY) -> local analytics SQLite.
 * Never issues INSERT/UPDATE/DELETE/DDL against the source database.
 */

$root = dirname(__DIR__, 2);
require_once $root . '/analytics/lib/egle_readonly.php';
require_once $root . '/analytics/lib/grade_maps.php';
require_once $root . '/analytics/lib/mission_classify.php';

$analyticsDbPath = $root . '/storage/analytics/egle_training_analytics.sqlite';
$schemaPath = $root . '/analytics/schema/analytics_schema.sql';
@mkdir(dirname($analyticsDbPath), 0755, true);
// Fresh analytics DB each full extract (source remains untouched).
if (is_file($analyticsDbPath)) {
    unlink($analyticsDbPath);
}
foreach (glob($analyticsDbPath . '*') ?: [] as $sidecar) {
    if (is_file($sidecar)) {
        @unlink($sidecar);
    }
}

$host = getenv('EGLE_DB_HOST') ?: '';
$port = (int)(getenv('EGLE_DB_PORT') ?: 3306);
$dbName = getenv('EGLE_DB_NAME') ?: '';
$user = getenv('EGLE_DB_USER') ?: '';
$pass = getenv('EGLE_DB_PASS') ?: '';
if ($host === '' || $dbName === '' || $user === '' || $pass === '') {
    fwrite(STDERR, "Missing EGLE_DB_* env vars (read-only credentials required).\n");
    exit(1);
}

$src = egle_readonly_connect($host, $port, $dbName, $user, $pass);
$ana = new PDO('sqlite:' . $analyticsDbPath);
$ana->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$ana->exec('PRAGMA foreign_keys = ON');
$ana->exec('PRAGMA journal_mode = WAL');
$schemaSql = file_get_contents($schemaPath);
if ($schemaSql === false) {
    throw new RuntimeException('Unable to read analytics schema SQL');
}
foreach (array_filter(array_map('trim', explode(';', $schemaSql))) as $stmtSql) {
    if ($stmtSql === '' || str_starts_with($stmtSql, '--')) {
        continue;
    }
    $ana->exec($stmtSql);
}

$started = gmdate('c');
$ana->prepare('INSERT INTO etl_run (started_at, status, source_system, source_database, notes) VALUES (?,?,?,?,?)')
    ->execute([$started, 'RUNNING', 'egle', $dbName, 'Phase 3 extraction']);
$etlRunId = (int)$ana->lastInsertId();

$stats = [
    'sessions_source' => 0,
    'sessions_mapped' => 0,
    'exercise_attempts' => 0,
    'exercise_parse_fail' => 0,
    'srm_attempts' => 0,
    'narratives' => 0,
    'logbook_legs' => 0,
    'qa_issues' => 0,
];

function qa_issue(PDO $ana, int $run, string $sev, string $code, ?string $etable, ?int $eid, ?string $stable, ?string $spk, string $detail): void
{
    $ana->prepare('INSERT INTO qa_data_issue (etl_run_id, severity, issue_code, entity_table, entity_id, source_table, source_pk, detail, created_at) VALUES (?,?,?,?,?,?,?,?,?)')
        ->execute([$run, $sev, $code, $etable, $eid, $stable, $spk, $detail, gmdate('c')]);
}

function map_source(PDO $ana, int $run, string $ctable, int $cid, string $sdb, string $stable, string $spk, ?string $prog = null): void
{
    $ana->prepare('INSERT OR IGNORE INTO source_record_map (etl_run_id, canonical_table, canonical_id, source_system, source_database, source_table, source_pk, source_program_code) VALUES (?,?,?,?,?,?,?,?)')
        ->execute([$run, $ctable, $cid, 'egle', $sdb, $stable, $spk, $prog]);
}

echo "ETL run {$etlRunId} starting...\n";

// --- Curriculum families / versions (verified from programs.pr_db pairs) ---
$families = [
    ['PPL', 'Private Pilot Licence family'],
    ['IR', 'Instrument Rating family'],
    ['MEP', 'Multi-Engine Piston family'],
    ['CPL', 'Commercial Pilot family'],
    ['FI', 'Flight Instructor family'],
    ['IRI', 'Instrument Rating Instructor family'],
    ['UPRT', 'UPRT family'],
    ['MCC', 'MCC / APS MCC family'],
    ['ACP', 'Airline Career Program family'],
    ['NQ', 'Night Rating family'],
    ['OTHER', 'Other / singleton programs'],
];
foreach ($families as [$code, $name]) {
    $ana->prepare('INSERT OR IGNORE INTO dim_curriculum_family (family_code, family_name) VALUES (?,?)')->execute([$code, $name]);
}
$familyIds = [];
foreach ($ana->query('SELECT curriculum_family_id, family_code FROM dim_curriculum_family') as $r) {
    $familyIds[$r['family_code']] = (int)$r['curriculum_family_id'];
}

$versions = [
    // family, version_code, name, generation_order, is_current
    ['PPL', 'PPL_OLD', 'Private Pilot - PPL(A) Old', 1, 0],
    ['PPL', 'PPLA', 'Private Pilot - PPL(A)', 2, 1],
    ['IR', 'IR_LEGACY', 'Instrument Rating - IR(A)', 1, 1], // still active flag YES in source
    ['IR', 'IRNEW_SE', 'EASA SE Instrument Rating - IR(A)', 2, 1],
    ['IR', 'IRNEW_ME', 'EASA ME Instrument Rating - IR(A)', 2, 1],
    ['MEP', 'MEP_OLD', 'Multi-Engine Piston - MEP(A) Old', 1, 0],
    ['MEP', 'MEPNEW', 'Multi-Engine Piston - MEP(A)', 2, 1],
    ['CPL', 'CPLA_OLD', 'Commercial Pilot - CPL(A) Old', 1, 0],
    ['CPL', 'CPLAUPRT', 'Commercial Pilot - CPL(A)', 2, 1],
    ['FI', 'FIA', 'Flight Instructor - FI(A)', 1, 1],
    ['IRI', 'IRI', 'Instrument Instructor - IRI(A)', 1, 1],
    ['UPRT', 'AUPRT', 'Advanced UPRT', 1, 1],
    ['MCC', 'APSMCC', 'APS MCC', 1, 1],
    ['MCC', 'MCCI', 'MCC Instructor - MCCI', 1, 1],
    ['ACP', 'EASAACP', 'EASA Airline Career Program', 1, 1],
    ['ACP', 'FAAACP', 'FAA Airline Career Program', 1, 1],
    ['ACP', 'SCREENING', 'ACP Screening', 1, 1],
    ['ACP', 'AIRLINE', 'Airline Assessments', 1, 1],
    ['NQ', 'NQ', 'Night Rating - NQ', 1, 1],
    ['OTHER', 'EXP', 'Experience Building USA', 1, 0],
    ['OTHER', 'CFI', 'FAA CFI', 1, 1],
    ['OTHER', 'STANDARDIZATION', 'EPC Standardization', 1, 1],
];
foreach ($versions as [$fam, $vcode, $vname, $gen, $cur]) {
    $ana->prepare('INSERT OR IGNORE INTO dim_curriculum_version (curriculum_family_id, version_code, version_name, generation_order, is_current) VALUES (?,?,?,?,?)')
        ->execute([$familyIds[$fam], $vcode, $vname, $gen, $cur]);
}
$versionIds = [];
foreach ($ana->query('SELECT curriculum_version_id, version_code FROM dim_curriculum_version') as $r) {
    $versionIds[$r['version_code']] = (int)$r['curriculum_version_id'];
}

$programVersionMap = [
    'scenario_tracking_PPL' => ['PPL', 'PPL_OLD'],
    'scenario_tracking_PPLA' => ['PPL', 'PPLA'],
    'scenario_tracking_IR' => ['IR', 'IR_LEGACY'],
    'scenario_tracking_IRNEW' => ['IR', 'IRNEW_SE'],
    'scenario_tracking_IRNEWME' => ['IR', 'IRNEW_ME'],
    'scenario_tracking_MEP' => ['MEP', 'MEP_OLD'],
    'scenario_tracking_MEPNEW' => ['MEP', 'MEPNEW'],
    'scenario_tracking_CPLA' => ['CPL', 'CPLA_OLD'],
    'scenario_tracking_CPLAUPRT' => ['CPL', 'CPLAUPRT'],
    'scenario_tracking_FIA' => ['FI', 'FIA'],
    'scenario_tracking_IRI' => ['IRI', 'IRI'],
    'scenario_tracking_AUPRT' => ['UPRT', 'AUPRT'],
    'scenario_tracking_APSMCC' => ['MCC', 'APSMCC'],
    'scenario_tracking_MCCI' => ['MCC', 'MCCI'],
    'scenario_tracking_EASAACP' => ['ACP', 'EASAACP'],
    'scenario_tracking_FAAACP' => ['ACP', 'FAAACP'],
    'scenario_tracking_SCREENING' => ['ACP', 'SCREENING'],
    'scenario_tracking_AIRLINE' => ['ACP', 'AIRLINE'],
    'scenario_tracking_NQ' => ['NQ', 'NQ'],
    'scenario_tracking_EXP' => ['OTHER', 'EXP'],
    'scenario_tracking_CFI' => ['OTHER', 'CFI'],
];

// --- SRM dictionary seed from UI ---
$srmSeed = [
    ['SM', 'Safety Management (SM)', 'HIGH', 'training_record_instructor.php / training_record_print.php UI label'],
    ['TM', 'Task Management (TM)', 'HIGH', 'training_record_instructor.php / training_record_print.php UI label'],
    ['AM', 'Automation Management (AM)', 'HIGH', 'training_record_instructor.php / training_record_print.php UI label'],
    ['RM', 'Risk Management (RM)', 'HIGH', 'training_record_instructor.php / training_record_print.php UI label'],
    ['ADM', 'Aeronautical Decision Making (ADM)', 'HIGH', 'training_record_instructor.php / training_record_print.php UI label'],
    ['SA', 'Situational Awareness (SA)', 'HIGH', 'training_record_instructor.php / training_record_print.php UI label'],
    ['CFIT', 'Controlled Flight Into Terrain (CFIT)', 'HIGH', 'training_record_instructor.php / training_record_print.php UI label'],
];
foreach ($srmSeed as [$k, $label, $conf, $ev]) {
    $ana->prepare('INSERT OR IGNORE INTO dim_srm_key (raw_key, probable_ui_label, confidence, evidence) VALUES (?,?,?,?)')
        ->execute([$k, $label, $conf, $ev]);
}

// --- Dimensions ---
echo "Loading programs...\n";
$programs = egle_select($src, 'SELECT pr_id, pr_name, pr_db, pr_active, pr_location FROM programs ORDER BY pr_id');
$programIdBySource = [];
foreach ($programs as $p) {
    $tracking = trim((string)$p['pr_db']);
    $famCode = $programVersionMap[$tracking][0] ?? 'OTHER';
    $verCode = $programVersionMap[$tracking][1] ?? 'STANDARDIZATION';
    if ($tracking === '' && (int)$p['pr_id'] === 21) {
        $famCode = 'OTHER';
        $verCode = 'STANDARDIZATION';
    }
    $authority = null;
    $loc = (string)$p['pr_location'];
    $name = (string)$p['pr_name'];
    if (stripos($name, 'FAA') !== false || $loc === 'US') {
        $authority = 'FAA';
    } elseif (stripos($name, 'EASA') !== false || $loc === 'BE') {
        $authority = 'EASA';
    } elseif ($loc === 'ALL') {
        $authority = 'MIXED';
    }
    $ana->prepare('INSERT INTO dim_program (source_program_id, program_name, source_tracking_table, source_active_raw, location_raw, curriculum_family_id, curriculum_version_id, authority_guess, etl_run_id)
        VALUES (?,?,?,?,?,?,?,?,?)')
        ->execute([
            (int)$p['pr_id'], $name, $tracking !== '' ? $tracking : null, $p['pr_active'], $loc,
            $familyIds[$famCode] ?? $familyIds['OTHER'],
            $versionIds[$verCode] ?? null,
            $authority, $etlRunId,
        ]);
    $pid = (int)$ana->lastInsertId();
    $programIdBySource[(int)$p['pr_id']] = $pid;
    map_source($ana, $etlRunId, 'dim_program', $pid, $dbName, 'programs', (string)$p['pr_id']);
}
$programByTracking = [];
foreach ($ana->query('SELECT program_id, source_program_id, source_tracking_table, curriculum_family_id, curriculum_version_id FROM dim_program') as $r) {
    if ($r['source_tracking_table']) {
        $programByTracking[$r['source_tracking_table']] = $r;
    }
}

echo "Loading users...\n";
$users = egle_select($src, 'SELECT userid, naam, voornaam, email, dob, tel, nationaliteit, actief_tot, type FROM users');
$studentIdBySource = [];
$instructorIdBySource = [];
foreach ($users as $u) {
    $type = strtoupper(trim((string)$u['type']));
    if ($type === 'STUDENT' || $type === 'SELECT' || $type === '') {
        $ana->prepare('INSERT INTO dim_student (source_user_id, canonical_student_id, first_name, last_name, email, dob, phone, nationality, active_until, source_type, etl_run_id)
            VALUES (?,?,NULL,?,?,?,?,?,?,?,?)')
            ->execute([
                (int)$u['userid'], $u['voornaam'], $u['naam'], $u['email'], $u['dob'], $u['tel'],
                $u['nationaliteit'], $u['actief_tot'], $u['type'], $etlRunId,
            ]);
        $sid = (int)$ana->lastInsertId();
        $ana->prepare('UPDATE dim_student SET canonical_student_id = ? WHERE student_id = ?')->execute([$sid, $sid]);
        $studentIdBySource[(int)$u['userid']] = $sid;
        map_source($ana, $etlRunId, 'dim_student', $sid, $dbName, 'users', (string)$u['userid']);
    }
    if ($type === 'INSTRUCTOR' || $type === 'ADMIN') {
        $ana->prepare('INSERT INTO dim_instructor (source_user_id, first_name, last_name, email, active_until, source_type, etl_run_id)
            VALUES (?,?,?,?,?,?,?)')
            ->execute([(int)$u['userid'], $u['voornaam'], $u['naam'], $u['email'], $u['actief_tot'], $u['type'], $etlRunId]);
        $iid = (int)$ana->lastInsertId();
        $instructorIdBySource[(int)$u['userid']] = $iid;
        map_source($ana, $etlRunId, 'dim_instructor', $iid, $dbName, 'users', (string)$u['userid']);
    }
}
// Ensure any user appearing as student/instructor in sessions can be referenced even if type mismatched
// (filled later if missing)

echo "Loading stages/phases/devices...\n";
foreach (egle_select($src, 'SELECT st_id, st_program, st_name, st_order FROM stages') as $r) {
    $ana->prepare('INSERT INTO dim_stage (source_stage_id, source_program_id, stage_name, stage_order, etl_run_id) VALUES (?,?,?,?,?)')
        ->execute([(int)$r['st_id'], (int)$r['st_program'], $r['st_name'], (int)$r['st_order'], $etlRunId]);
}
foreach (egle_select($src, 'SELECT ph_id, ph_stage, ph_name, ph_order, ph_min_dual, ph_min_pic, ph_min_fnpt, ph_min_brief FROM phases') as $r) {
    $ana->prepare('INSERT INTO dim_phase (source_phase_id, source_stage_id, phase_name, phase_order, min_dual, min_pic, min_fnpt, min_brief, etl_run_id) VALUES (?,?,?,?,?,?,?,?,?)')
        ->execute([(int)$r['ph_id'], (int)$r['ph_stage'], $r['ph_name'], (int)$r['ph_order'], $r['ph_min_dual'], $r['ph_min_pic'], $r['ph_min_fnpt'], $r['ph_min_brief'], $etlRunId]);
}
$deviceIdBySource = [];
foreach (egle_select($src, 'SELECT dev_id, dev_name, dev_type, dev_kind, dev_location, dev_active FROM devices') as $r) {
    $ana->prepare('INSERT INTO dim_device (source_device_id, device_name, device_type_raw, device_kind_raw, device_location_raw, device_active_raw, etl_run_id) VALUES (?,?,?,?,?,?,?)')
        ->execute([(int)$r['dev_id'], $r['dev_name'], $r['dev_type'], $r['dev_kind'], $r['dev_location'], $r['dev_active'], $etlRunId]);
    $deviceIdBySource[(int)$r['dev_id']] = (int)$ana->lastInsertId();
}

echo "Loading missions...\n";
$missionIdBySource = [];
foreach (egle_select($src, 'SELECT sc_id, sc_program, sc_stage, sc_phase, sc_code, sc_name, sc_order, sc_type, sc_ksa, sc_duration_minutes, active, easa_solo FROM scenarios') as $r) {
    $cls = classify_mission($r);
    $ana->prepare('INSERT INTO dim_mission (
        source_scenario_id, source_program_id, source_stage_id, source_phase_id, mission_code, mission_name, mission_order,
        source_session_type, easa_solo_raw, ksa_raw, duration_minutes, active,
        event_class, event_class_confidence, event_class_reason, event_class_evidence, etl_run_id
    ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([
        (int)$r['sc_id'], (int)$r['sc_program'], (int)$r['sc_stage'], (int)$r['sc_phase'],
        $r['sc_code'], $r['sc_name'], (int)$r['sc_order'], $r['sc_type'], $r['easa_solo'], $r['sc_ksa'],
        $r['sc_duration_minutes'], (int)$r['active'],
        $cls['classification'], $cls['confidence'], $cls['classification_reason'], $cls['source_evidence'], $etlRunId,
    ]);
    $missionIdBySource[(int)$r['sc_id']] = (int)$ana->lastInsertId();
}

echo "Loading exercises...\n";
$exerciseMetaBySource = [];
$exStmt = $ana->prepare('INSERT INTO dim_exercise (
    source_exercise_id, source_scenario_id, exercise_name_raw, exercise_name_normalized, exercise_order, exercise_type_raw,
    required_level_raw, required_level_normalized, required_level_parse_status, is_title, etl_run_id
) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
foreach (egle_select($src, 'SELECT ex_id, ex_scenario, ex_name, ex_order, ex_type FROM exercises') as $r) {
    $parsed = parse_required_level((string)$r['ex_name']);
    $isTitle = strtoupper(trim((string)$r['ex_type'])) === 'TITLE' ? 1 : 0;
    $exStmt->execute([
        (int)$r['ex_id'], (int)$r['ex_scenario'], $r['ex_name'], $parsed['name_normalized'], (int)$r['ex_order'], $r['ex_type'],
        $parsed['required_level_raw'], $parsed['required_level_normalized'], $parsed['parse_status'], $isTitle, $etlRunId,
    ]);
    $eid = (int)$ana->lastInsertId();
    $exerciseMetaBySource[(int)$r['ex_id']] = [
        'exercise_id' => $eid,
        'name_raw' => (string)$r['ex_name'],
        'required_level_raw' => $parsed['required_level_raw'],
        'required_level_normalized' => $parsed['required_level_normalized'],
        'is_title' => $isTitle,
    ];
}

// --- Sessions ---
$trackingTables = egle_select($src, "SHOW TABLES LIKE 'scenario_tracking_%'");
$trackingNames = [];
foreach ($trackingTables as $row) {
    $trackingNames[] = array_values($row)[0];
}
sort($trackingNames);

$sessionInsert = $ana->prepare('INSERT INTO fact_training_session (
  etl_run_id, source_system, source_database, source_table, source_sctr_id, source_program_id, program_id,
  curriculum_family_id, curriculum_version_id, student_id, source_student_id, instructor_id, source_instructor_id,
  mission_id, source_scenario_id, device_id, source_device_id, session_date, session_date_valid,
  source_session_type, session_type_normalized, dual_hours, pic_hours, fnpt_hours, brief_hours,
  total_training_hours, flight_hours, sim_hours, ground_hours,
  grading_raw, grading_color, grading_completion, grading_category,
  sctr_next_raw, sctr_alternative_raw, sctr_next_is_none, sctr_alternative_is_none,
  sign_inst_date, sign_stud_date, ex_blob_parse_status, srm_blob_parse_status, ksa_blob_parse_status,
  exercises_graded_count, exercises_below_required, exercises_at_required, exercises_above_required, exercises_deferred,
  required_level_met_session, narrative_public_present, narrative_private_present, qa_class, qa_notes
) VALUES (' . implode(',', array_fill(0, 52, '?')) . ')');

$exAttemptInsert = $ana->prepare('INSERT INTO fact_exercise_attempt (
  etl_run_id, session_id, source_table, source_sctr_id, source_exercise_id, exercise_id, student_id, instructor_id,
  mission_id, program_id, session_date, exercise_name_raw, required_level_raw, required_level_normalized,
  achieved_grade_raw, achieved_competency_stage, required_level_met, required_level_exceeded, required_level_not_met,
  deferred, parse_status, parse_warnings
) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');

$srmInsert = $ana->prepare('INSERT INTO fact_srm_attempt (
  etl_run_id, session_id, source_table, source_sctr_id, srm_key_raw, srm_value_raw, srm_ui_label,
  student_id, instructor_id, mission_id, program_id, session_date, parse_status
) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');

$narrInsert = $ana->prepare('INSERT INTO fact_narrative (
  etl_run_id, session_id, source_table, source_sctr_id, student_id, instructor_id, comment_type, raw_text, text_hash, character_count, word_count
) VALUES (?,?,?,?,?,?,?,?,?,?,?)');

$srmLabels = [];
foreach ($ana->query('SELECT raw_key, probable_ui_label FROM dim_srm_key') as $r) {
    $srmLabels[$r['raw_key']] = $r['probable_ui_label'];
}

$NONE_SENTINEL = 999999999;

foreach ($trackingNames as $table) {
    echo "Extracting {$table}...\n";
    $progRow = $programByTracking[$table] ?? null;
    $cols = egle_select($src, "SHOW COLUMNS FROM `{$table}`");
    $colNames = array_map(static fn($c) => $c['Field'], $cols);
    $hasKsa = in_array('sctr_ksa', $colNames, true);
    $hasSignInst = in_array('sctr_sign_inst_date', $colNames, true);
    $hasSignStud = in_array('sctr_sign_stud_date', $colNames, true);
    $selectCols = [
        'sctr_id', 'sctr_scenario_id', 'sctr_type', 'sctr_student', 'sctr_instructor', 'sctr_date', 'sctr_grading',
        'sctr_public_comment', 'sctr_private_comment', 'sctr_device', 'sctr_dual', 'sctr_pic', 'sctr_fnpt', 'sctr_brief',
        'sctr_next', 'sctr_alternative', 'sctr_ex', 'sctr_srm',
    ];
    if ($hasSignInst) {
        $selectCols[] = 'sctr_sign_inst_date';
    }
    if ($hasSignStud) {
        $selectCols[] = 'sctr_sign_stud_date';
    }
    if ($hasKsa) {
        $selectCols[] = 'sctr_ksa';
    }
    $sql = 'SELECT ' . implode(', ', $selectCols) . " FROM `{$table}`";
    $rows = egle_select($src, $sql);
    $stats['sessions_source'] += count($rows);

    $ana->beginTransaction();
    $n = 0;
    foreach ($rows as $row) {
        $n++;
        $sourceStudent = (int)$row['sctr_student'];
        $sourceInstr = (int)$row['sctr_instructor'];
        $sourceMission = (int)$row['sctr_scenario_id'];
        $sourceDevice = (int)$row['sctr_device'];

        if (!isset($studentIdBySource[$sourceStudent]) && $sourceStudent > 0) {
            // create placeholder student from unknown type
            $u = egle_select($src, 'SELECT userid, naam, voornaam, email, dob, tel, nationaliteit, actief_tot, type FROM users WHERE userid = ?', [$sourceStudent]);
            if ($u) {
                $uu = $u[0];
                $ana->prepare('INSERT OR IGNORE INTO dim_student (source_user_id, canonical_student_id, first_name, last_name, email, dob, phone, nationality, active_until, source_type, etl_run_id)
                    VALUES (?,?,NULL,?,?,?,?,?,?,?,?)')
                    ->execute([(int)$uu['userid'], $uu['voornaam'], $uu['naam'], $uu['email'], $uu['dob'], $uu['tel'], $uu['nationaliteit'], $uu['actief_tot'], $uu['type'], $etlRunId]);
                $sid = (int)$ana->query('SELECT student_id FROM dim_student WHERE source_user_id = ' . (int)$uu['userid'])->fetchColumn();
                $ana->prepare('UPDATE dim_student SET canonical_student_id = COALESCE(canonical_student_id, student_id) WHERE student_id = ?')->execute([$sid]);
                $studentIdBySource[$sourceStudent] = $sid;
            }
        }
        if (!isset($instructorIdBySource[$sourceInstr]) && $sourceInstr > 0) {
            $u = egle_select($src, 'SELECT userid, naam, voornaam, email, actief_tot, type FROM users WHERE userid = ?', [$sourceInstr]);
            if ($u) {
                $uu = $u[0];
                $ana->prepare('INSERT OR IGNORE INTO dim_instructor (source_user_id, first_name, last_name, email, active_until, source_type, etl_run_id) VALUES (?,?,?,?,?,?,?)')
                    ->execute([(int)$uu['userid'], $uu['voornaam'], $uu['naam'], $uu['email'], $uu['actief_tot'], $uu['type'], $etlRunId]);
                $instructorIdBySource[$sourceInstr] = (int)$ana->query('SELECT instructor_id FROM dim_instructor WHERE source_user_id = ' . (int)$uu['userid'])->fetchColumn();
            }
        }

        $studentId = $studentIdBySource[$sourceStudent] ?? null;
        $instructorId = $instructorIdBySource[$sourceInstr] ?? null;
        $missionId = $missionIdBySource[$sourceMission] ?? null;
        $deviceId = $deviceIdBySource[$sourceDevice] ?? null;

        $date = (string)$row['sctr_date'];
        $dateValid = ($date !== '' && $date !== '0000-00-00') ? 1 : 0;
        $typeRaw = (string)$row['sctr_type'];
        $typeNorm = normalize_session_type($typeRaw);
        $g = map_session_grading((string)$row['sctr_grading']);

        $dual = (float)$row['sctr_dual'];
        $pic = (float)$row['sctr_pic'];
        $fnpt = (float)$row['sctr_fnpt'];
        $brief = (float)$row['sctr_brief'];
        $total = $dual + $pic + $fnpt + $brief;
        $flight = $dual + $pic;
        $sim = $fnpt;
        // LB/SAB hours are often recorded in brief; keep raw components and also ground estimate
        $ground = $brief;

        $nextRaw = (int)$row['sctr_next'];
        $altRaw = (int)$row['sctr_alternative'];

        $exParsed = php_unserialize_safe($row['sctr_ex']);
        $srmParsed = php_unserialize_safe($row['sctr_srm']);
        $ksaParsed = $hasKsa ? php_unserialize_safe($row['sctr_ksa'] ?? null) : ['ok' => true, 'data' => null, 'status' => 'NOT_PRESENT'];

        $below = $at = $above = $deferred = $graded = 0;
        $exRows = [];
        if ($exParsed['ok'] && is_array($exParsed['data'])) {
            foreach ($exParsed['data'] as $exSourceId => $gradeRaw) {
                $exSourceId = (int)$exSourceId;
                $gradeRaw = is_scalar($gradeRaw) ? (string)$gradeRaw : '';
                $meta = $exerciseMetaBySource[$exSourceId] ?? null;
                if ($meta && $meta['is_title']) {
                    continue;
                }
                $cmp = compare_required_achieved($meta['required_level_normalized'] ?? null, $gradeRaw);
                if ($cmp['deferred']) {
                    $deferred++;
                } elseif ($cmp['met'] === 1 && $cmp['exceeded'] === 0) {
                    $at++;
                    $graded++;
                } elseif ($cmp['exceeded'] === 1) {
                    $above++;
                    $graded++;
                } elseif ($cmp['not_met'] === 1) {
                    $below++;
                    $graded++;
                } else {
                    $graded++;
                }
                $exRows[] = [
                    'source_exercise_id' => $exSourceId,
                    'exercise_id' => $meta['exercise_id'] ?? null,
                    'name_raw' => $meta['name_raw'] ?? null,
                    'req_raw' => $meta['required_level_raw'] ?? null,
                    'req_norm' => $meta['required_level_normalized'] ?? null,
                    'grade_raw' => $gradeRaw,
                    'stage' => $cmp['achieved_stage'],
                    'met' => $cmp['met'],
                    'exceeded' => $cmp['exceeded'],
                    'not_met' => $cmp['not_met'],
                    'deferred' => $cmp['deferred'] ? 1 : 0,
                    'parse_status' => 'OK',
                    'warnings' => $meta ? null : 'exercise_id_not_in_dim_exercise',
                ];
            }
            $exStatus = 'OK';
        } elseif ($exParsed['status'] === 'EMPTY') {
            $exStatus = 'EMPTY';
        } else {
            $exStatus = 'FAIL';
            $stats['exercise_parse_fail']++;
            qa_issue($ana, $etlRunId, 'ERROR', 'EX_BLOB_PARSE_FAIL', null, null, $table, (string)$row['sctr_id'], $exParsed['error'] ?? 'parse failed');
            $stats['qa_issues']++;
        }

        $qaClass = 'HIGH_CONFIDENCE';
        $qaNotes = [];
        if (!$dateValid) {
            $qaClass = 'EXCLUDE';
            $qaNotes[] = 'invalid_session_date';
        }
        if ($studentId === null) {
            $qaClass = ($qaClass === 'EXCLUDE') ? 'EXCLUDE' : 'AMBIGUOUS';
            $qaNotes[] = 'missing_student';
        }
        if ($missionId === null) {
            $qaClass = ($qaClass === 'EXCLUDE') ? 'EXCLUDE' : 'AMBIGUOUS';
            $qaNotes[] = 'missing_mission';
        }
        if ($exStatus === 'FAIL') {
            $qaClass = ($qaClass === 'EXCLUDE') ? 'EXCLUDE' : 'USABLE_WITH_QUALIFICATION';
            $qaNotes[] = 'ex_blob_parse_fail';
        } elseif ($exStatus === 'EMPTY') {
            $qaClass = ($qaClass === 'HIGH_CONFIDENCE') ? 'USABLE_WITH_QUALIFICATION' : $qaClass;
            $qaNotes[] = 'empty_ex_blob';
        }
        if ($g['raw'] === '') {
            $qaClass = ($qaClass === 'HIGH_CONFIDENCE') ? 'USABLE_WITH_QUALIFICATION' : $qaClass;
            $qaNotes[] = 'blank_session_grade';
        }

        $pub = trim((string)$row['sctr_public_comment']);
        $priv = trim((string)$row['sctr_private_comment']);

        $sessionInsert->execute([
            $etlRunId, 'egle', $dbName, $table, (int)$row['sctr_id'],
            $progRow ? (int)$progRow['source_program_id'] : null,
            $progRow ? (int)$progRow['program_id'] : null,
            $progRow ? (int)$progRow['curriculum_family_id'] : null,
            $progRow ? (int)$progRow['curriculum_version_id'] : null,
            $studentId, $sourceStudent, $instructorId, $sourceInstr,
            $missionId, $sourceMission, $deviceId, $sourceDevice,
            $dateValid ? $date : null, $dateValid,
            $typeRaw, $typeNorm,
            $dual, $pic, $fnpt, $brief, $total, $flight, $sim, $ground,
            $g['raw'], $g['color'], $g['completion'], $g['category'],
            $nextRaw, $altRaw, $nextRaw === $NONE_SENTINEL ? 1 : 0, $altRaw === $NONE_SENTINEL ? 1 : 0,
            null_if_zero_date(isset($row['sctr_sign_inst_date']) ? (string)$row['sctr_sign_inst_date'] : null),
            null_if_zero_date(isset($row['sctr_sign_stud_date']) ? (string)$row['sctr_sign_stud_date'] : null),
            $exStatus, $srmParsed['status'], $ksaParsed['status'],
            $graded, $below, $at, $above, $deferred,
            ($below === 0 && $deferred === 0 && $graded > 0) ? 1 : (($graded === 0) ? null : 0),
            $pub !== '' ? 1 : 0, $priv !== '' ? 1 : 0,
            $qaClass, implode(',', $qaNotes),
        ]);
        $sessionId = (int)$ana->lastInsertId();
        $stats['sessions_mapped']++;
        map_source($ana, $etlRunId, 'fact_training_session', $sessionId, $dbName, $table, (string)$row['sctr_id'], $table);

        if ($qaClass === 'EXCLUDE') {
            $ana->prepare('INSERT INTO qa_exclusion_log (etl_run_id, source_table, source_pk, canonical_table, reason_code, reason_detail, qa_class) VALUES (?,?,?,?,?,?,?)')
                ->execute([$etlRunId, $table, (string)$row['sctr_id'], 'fact_training_session', $qaNotes[0] ?? 'excluded', implode(',', $qaNotes), $qaClass]);
        }

        foreach ($exRows as $er) {
            $exAttemptInsert->execute([
                $etlRunId, $sessionId, $table, (int)$row['sctr_id'], $er['source_exercise_id'], $er['exercise_id'],
                $studentId, $instructorId, $missionId, $progRow ? (int)$progRow['program_id'] : null,
                $dateValid ? $date : null, $er['name_raw'], $er['req_raw'], $er['req_norm'],
                $er['grade_raw'], $er['stage'], $er['met'], $er['exceeded'], $er['not_met'], $er['deferred'],
                $er['parse_status'], $er['warnings'],
            ]);
            $stats['exercise_attempts']++;
        }

        if ($srmParsed['ok'] && is_array($srmParsed['data'])) {
            foreach ($srmParsed['data'] as $k => $v) {
                $k = (string)$k;
                if (!isset($srmLabels[$k])) {
                    $ana->prepare('INSERT OR IGNORE INTO dim_srm_key (raw_key, probable_ui_label, confidence, evidence) VALUES (?,?,?,?)')
                        ->execute([$k, null, 'UNKNOWN', 'Observed in sctr_srm blob; no UI label found in inspected code']);
                    $srmLabels[$k] = null;
                }
                $srmInsert->execute([
                    $etlRunId, $sessionId, $table, (int)$row['sctr_id'], $k, is_scalar($v) ? (string)$v : json_encode($v),
                    $srmLabels[$k], $studentId, $instructorId, $missionId, $progRow ? (int)$progRow['program_id'] : null,
                    $dateValid ? $date : null, 'OK',
                ]);
                $stats['srm_attempts']++;
            }
        } elseif ($srmParsed['status'] === 'FAIL') {
            qa_issue($ana, $etlRunId, 'WARN', 'SRM_BLOB_PARSE_FAIL', 'fact_training_session', $sessionId, $table, (string)$row['sctr_id'], $srmParsed['error'] ?? '');
            $stats['qa_issues']++;
        }

        foreach ([['public', $pub], ['private', $priv]] as [$ctype, $text]) {
            if ($text === '') {
                continue;
            }
            $hash = hash('sha256', $text);
            $narrInsert->execute([
                $etlRunId, $sessionId, $table, (int)$row['sctr_id'], $studentId, $instructorId, $ctype, $text, $hash,
                strlen($text), str_word_count($text),
            ]);
            $stats['narratives']++;
        }

        if ($n % 500 === 0) {
            $ana->commit();
            $ana->beginTransaction();
            echo "  {$table}: {$n} rows\n";
        }
    }
    $ana->commit();
}

echo "Loading logbook...\n";
$lbInsert = $ana->prepare('INSERT INTO fact_logbook_leg (
  etl_run_id, source_lb_id, source_tracking_table, source_sctr_id, session_id, student_id, instructor_id, device_id,
  dep, arr, dep_time_unix, duration_hours, landings, cond_raw, ifr_raw, fnpt_raw, brief_raw, dual_raw, xc_raw
) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
$sessionLookup = $ana->prepare('SELECT session_id, student_id, instructor_id, device_id FROM fact_training_session WHERE source_table = ? AND source_sctr_id = ?');
$ana->beginTransaction();
$i = 0;
foreach (egle_select($src, 'SELECT * FROM logbook') as $lb) {
    $i++;
    $stable = (string)$lb['lb_db'];
    $sctr = (int)$lb['lb_sctr'];
    $sessionLookup->execute([$stable, $sctr]);
    $sess = $sessionLookup->fetch(PDO::FETCH_ASSOC) ?: null;
    $lbInsert->execute([
        $etlRunId, (int)$lb['lb_id'], $stable, $sctr,
        $sess['session_id'] ?? null,
        $sess['student_id'] ?? ($studentIdBySource[(int)$lb['lb_student']] ?? null),
        $sess['instructor_id'] ?? ($instructorIdBySource[(int)$lb['lb_instr']] ?? null),
        $sess['device_id'] ?? ($deviceIdBySource[(int)$lb['lb_dev']] ?? null),
        $lb['lb_dep'], $lb['lb_arr'], (int)$lb['lb_deptime'], (float)$lb['lb_dur'], (int)$lb['lb_ld'],
        $lb['lb_cond'], $lb['lb_ifr'], $lb['lb_fnpt'], $lb['lb_brief'], $lb['lb_dual'], $lb['lb_xc'],
    ]);
    $stats['logbook_legs']++;
    if ($i % 1000 === 0) {
        $ana->commit();
        $ana->beginTransaction();
        echo "  logbook {$i}\n";
    }
}
$ana->commit();

echo "Computing temporal and progression features...\n";
passthru(escapeshellarg(PHP_BINARY ?: 'php') . ' ' . escapeshellarg($root . '/analytics/etl/compute_features.php') . ' ' . escapeshellarg((string)$etlRunId) . ' ' . escapeshellarg($analyticsDbPath), $featCode);
if ($featCode !== 0) {
    fwrite(STDERR, "Feature computation failed with code {$featCode}\n");
}

echo "Building duplicate-student candidates...\n";
passthru(escapeshellarg(PHP_BINARY ?: 'php') . ' ' . escapeshellarg($root . '/analytics/etl/identity_candidates.php') . ' ' . escapeshellarg($analyticsDbPath), $idCode);

$ana->prepare('UPDATE etl_run SET finished_at = ?, status = ?, stats_json = ? WHERE etl_run_id = ?')
    ->execute([gmdate('c'), 'SUCCESS', json_encode($stats, JSON_UNESCAPED_SLASHES), $etlRunId]);

echo "DONE etl_run_id={$etlRunId}\n";
echo json_encode($stats, JSON_PRETTY_PRINT) . "\n";
