<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/MissionCatalogService.php';

function mission_149_task(string $id, string $area, string $title, array $standards = array(), bool $required = true): array
{
    return array(
        'id' => $id,
        'area' => $area,
        'title' => $title,
        'required' => $required,
        'required_standard' => 'PE',
        'standards' => $standards === array() ? array('Perform to applicable ACS standards.') : $standards,
        'grade_scale' => array('DE', 'EX', 'PR', 'PE', 'NO'),
    );
}

$taskRows = array(
    array('preflight.pilot_qualifications', 'Preflight Preparation', 'Pilot Qualifications', array('Certification, currency, medical and required documents.', 'Set personal minimums and establish fitness for flight.', 'Apply VFR PIC requirements to the scenario.')),
    array('preflight.airworthiness', 'Preflight Preparation', 'Airworthiness Requirements'),
    array('preflight.weather', 'Preflight Preparation', 'Weather Information'),
    array('preflight.airspace', 'Preflight Preparation', 'National Airspace System', array('Identify airspace, chart symbols, SUA, SFRA and TFR.', 'Apply communication, equipment and VFR weather minimum requirements.')),
    array('preflight.performance', 'Preflight Preparation', 'Performance and Limitations'),
    array('preflight.systems', 'Preflight Preparation', 'Operation of Systems', array('Operate avionics and pitot-static/vacuum flight instruments.', 'Recognize and properly handle system failures.', 'Use appropriate checklists.')),
    array('preflight.human_factors', 'Preflight Preparation', 'Human Factors'),
    array('procedures.assessment', 'Preflight Procedures', 'Preflight Assessment'),
    array('procedures.flight_deck', 'Preflight Procedures', 'Flight Deck Management'),
    array('procedures.engine_start', 'Preflight Procedures', 'Engine Starting'),
    array('procedures.taxi', 'Preflight Procedures', 'Taxiing'),
    array('procedures.before_takeoff', 'Preflight Procedures', 'Before Takeoff Check', array('Complete checklist and standardized takeoff briefing.', 'Manage runway changes and wake turbulence risk.')),
    array('airport.communications', 'Airport Operations', 'Communications and Light Signals'),
    array('airport.patterns', 'Airport Operations', 'Traffic Patterns'),
    array('maneuvers.basic', 'Airport Operations', 'Basic Flight Maneuvers'),
    array('takeoff.normal', 'Takeoff, Landing and Go-Around', 'Normal Takeoff and Climb'),
    array('landing.normal', 'Takeoff, Landing and Go-Around', 'Normal Approach and Landing'),
    array('landing.forward_slip', 'Takeoff, Landing and Go-Around', 'Forward Slip to a Landing', array('Use appropriate energy, wind correction and runway-selection concepts.', 'Configure correctly and make appropriate radio calls/checklists.', 'Touch down within -0/+400 feet with minimum side drift.')),
    array('landing.go_around', 'Takeoff, Landing and Go-Around', 'Go-Around / Rejected Landing'),
    array('maneuvers.steep_turns', 'Performance and Ground Reference', 'Steep Turns'),
    array('maneuvers.ground_reference', 'Performance and Ground Reference', 'Ground Reference Maneuver'),
    array('navigation.pilotage', 'Navigation', 'Pilotage and Dead Reckoning', array('Navigate by pilotage.', 'Manage compass error, collision, distraction and navigation-system limitations.')),
    array('navigation.systems', 'Navigation', 'Navigation Systems and Radar Services', array('Use airborne navigation systems and determine aircraft position.')),
    array('navigation.lost', 'Navigation', 'Lost Procedures', array('Determine position and maintain safe heading/altitude.', 'Use landmarks, navigation facilities or ATC assistance.', 'Record waypoint times and seek assistance before deterioration.')),
    array('stalls.slow_flight', 'Slow Flight and Stalls', 'Maneuvering During Slow Flight'),
    array('stalls.power_off', 'Slow Flight and Stalls', 'Power-Off Stalls'),
    array('stalls.power_on', 'Slow Flight and Stalls', 'Power-On Stalls'),
    array('stalls.spin_awareness', 'Slow Flight and Stalls', 'Spin Awareness'),
    array('instrument.straight_level', 'Basic Instrument Maneuvers', 'Straight-and-Level Flight', array('Maintain altitude ±200 ft, heading ±20°, airspeed ±10 kt using proper cross-check.')),
    array('instrument.climbs', 'Basic Instrument Maneuvers', 'Constant-Airspeed Climbs', array('Use proper instrument cross-check and coordinated control.', 'Level at assigned altitude; maintain ±200 ft, ±20°, ±10 kt.')),
    array('instrument.descents', 'Basic Instrument Maneuvers', 'Constant-Airspeed Descents', array('Use proper instrument cross-check and coordinated control.', 'Level at assigned altitude; maintain ±200 ft, ±20°, ±10 kt.')),
    array('instrument.turns', 'Basic Instrument Maneuvers', 'Turns to Headings', array('Maintain altitude ±200 ft and airspeed ±10 kt.', 'Use standard rate and roll out within ±10°.')),
    array('instrument.unusual_attitudes', 'Basic Instrument Maneuvers', 'Recovery from Unusual Attitudes', array('Recognize solely by instruments and recover correctly within aircraft limitations.', 'Interpret instruments and unload wings when appropriate.')),
    array('instrument.com_nav', 'Basic Instrument Maneuvers', 'Radio Communications, Navigation Systems and Radar Services', array('Maintain control while selecting frequencies and managing navigation equipment.', 'Comply with ATC instructions and use all available resources.')),
    array('emergency.descent', 'Emergency Operations', 'Emergency Descent', array('Establish appropriate airspeed/configuration and clear the area.', 'Use 30°–45° bank, maintain positive load factor and complete checklist.')),
    array('emergency.approach', 'Emergency Operations', 'Emergency Approach and Landing', array('Establish best glide ±10 kt and select a suitable landing area.', 'Plan and follow a safe flightpath and complete the checklist.')),
    array('emergency.systems', 'Emergency Operations', 'Systems and Equipment Malfunctions', array('Partial/complete engine power loss.', 'Pitot-static or electronic display malfunction.', 'Landing gear/flap malfunction and inoperative trim.')),
    array('emergency.equipment', 'Emergency Operations', 'Emergency Equipment and Survival Gear'),
    array('postflight.securing', 'Postflight Operations', 'After Landing, Parking and Securing'),
);
$tasks = array_map(
    fn(array $row): array => mission_149_task($row[0], $row[1], $row[2], $row[3] ?? array()),
    $taskRows
);

