<?php
declare(strict_types=1);

function mission_215_task(string $id, string $area, string $title, array $standards = array(), bool $required = true): array
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

$weatherStandards = array(
    'Meteorology applicable to departure, en route, alternate and destination under VFR in VMC, including expected climate and hazardous conditions.',
    'Go/no-go and continue/divert decisions.',
    'Personal weather minimums and limitations of onboard weather equipment.',
    'Limitations of aviation weather reports, forecasts and in-flight weather resources.',
    'Use available weather resources for an adequate briefing and go/no-go decision.',
    'Discuss weather implications using actual conditions or instructor-provided scenario data.',
    'Correlate weather information to make a go/no-go decision.',
);

$runwayRiskStandards = array(
    'Selection of runway based on pilot capability, aircraft performance, available distance and wind.',
    'Effects of crosswind, wind shear, tailwind, wake turbulence and runway surface condition.',
    'Collision hazards involving aircraft, terrain, obstacles and wires.',
    'Distraction, situational awareness and task management.',
    'Low-altitude maneuvering, stall and spin awareness.',
);

$abnormalTakeoffStandards = array(
    'Planning for rejected takeoff and engine failure in the takeoff/climb phase.',
    'Complete appropriate checklists and make radio calls as appropriate.',
    'Verify assigned runway, wind indication and flight-control position for conditions.',
);

$softFieldTakeoffStandards = array_merge($abnormalTakeoffStandards, array(
    'Effects of atmospheric conditions on takeoff and climb performance.',
    'VX and VY, appropriate configuration, ground effect and weight transfer.',
    'Left turning tendencies.',
    'Clear the area, align on centerline, advance throttle smoothly and confirm engine indications.',
    'Lift off at lowest practical airspeed; remain in ground effect while accelerating to VX or VY.',
    'Maintain VX or VY +10/-5 knots and proper wind-drift correction through climb.',
));

$softFieldLandingStandards = array_merge($runwayRiskStandards, array(
    'Stabilized approach and energy management concepts.',
    'Wind-correction techniques on approach and landing.',
    'Abnormal operations planning for rejected landing and go-around.',
    'Maintain recommended approach speed or not more than 1.3 VSO +10/-5 knots with gust factor.',
    'Touch down with minimum sink, no side drift and longitudinal axis aligned with runway centerline.',
    'Maintain elevator recommendation during rollout and exit soft surface at safe taxi speed.',
));

$shortFieldTakeoffStandards = array_merge($abnormalTakeoffStandards, array(
    'Apply brakes while setting power for maximum performance.',
    'Rotate at recommended airspeed and accelerate to obstacle clearance airspeed or VX +10/-5 knots.',
    'Maintain obstacle clearance pitch until obstacle cleared or 50 feet AGL, then accelerate to VY.',
    'Maintain VY +10/-5 knots to a safe maneuvering altitude.',
));

$shortFieldLandingStandards = array_merge($runwayRiskStandards, array(
    'Stabilized approach and energy management concepts.',
    'Touch down at recommended airspeed within 200 feet beyond the specified point with minimum float.',
    'Use manufacturer-recommended configuration and braking; utilize runway incursion avoidance procedures.',
    'Execute safe and timely go-around when the approach cannot be made within tolerances.',
));

$instrumentStandards = array(
    'Maintain altitude ±200 ft, heading ±20° and airspeed ±10 kt using proper cross-check.',
    'Use standard-rate turns and roll out within ±10° of assigned heading.',
    'Recover from unusual attitudes using proper instrument interpretation and wing unloading when appropriate.',
    'Maintain aircraft control while selecting frequencies and complying with ATC/radar instructions.',
);

