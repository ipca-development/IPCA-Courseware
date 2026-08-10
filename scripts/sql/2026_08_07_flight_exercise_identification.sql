-- Flight exercise identification foundation.
-- Identify maneuvers from evidence (crew markers, transcript, CSV).
-- ACS and SOP are separate foresight layers; evaluation_enabled defaults off.

CREATE TABLE IF NOT EXISTS ipca_flight_exercise_catalog (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  exercise_code VARCHAR(64) NOT NULL,
  display_name VARCHAR(160) NOT NULL,
  description_text TEXT NULL,
  transcript_aliases_json JSON NOT NULL,
  detection_rules_json JSON NOT NULL,
  detector_version VARCHAR(32) NOT NULL DEFAULT 'v1',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 1000,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_flight_exercise_code (exercise_code),
  KEY idx_flight_exercise_active_sort (is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Operational exercise catalog for evidence-based identification';

CREATE TABLE IF NOT EXISTS ipca_flight_exercise_acs_bindings (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  exercise_code VARCHAR(64) NOT NULL,
  qualification_code VARCHAR(64) NOT NULL,
  acs_task_code VARCHAR(64) NOT NULL,
  acs_task_title VARCHAR(255) NOT NULL,
  acs_area_title VARCHAR(255) NULL,
  criteria_json JSON NOT NULL,
  evaluation_enabled TINYINT(1) NOT NULL DEFAULT 0,
  evaluator_version VARCHAR(32) NOT NULL DEFAULT 'v1',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_flight_exercise_acs (exercise_code, qualification_code, acs_task_code),
  KEY idx_flight_exercise_acs_qual (qualification_code, is_active),
  CONSTRAINT fk_flight_exercise_acs_catalog
    FOREIGN KEY (exercise_code) REFERENCES ipca_flight_exercise_catalog(exercise_code)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='ACS bindings per exercise/qualification. Criteria foresight only until evaluation_enabled=1';

CREATE TABLE IF NOT EXISTS ipca_flight_exercise_sop_bindings (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  exercise_code VARCHAR(64) NOT NULL,
  organization_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  sop_code VARCHAR(96) NOT NULL,
  sop_title VARCHAR(255) NOT NULL,
  instruction_outline_json JSON NOT NULL,
  criteria_json JSON NOT NULL,
  evaluation_enabled TINYINT(1) NOT NULL DEFAULT 0,
  evaluator_version VARCHAR(32) NOT NULL DEFAULT 'v1',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_flight_exercise_sop (exercise_code, organization_id, sop_code),
  KEY idx_flight_exercise_sop_org (organization_id, is_active),
  CONSTRAINT fk_flight_exercise_sop_catalog
    FOREIGN KEY (exercise_code) REFERENCES ipca_flight_exercise_catalog(exercise_code)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='SOP/instruction bindings separate from ACS. organization_id=0 is global template; admin evaluation later';

CREATE TABLE IF NOT EXISTS ipca_detected_flight_exercises (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  detection_uuid CHAR(36) NOT NULL,
  recording_id BIGINT UNSIGNED NOT NULL,
  workflow_flight_record_uuid CHAR(36) NULL,
  exercise_code VARCHAR(64) NOT NULL,
  display_name VARCHAR(160) NOT NULL,
  t_start_seconds DOUBLE NOT NULL,
  t_end_seconds DOUBLE NULL,
  confidence DECIMAL(6,4) NOT NULL DEFAULT 0.5000,
  detector_version VARCHAR(32) NOT NULL DEFAULT 'v1',
  source_marker_event_uuid CHAR(36) NULL,
  evidence_json JSON NOT NULL,
  matched_acs_task_codes_json JSON NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'identified',
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_detected_flight_exercise_uuid (detection_uuid),
  KEY idx_detected_flight_exercise_recording (recording_id, t_start_seconds),
  KEY idx_detected_flight_exercise_wfr (workflow_flight_record_uuid),
  KEY idx_detected_flight_exercise_code (exercise_code),
  CONSTRAINT fk_detected_flight_exercise_catalog
    FOREIGN KEY (exercise_code) REFERENCES ipca_flight_exercise_catalog(exercise_code)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Evidence-based identified exercise instances. No ACS/SOP scoring in v1';

-- Catalog seeds
INSERT INTO ipca_flight_exercise_catalog
  (exercise_code, display_name, description_text, transcript_aliases_json, detection_rules_json, detector_version, is_active, sort_order)
VALUES
  ('power_off_stall', 'Power-Off Stall', 'Power-off stall in approach or landing configuration.',
   JSON_ARRAY('power off stall', 'power-off stall', 'full stall power off'),
   JSON_OBJECT(
     'crew_event_types', JSON_ARRAY('exercise_marker'),
     'telemetry', JSON_OBJECT('rpm_max', 2000, 'aoa_cp_min', 0.75, 'min_seconds', 2.0, 'pitch_min_deg', 12.0, 'groundspeed_max_kt', 55.0),
     'transcript_window_sec', 90,
     'marker_window_sec', 90
   ), 'v1', 1, 100),
  ('power_on_stall', 'Power-On Stall', 'Power-on stall in takeoff or departure configuration.',
   JSON_ARRAY('power on stall', 'power-on stall', 'departure stall', 'takeoff configuration stall'),
   JSON_OBJECT(
     'crew_event_types', JSON_ARRAY('exercise_marker'),
     'telemetry', JSON_OBJECT('rpm_min', 2000, 'aoa_cp_min', 0.75, 'min_seconds', 2.0, 'pitch_min_deg', 15.0),
     'transcript_window_sec', 90,
     'marker_window_sec', 90
   ), 'v1', 1, 110),
  ('slow_flight', 'Slow Flight', 'Maneuvering during slow flight.',
   JSON_ARRAY('slow flight', 'maneuvering during slow flight', 'minimum controllable airspeed'),
   JSON_OBJECT(
     'crew_event_types', JSON_ARRAY('exercise_marker'),
     'telemetry', JSON_OBJECT('ias_max_kt', 55.0, 'min_seconds', 8.0),
     'transcript_window_sec', 90,
     'marker_window_sec', 90
   ), 'v1', 1, 120),
  ('steep_turn', 'Steep Turn', 'Steep turn approximately 45° bank (Private) / 50° (Commercial).',
   JSON_ARRAY('steep turn', 'steep turns', 'forty five degree bank', 'fifty degree bank'),
   JSON_OBJECT(
     'crew_event_types', JSON_ARRAY('exercise_marker'),
     'telemetry', JSON_OBJECT('bank_abs_min_deg', 40.0, 'min_seconds', 3.0),
     'transcript_window_sec', 90,
     'marker_window_sec', 90
   ), 'v1', 1, 130),
  ('unusual_attitude_recovery', 'Unusual Attitude Recovery', 'Instrument unusual attitude recovery.',
   JSON_ARRAY('unusual attitude', 'unusual attitudes', 'recover from unusual attitude'),
   JSON_OBJECT(
     'crew_event_types', JSON_ARRAY('exercise_marker'),
     'telemetry', JSON_OBJECT(),
     'transcript_window_sec', 90,
     'marker_window_sec', 90
   ), 'v1', 1, 140)
ON DUPLICATE KEY UPDATE
  display_name = VALUES(display_name),
  description_text = VALUES(description_text),
  transcript_aliases_json = VALUES(transcript_aliases_json),
  detection_rules_json = VALUES(detection_rules_json),
  detector_version = VALUES(detector_version),
  is_active = VALUES(is_active),
  sort_order = VALUES(sort_order);

-- ACS bindings (criteria foresight; evaluation_enabled=0)
INSERT INTO ipca_flight_exercise_acs_bindings
  (exercise_code, qualification_code, acs_task_code, acs_task_title, acs_area_title, criteria_json, evaluation_enabled, evaluator_version, is_active)
VALUES
  ('power_off_stall', 'private_pilot_airplane', 'PA.VII.B', 'Power-Off Stalls', 'Slow Flight and Stalls',
   JSON_OBJECT('evaluation_enabled', false, 'elements', JSON_ARRAY(
     JSON_OBJECT('code', 'rpm_reduced', 'metric', 'rpm', 'op', '<', 'value', 2000, 'status', 'planned'),
     JSON_OBJECT('code', 'aoa_rise', 'metric', 'aoa_cp', 'op', '>', 'value', NULL, 'status', 'planned'),
     JSON_OBJECT('code', 'heading_bank_tolerance', 'status', 'planned'),
     JSON_OBJECT('code', 'recovery', 'status', 'planned')
   )), 0, 'v1', 1),
  ('power_off_stall', 'commercial_pilot_airplane', 'CA.VII.B', 'Power-Off Stalls', 'Slow Flight and Stalls',
   JSON_OBJECT('evaluation_enabled', false, 'elements', JSON_ARRAY(
     JSON_OBJECT('code', 'rpm_reduced', 'metric', 'rpm', 'op', '<', 'value', 2000, 'status', 'planned'),
     JSON_OBJECT('code', 'aoa_rise', 'metric', 'aoa_cp', 'op', '>', 'value', NULL, 'status', 'planned'),
     JSON_OBJECT('code', 'heading_bank_tolerance', 'status', 'planned'),
     JSON_OBJECT('code', 'recovery', 'status', 'planned')
   )), 0, 'v1', 1),
  ('power_on_stall', 'private_pilot_airplane', 'PA.VII.C', 'Power-On Stalls', 'Slow Flight and Stalls',
   JSON_OBJECT('evaluation_enabled', false, 'elements', JSON_ARRAY(
     JSON_OBJECT('code', 'power_set', 'metric', 'rpm', 'op', '>', 'value', 2000, 'status', 'planned'),
     JSON_OBJECT('code', 'aoa_rise', 'metric', 'aoa_cp', 'op', '>', 'value', NULL, 'status', 'planned'),
     JSON_OBJECT('code', 'recovery', 'status', 'planned')
   )), 0, 'v1', 1),
  ('power_on_stall', 'commercial_pilot_airplane', 'CA.VII.C', 'Power-On Stalls', 'Slow Flight and Stalls',
   JSON_OBJECT('evaluation_enabled', false, 'elements', JSON_ARRAY(
     JSON_OBJECT('code', 'power_set', 'metric', 'rpm', 'op', '>', 'value', 2000, 'status', 'planned'),
     JSON_OBJECT('code', 'aoa_rise', 'metric', 'aoa_cp', 'op', '>', 'value', NULL, 'status', 'planned'),
     JSON_OBJECT('code', 'recovery', 'status', 'planned')
   )), 0, 'v1', 1),
  ('slow_flight', 'private_pilot_airplane', 'PA.VII.A', 'Maneuvering During Slow Flight', 'Slow Flight and Stalls',
   JSON_OBJECT('evaluation_enabled', false, 'elements', JSON_ARRAY(
     JSON_OBJECT('code', 'airspeed_regime', 'metric', 'ias_kt', 'status', 'planned'),
     JSON_OBJECT('code', 'altitude_heading', 'status', 'planned')
   )), 0, 'v1', 1),
  ('slow_flight', 'commercial_pilot_airplane', 'CA.VII.A', 'Maneuvering During Slow Flight', 'Slow Flight and Stalls',
   JSON_OBJECT('evaluation_enabled', false, 'elements', JSON_ARRAY(
     JSON_OBJECT('code', 'airspeed_regime', 'metric', 'ias_kt', 'status', 'planned'),
     JSON_OBJECT('code', 'altitude_heading', 'status', 'planned')
   )), 0, 'v1', 1),
  ('steep_turn', 'private_pilot_airplane', 'PA.V.A', 'Steep Turns', 'Performance and Ground Reference Maneuvers',
   JSON_OBJECT('evaluation_enabled', false, 'elements', JSON_ARRAY(
     JSON_OBJECT('code', 'bank_target', 'metric', 'bank_deg', 'target_abs', 45, 'tolerance_deg', 5, 'status', 'planned'),
     JSON_OBJECT('code', 'altitude_heading', 'status', 'planned')
   )), 0, 'v1', 1),
  ('steep_turn', 'commercial_pilot_airplane', 'CA.V.A', 'Steep Turns', 'Performance and Ground Reference Maneuvers',
   JSON_OBJECT('evaluation_enabled', false, 'elements', JSON_ARRAY(
     JSON_OBJECT('code', 'bank_target', 'metric', 'bank_deg', 'target_abs', 50, 'tolerance_deg', 5, 'status', 'planned'),
     JSON_OBJECT('code', 'altitude_heading', 'status', 'planned')
   )), 0, 'v1', 1),
  ('unusual_attitude_recovery', 'instrument_rating_airplane', 'IR.IV.A', 'Recovery from Unusual Flight Attitudes', 'Instrument Flight',
   JSON_OBJECT('evaluation_enabled', false, 'elements', JSON_ARRAY(
     JSON_OBJECT('code', 'recognize_recover', 'status', 'planned'),
     JSON_OBJECT('code', 'instrument_scan', 'status', 'planned')
   )), 0, 'v1', 1)
ON DUPLICATE KEY UPDATE
  acs_task_title = VALUES(acs_task_title),
  acs_area_title = VALUES(acs_area_title),
  criteria_json = VALUES(criteria_json),
  evaluation_enabled = VALUES(evaluation_enabled),
  evaluator_version = VALUES(evaluator_version),
  is_active = VALUES(is_active);

-- SOP bindings (instruction/procedure foresight; separate from ACS; organization_id=0 = global template)
INSERT INTO ipca_flight_exercise_sop_bindings
  (exercise_code, organization_id, sop_code, sop_title, instruction_outline_json, criteria_json, evaluation_enabled, evaluator_version, is_active)
VALUES
  ('power_off_stall', 0, 'SOP.EX.POWER_OFF_STALL', 'Power-Off Stall Instruction & Procedure',
   JSON_OBJECT('steps', JSON_ARRAY(
     'Clearing turns / clear area',
     'Configuration brief (approach or landing)',
     'Instructor cue / exercise start',
     'Power reduction and pitch to AOA',
     'Recognition and recovery coaching limits',
     'Reset and debrief cues'
   )),
   JSON_OBJECT('evaluation_enabled', false, 'instruction_elements', JSON_ARRAY(
     JSON_OBJECT('code', 'clear_area_callout', 'observability', 'transcript', 'status', 'planned'),
     JSON_OBJECT('code', 'configuration_brief', 'observability', 'transcript', 'status', 'planned'),
     JSON_OBJECT('code', 'exercise_start_marked', 'observability', 'crew_marker', 'status', 'planned'),
     JSON_OBJECT('code', 'coaching_vs_independent', 'observability', 'transcript', 'status', 'planned')
   ), 'procedure_elements', JSON_ARRAY(
     JSON_OBJECT('code', 'power_set', 'observability', 'telemetry', 'status', 'planned'),
     JSON_OBJECT('code', 'recovery_flow', 'observability', 'telemetry', 'status', 'planned')
   )), 0, 'v1', 1),
  ('power_on_stall', 0, 'SOP.EX.POWER_ON_STALL', 'Power-On Stall Instruction & Procedure',
   JSON_OBJECT('steps', JSON_ARRAY('Clear area', 'Takeoff/departure configuration brief', 'Exercise start', 'Pitch/power entry', 'Recovery', 'Debrief cues')),
   JSON_OBJECT('evaluation_enabled', false, 'instruction_elements', JSON_ARRAY(
     JSON_OBJECT('code', 'configuration_brief', 'status', 'planned'),
     JSON_OBJECT('code', 'exercise_start_marked', 'status', 'planned')
   ), 'procedure_elements', JSON_ARRAY(
     JSON_OBJECT('code', 'power_set', 'status', 'planned'),
     JSON_OBJECT('code', 'recovery_flow', 'status', 'planned')
   )), 0, 'v1', 1),
  ('slow_flight', 0, 'SOP.EX.SLOW_FLIGHT', 'Slow Flight Instruction & Procedure',
   JSON_OBJECT('steps', JSON_ARRAY('Clear area', 'Configuration', 'Entry to slow flight', 'Maneuvering', 'Recovery to cruise')),
   JSON_OBJECT('evaluation_enabled', false, 'instruction_elements', JSON_ARRAY(
     JSON_OBJECT('code', 'entry_coaching', 'status', 'planned')
   ), 'procedure_elements', JSON_ARRAY(
     JSON_OBJECT('code', 'airspeed_hold', 'status', 'planned')
   )), 0, 'v1', 1),
  ('steep_turn', 0, 'SOP.EX.STEEP_TURN', 'Steep Turn Instruction & Procedure',
   JSON_OBJECT('steps', JSON_ARRAY('Clear area', 'Entry brief', 'Left/right turns', 'Rollout cues', 'Debrief')),
   JSON_OBJECT('evaluation_enabled', false, 'instruction_elements', JSON_ARRAY(
     JSON_OBJECT('code', 'entry_brief', 'status', 'planned')
   ), 'procedure_elements', JSON_ARRAY(
     JSON_OBJECT('code', 'bank_altitude', 'status', 'planned')
   )), 0, 'v1', 1),
  ('unusual_attitude_recovery', 0, 'SOP.EX.UA_RECOVERY', 'Unusual Attitude Recovery Instruction & Procedure',
   JSON_OBJECT('steps', JSON_ARRAY('Hood/instrument setup', 'Entry by instructor', 'Recover by instruments', 'Debrief')),
   JSON_OBJECT('evaluation_enabled', false, 'instruction_elements', JSON_ARRAY(
     JSON_OBJECT('code', 'instrument_coaching', 'status', 'planned')
   ), 'procedure_elements', JSON_ARRAY(
     JSON_OBJECT('code', 'recover_sequence', 'status', 'planned')
   )), 0, 'v1', 1)
ON DUPLICATE KEY UPDATE
  sop_title = VALUES(sop_title),
  instruction_outline_json = VALUES(instruction_outline_json),
  criteria_json = VALUES(criteria_json),
  evaluation_enabled = VALUES(evaluation_enabled),
  evaluator_version = VALUES(evaluator_version),
  is_active = VALUES(is_active);