$exercise = array(
    'schema_version' => 'ipca.mission.exercise.v2',
    'source' => array(
        'scenario' => array(
            'document' => 'Part 2 Private Pilot Certification Course, Scenario 1-4-9',
            'revision' => '1.0',
            'date' => '2022-05-13',
            'pages' => array(178, 179, 180),
        ),
        'evaluation' => array(
            'document' => 'Debriefing Sheet PPL 1-4-9 (ACFT)',
            'revision' => 'Original',
            'date' => '2018-08-16',
            'pages' => 10,
        ),
    ),
    'scenario_plan' => array(
        'objective' => 'Phase 4 Progress Check to determine readiness for first solo flights in Stage 5.',
        'type' => 'ACFT',
        'planned_time' => array('dual_hours' => 1.0, 'basic_instrument_hours' => 0.3),
        'locations' => array('Salton Sea Training Area', 'Thermal Airport'),
        'navigation_method' => 'Pilotage',
        'planned_deviations' => array('Divert to Thermal after simulated IMC encounter.'),
        'planned_malfunctions' => array(
            'Low Voltage Warning or Low Vacuum Warning on the ground',
            'Radio Communication Failure',
            'Engine Failure',
            'Engine Fire',
            'Electrical Fire or Cabin Fire',
            'Full Flap Failure',
        ),
        'risks' => array(
            'Personal minimums and fitness for flight',
            'Airspace compliance',
            'Recognition and handling of system failures',
            'Collision hazards involving aircraft, terrain, obstacles and wires',
            'Distraction, situational awareness and task management',
            'Waypoint time recording',
            'Timely use of assistance or emergency declaration',
            'Instrument flight hazards and spatial disorientation',
            'Aircraft configuration and low-altitude stall/spin exposure',
            'Landing-area, wind, terrain, obstruction and distance considerations',
        ),
        'chronology' => array(
            array('id' => 'briefing', 'title' => 'Pre-solo knowledge and flight preparation briefing', 'required' => true),
            array('id' => 'preflight', 'title' => 'Preflight inspection, start and taxi without assistance', 'required' => true),
            array('id' => 'takeoff', 'title' => 'Before-takeoff checklist, briefing and takeoff', 'required' => true),
            array('id' => 'pilotage', 'title' => 'Pilotage to Salton Sea training area', 'required' => true),
            array('id' => 'slow_flight', 'title' => 'Slow flight entry, turns, climbs, descents and recovery', 'required' => true),
            array('id' => 'power_off_stalls', 'title' => 'Power-off stalls in banked approach and wings-level landing configurations', 'required' => true),
            array('id' => 'power_on_stalls', 'title' => 'Power-on stalls wings-level and banked in takeoff configuration', 'required' => true),
            array('id' => 'steep_turns', 'title' => 'Left and right steep turns near 45° bank', 'required' => true),
            array('id' => 'ground_reference', 'title' => 'One ground reference maneuver', 'required' => true),
            array('id' => 'instrument', 'title' => 'Instrument turns, climb and descent to assigned altitudes', 'required' => true),
            array('id' => 'communication_failure', 'title' => 'Simulated communication failure', 'required' => true),
            array('id' => 'pattern_entry', 'title' => 'Standard traffic pattern entry', 'required' => true),
            array('id' => 'normal_landing', 'title' => 'Normal approach and landing', 'required' => true),
            array('id' => 'go_around', 'title' => 'Normal approach and go-around', 'required' => true),
            array('id' => 'engine_failure_downwind', 'title' => 'Engine failure on downwind and emergency approach', 'required' => true),
            array('id' => 'flap_failure', 'title' => 'Full-flap-failure approach and landing', 'required' => true),
            array('id' => 'forward_slip', 'title' => 'Forward slip to a landing', 'required' => true),
            array('id' => 'engine_failure_after_touch_go', 'title' => 'Engine failure after touch-and-go', 'required' => true),
            array('id' => 'securing', 'title' => 'After-landing, taxi, securing and postflight actions', 'required' => true),
        ),
        'expected_evidence_signals' => array(
            'exercise_markers_define_segment_boundaries',
            'training_remark_markers_prioritize_following_transcript_audio',
            'safety_event_markers_create_high_priority_safety_windows',
            'garmin_supports_aircraft_performance_and_flight_path_only',
            'transcript_supports_instruction_prompting_checklists_and_decisions',
            'adsb_supports_traffic_context_but_not_pilot_visual_acquisition',
        ),
    ),
    'evaluation_rubric' => array(
        'task_scale' => array(
            'DE' => 'Describe at rote level.',
            'EX' => 'Explain underlying concepts, principles and procedures.',
            'PR' => 'Plan and execute with coaching, instruction or assistance.',
            'PE' => 'Perform without instructor assistance; identify and correct deviations expeditiously.',
            'NO' => 'Not observed or not required in the scenario.',
        ),
        'srm_scale' => array(
            'EX' => 'Verbally identify risks inherent in the scenario.',
            'PR' => 'Identify, describe and understand risks; prompting may be needed.',
            'MD' => 'Gather key data, evaluate options and risk, and decide appropriately without instructor intervention.',
            'NO' => 'Not observed or not required in the scenario.',
        ),
        'tasks' => $tasks,
        'srm_items' => array_map(fn(array $row): array => array(
            'id' => $row[0],
            'title' => $row[1],
            'required' => true,
            'required_standard' => 'MD',
            'grade_scale' => array('EX', 'PR', 'MD', 'NO'),
        ), array(
            array('srm.safety_management', 'Safety Management'),
            array('srm.task_management', 'Task Management'),
            array('srm.automation_management', 'Automation Management'),
            array('srm.risk_management', 'Risk Management'),
            array('srm.aeronautical_decision_making', 'Aeronautical Decision Making'),
            array('srm.situational_awareness', 'Situational Awareness'),
            array('srm.cfit', 'Controlled Flight Into Terrain'),
        )),
        'overall_rules' => array(
            'BLUE' => 'At least 25% above required, none below.',
            'GREEN' => 'All meet/exceed required, fewer than 25% above.',
            'YELLOW' => 'Up to 25% below required.',
            'RED' => 'More than 25% below required or safety was insufficient.',
            'INCOMPLETE' => 'Any required task explicitly not completed; separate from below-standard performance.',
        ),
        'evidence_rules' => array(
            'Absence from transcript is insufficient_evidence, never automatic NO.',
            'Garmin can support performance but not instruction, prompting or decision quality.',
            'ADS-B can provide traffic context but cannot prove the student saw traffic.',
            'Instructor grading and approval are authoritative.',
        ),
    ),
);