$taskRows = array(
    array('preflight.pilot_qualifications', 'Preflight Preparation', 'Pilot Qualifications'),
    array('preflight.airworthiness', 'Preflight Preparation', 'Airworthiness Requirements'),
    array('preflight.weather', 'Preflight Preparation', 'Weather Information', $weatherStandards),
    array('preflight.airspace', 'Preflight Preparation', 'National Airspace System'),
    array('preflight.performance', 'Preflight Preparation', 'Performance and Limitations'),
    array('preflight.systems', 'Preflight Preparation', 'Operation of Systems', array(
        'Identify system malfunctions or failures.',
        'Handle system failures using appropriate checklists.',
    )),
    array('preflight.human_factors', 'Preflight Preparation', 'Human Factors'),
    array('procedures.assessment', 'Preflight Procedures', 'Preflight Assessment'),
    array('procedures.flight_deck', 'Preflight Procedures', 'Flight Deck Management'),
    array('procedures.engine_start', 'Preflight Procedures', 'Engine Starting'),
    array('procedures.taxi', 'Preflight Procedures', 'Taxiing'),
    array('procedures.before_takeoff', 'Preflight Procedures', 'Before Takeoff Check', array(
        'Complete checklist and full standardized takeoff briefing without instructor assistance.',
    )),
    array('airport.communications', 'Airport Operations', 'Communications and Light Signals'),
    array('airport.patterns', 'Airport Operations', 'Traffic Patterns'),
    array('maneuvers.basic', 'Airport Operations', 'Basic Flight Maneuvers'),
    array('takeoff.normal', 'Takeoff, Landing and Go-Around', 'Normal Takeoff and Climb'),
    array('landing.normal', 'Takeoff, Landing and Go-Around', 'Normal Approach and Landing'),
    array('takeoff.soft_field', 'Takeoff, Landing and Go-Around', 'Soft-Field Takeoff and Climb', $softFieldTakeoffStandards),
    array('landing.soft_field', 'Takeoff, Landing and Go-Around', 'Soft-Field Approach and Landing', $softFieldLandingStandards),
    array('takeoff.short_field', 'Takeoff, Landing and Go-Around', 'Short-Field Takeoff and Max Performance Climb', $shortFieldTakeoffStandards),
    array('landing.short_field', 'Takeoff, Landing and Go-Around', 'Short-Field Approach and Landing', $shortFieldLandingStandards),
    array('landing.forward_slip', 'Takeoff, Landing and Go-Around', 'Forward Slip to a Landing'),
    array('landing.go_around', 'Takeoff, Landing and Go-Around', 'Go-Around / Rejected Landing'),
    array('maneuvers.ground_reference', 'Performance and Ground Reference Maneuvers', 'Ground Reference Maneuver'),
    array('navigation.pilotage', 'Navigation', 'Pilotage and Dead Reckoning'),
    array('navigation.systems', 'Navigation', 'Navigation Systems and Radar Services', array(
        'Use GPS direct-to and other airborne navigation systems to determine position.',
        'Navigate back to Thermal Airport using GPS while making appropriate ATC calls.',
    )),
    array('navigation.lost', 'Navigation', 'Lost Procedures'),
    array('stalls.slow_flight', 'Slow Flight and Stalls', 'Slow Flight and Stall Awareness'),
    array('instrument.straight_level', 'Basic Instrument Maneuvers', 'Straight-and-Level Flight', $instrumentStandards),
    array('instrument.climbs', 'Basic Instrument Maneuvers', 'Constant-Airspeed Climbs', $instrumentStandards),
    array('instrument.descents', 'Basic Instrument Maneuvers', 'Constant-Airspeed Descents', $instrumentStandards),
    array('instrument.turns', 'Basic Instrument Maneuvers', 'Turns to Headings', $instrumentStandards),
    array('instrument.unusual_attitudes', 'Basic Instrument Maneuvers', 'Recovery from Unusual Attitudes', array_merge($instrumentStandards, array(
        'Recover from nose-high and nose-low unusual attitudes under the hood until proficient.',
    ))),
    array('instrument.com_nav', 'Basic Instrument Maneuvers', 'Radio Communications, Navigation Systems and Radar Services', array_merge($instrumentStandards, array(
        'Practice simulated ATC calls and comply with radar headings and altitude changes.',
    ))),
    array('emergency.descent', 'Emergency Operations', 'Emergency Descent'),
    array('emergency.approach', 'Emergency Operations', 'Emergency Approach and Landing', array(
        'Engine failure after takeoff and loss of engine power after takeoff.',
        'Establish best glide and select a suitable landing area or execute go-around as appropriate.',
    )),
    array('emergency.systems', 'Emergency Operations', 'Systems and Equipment Malfunctions'),
    array('emergency.equipment', 'Emergency Operations', 'Emergency Equipment and Survival Gear'),
    array('postflight.securing', 'Postflight Operations', 'After Landing, Parking and Securing'),
);
$tasks = array_map(
    fn(array $row): array => mission_215_task($row[0], $row[1], $row[2], $row[3] ?? array()),
    $taskRows
);

$exercise = array(
    'schema_version' => 'ipca.mission.exercise.v2',
    'source' => array(
        'scenario' => array(
            'document' => 'Part 2 Private Pilot Certification Course, Scenario 2-1-5',
            'revision' => '1.0',
            'date' => '2022-05-13',
            'pages' => array(222, 223),
        ),
        'evaluation' => array(
            'document' => 'Debriefing Sheet PPL 2-1-5 (ACFT)',
            'revision' => 'Original',
            'date' => '2018-08-16',
            'pages' => 11,
        ),
    ),
    'scenario_plan' => array(
        'objective' => 'Phase 1 Progress Check to determine readiness to move on to the next phase of training. The customer prepares the scenario, performs pilot self-assessment and risk assessment, makes go/no-go decisions from actual weather and NOTAM information, and performs requested items without instructor assistance.',
        'type' => 'ACFT',
        'planned_time' => array('dual_hours' => 1.0, 'basic_instrument_hours' => 0.3),
        'locations' => array('Thermal Airport (KTRM)', 'Salton Sea Training Area'),
        'navigation_method' => array('Pilotage', 'GPS'),
        'planned_deviations' => array('None'),
        'planned_malfunctions' => array(
            'Engine failure in takeoff/climb',
        ),
        'expected_events' => array(
            'At least one go-around',
            'Engine failure after takeoff and/or loss of engine power after takeoff',
            'Simulated inadvertent IMC encounter under the hood',
        ),
        'purpose_pressures' => array('As assigned by the instructor'),
        'risks' => array(
            'Go/no-go and continue/divert decisions',
            'Personal weather minimums',
            'Limitations of onboard weather equipment and aviation weather reports/forecasts',
            'In-flight weather resources',
            'Runway selection based on capability, performance, distance and wind',
            'Crosswind, wind shear, tailwind, wake turbulence and runway surface condition',
            'Collision hazards involving aircraft, terrain, obstacles and wires',
            'Distraction, situational awareness and task management',
            'Low-altitude maneuvering, stall and spin exposure',
            'Rejected takeoff, engine failure in takeoff/climb and go-around planning',
            'Instrument flying hazards including spatial disorientation and loss of control',
            'Failure to seek assistance or declare an emergency in a deteriorating situation',
            'Failure to interpret flight instruments and unload wings in high-G recovery',
            'Failure to utilize all available resources including automation, ATC and flight deck planning aids',
        ),
        'chronology' => array(
            array('id' => 'briefing', 'title' => 'Flight preparation briefing using Preflight Preparation Checklist and actual data', 'required' => true),
            array('id' => 'preflight', 'title' => 'Preflight inspection without instructor assistance', 'required' => true),
            array('id' => 'engine_start', 'title' => 'Before start, engine start and after-start checklists without assistance', 'required' => true),
            array('id' => 'taxi', 'title' => 'Taxi route briefing, radio calls and taxi to run-up area', 'required' => true),
            array('id' => 'before_takeoff', 'title' => 'Before takeoff checklist including full standardized takeoff briefing', 'required' => true),
            array('id' => 'lineup_takeoff', 'title' => 'Line up and initial takeoff', 'required' => true),
            array('id' => 'soft_field_pattern', 'title' => 'Soft-field takeoffs, normal patterns and soft-field full-stop landings', 'required' => true),
            array('id' => 'short_field_pattern', 'title' => 'Short-field takeoffs, normal patterns and short-field full-stop landings', 'required' => true),
            array('id' => 'go_around_engine_failure', 'title' => 'Go-around and engine failure after takeoff / loss of power after takeoff', 'required' => true),
            array('id' => 'salton_sea_departure', 'title' => 'Normal takeoff to Salton Sea training area at 2000 feet', 'required' => true),
            array('id' => 'hood_imc', 'title' => 'Hood on at cruise; simulated inadvertent IMC encounter', 'required' => true),
            array('id' => 'instrument_maneuvers', 'title' => 'Straight-and-level, turns, climbs, descents, scanning and unusual attitude recoveries (nose high and nose low)', 'required' => true),
            array('id' => 'radar_atc', 'title' => 'Simulated ATC calls and radar headings/altitude changes under the hood', 'required' => true),
            array('id' => 'return_gps', 'title' => 'GPS direct return to Thermal and standard traffic pattern entry', 'required' => true),
            array('id' => 'final_landing', 'title' => 'Normal approach and landing', 'required' => true),
            array('id' => 'securing', 'title' => 'After-landing checklist, taxi, securing aircraft and post-flight actions', 'required' => true),
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

return array(
    'code' => '2-1-5',
    'name' => 'Phase 1 Progress Check - (1.5h DUAL/1.0 B-IR)',
    'description' => 'Canonical Scenario 2-1-5 plan and evaluation rubric for evidence-backed debriefing.',
    'exercise' => $exercise,
);