$currentStatement = $pdo->prepare(
    'SELECT m.*, v.exercise_json
     FROM ipca_missions m
     LEFT JOIN ipca_mission_versions v ON v.id = m.current_version_id
     WHERE m.organization_id = 1 AND UPPER(m.code) = \'1-4-9\' LIMIT 1'
);
$currentStatement->execute();
$currentMission = $currentStatement->fetch(PDO::FETCH_ASSOC);
$currentExercise = is_array($currentMission)
    ? json_decode((string)($currentMission['exercise_json'] ?? ''), true)
    : null;
if (is_array($currentMission)
    && is_array($currentExercise)
    && ($currentExercise['schema_version'] ?? '') === 'ipca.mission.exercise.v2'
    && is_array($currentExercise['scenario_plan'] ?? null)
    && is_array($currentExercise['evaluation_rubric'] ?? null)) {
    $mission = $currentMission;
} else {
    $mission = (new MissionCatalogService($pdo))->upsertMission(
        '1-4-9',
        'Phase 4 - Progress Check (3.0h DUAL/1.3h B-IR)',
        'Canonical Scenario 1-4-9 plan and evaluation rubric for evidence-backed debriefing.',
        $exercise,
        null
    );
}

$missionVersionId = (int)($mission['current_version_id'] ?? 0);
if ($missionVersionId <= 0) {
    throw new RuntimeException('Canonical mission version was not created.');
}
$documentInsert = $pdo->prepare(
    'INSERT INTO ipca_mission_canonical_documents
     (document_uuid, mission_version_id, document_type, schema_version, source_document,
      source_revision, source_date, content_sha256, content_json)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
);
foreach (array(
    'scenario_plan' => array($exercise['scenario_plan'], $exercise['source']['scenario'], 'scenario.v1'),
    'evaluation_rubric' => array($exercise['evaluation_rubric'], $exercise['source']['evaluation'], 'rubric.v1'),
) as $documentType => [$content, $source, $documentSchemaVersion]) {
    $contentJson = AuditEventService::jsonEncode($content);
    $contentHash = hash('sha256', $contentJson);
    $existingDocument = $pdo->prepare(
        'SELECT id, content_sha256 FROM ipca_mission_canonical_documents
         WHERE mission_version_id = ? AND document_type = ? LIMIT 1'
    );
    $existingDocument->execute(array($missionVersionId, $documentType));
    $existing = $existingDocument->fetch(PDO::FETCH_ASSOC);
    if (is_array($existing)) {
        if (!hash_equals((string)$existing['content_sha256'], $contentHash)) {
            throw new RuntimeException('Existing canonical ' . $documentType . ' document has different content.');
        }
        continue;
    }
    $documentInsert->execute(array(
        AuditEventService::uuid(),
        $missionVersionId,
        $documentType,
        $documentSchemaVersion,
        (string)$source['document'],
        (string)$source['revision'],
        (string)$source['date'],
        $contentHash,
        $contentJson,
    ));
}

echo json_encode(array('ok' => true, 'mission' => $mission), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
