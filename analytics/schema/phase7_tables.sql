-- Phase 7 operational competency pilot (analytics SQLite)
-- Does not modify Phase 6 tables.

CREATE TABLE IF NOT EXISTS phase7_meta (
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL,
  notes TEXT
);

CREATE TABLE IF NOT EXISTS tolerance_pack (
  tolerance_pack_id TEXT PRIMARY KEY,
  version TEXT NOT NULL,
  regulatory_framework TEXT,
  training_program TEXT,
  aircraft_category TEXT,
  aircraft_type TEXT,
  curriculum_version TEXT,
  source_reference TEXT,
  effective_date TEXT,
  notes TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS tolerance_definition (
  definition_id INTEGER PRIMARY KEY AUTOINCREMENT,
  tolerance_pack_id TEXT NOT NULL,
  exercise_code TEXT NOT NULL,
  metric TEXT NOT NULL,
  target REAL,
  minimum REAL,
  maximum REAL,
  unit TEXT,
  applicable_phase TEXT, -- ENTRY|EXECUTION|EXIT|MEASURE|ANY
  applicable_expected_level TEXT, -- DE|EX|PR|PE|ANY
  tolerance_class TEXT, -- CERTIFICATION_STANDARD|TRAINING_EXPECTED
  hard_or_soft TEXT, -- HARD|SOFT
  notes TEXT,
  provenance TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS exercise_state_machine (
  exercise_code TEXT PRIMARY KEY,
  display_name TEXT,
  entry_conditions_json TEXT,
  active_state_json TEXT,
  procedural_sequence_json TEXT,
  measurement_window_json TEXT,
  completion_condition_json TEXT,
  abort_condition_json TEXT,
  marker_authoritative INTEGER DEFAULT 1,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS pilot_flight (
  pilot_flight_id TEXT PRIMARY KEY,
  operational_session_id TEXT,
  source_kind TEXT, -- LOCAL_G3X|GARMIN_VAULT|SYNTHETIC_EDGE
  aircraft_ident TEXT,
  source_path TEXT,
  start_utc TEXT,
  end_utc TEXT,
  sample_count INTEGER,
  student_id TEXT,
  instructor_id TEXT,
  quality_notes TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS pilot_exercise_attempt (
  attempt_id TEXT PRIMARY KEY,
  pilot_flight_id TEXT NOT NULL,
  operational_session_id TEXT,
  actual_leg_id TEXT,
  exercise_code TEXT NOT NULL,
  attempt_number INTEGER,
  boundary_source TEXT, -- INSTRUCTOR_MARKER|TELEMETRY_DERIVED|SYNTHETIC
  t_start_sec REAL,
  t_end_sec REAL,
  start_utc TEXT,
  end_utc TEXT,
  expected_level TEXT,
  entry_ok INTEGER,
  completed INTEGER,
  aborted INTEGER,
  detection_confidence REAL,
  evidence_json TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS pilot_objective_metric (
  metric_id INTEGER PRIMARY KEY AUTOINCREMENT,
  attempt_id TEXT NOT NULL,
  metric TEXT NOT NULL,
  phase TEXT,
  actual_value REAL,
  target_value REAL,
  lower_tolerance REAL,
  upper_tolerance REAL,
  unit TEXT,
  max_deviation REAL,
  avg_deviation REAL,
  time_outside_tolerance_sec REAL,
  pct_within_tolerance REAL,
  within_standard INTEGER,
  tolerance_pack_id TEXT,
  tolerance_class TEXT,
  raw_payload_json TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS pilot_independence_observation (
  observation_id INTEGER PRIMARY KEY AUTOINCREMENT,
  attempt_id TEXT NOT NULL,
  independence_state TEXT NOT NULL, -- ASSISTED|PROMPTED|INDEPENDENT|NOT_OBSERVED
  source TEXT, -- INSTRUCTOR_ONE_TAP|DEFAULT|AI_PROPOSAL_UNCONFIRMED
  captured_at TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS pilot_intervention_event (
  event_id INTEGER PRIMARY KEY AUTOINCREMENT,
  attempt_id TEXT,
  pilot_flight_id TEXT,
  event_type TEXT NOT NULL,
  t_sec REAL,
  reason TEXT,
  source TEXT,
  confirmation_status TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS pilot_context (
  context_id INTEGER PRIMARY KEY AUTOINCREMENT,
  pilot_flight_id TEXT,
  attempt_id TEXT,
  wind_speed_kt REAL,
  wind_direction_deg REAL,
  crosswind_component_kt REAL,
  gust_spread_kt REAL,
  oat_c REAL,
  density_altitude_ft REAL,
  turbulence_proxy REAL,
  airport TEXT,
  runway TEXT,
  day_night TEXT,
  aircraft_ident TEXT,
  training_gap_days REAL,
  flight_phase TEXT,
  atc_environment TEXT,
  exercise_complexity TEXT,
  raw_values_json TEXT,
  derivation_mode TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS pilot_environmental_observation (
  env_id INTEGER PRIMARY KEY AUTOINCREMENT,
  pilot_flight_id TEXT,
  t_sec REAL,
  lat REAL,
  lon REAL,
  oat_c REAL,
  pressure_altitude_ft REAL,
  density_altitude_ft REAL,
  wind_speed_kt REAL,
  wind_direction_deg REAL,
  gps_altitude_ft REAL,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS pilot_competency_state (
  state_id INTEGER PRIMARY KEY AUTOINCREMENT,
  attempt_id TEXT,
  exercise_code TEXT,
  expected_level TEXT,
  independence_state TEXT,
  independence_source TEXT,
  objective_summary_json TEXT,
  consistency_state TEXT,
  attempt_repeatability TEXT,
  longitudinal_stability TEXT,
  context_summary TEXT,
  trend TEXT,
  confidence TEXT,
  explanation TEXT,
  evidence_ids_json TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS pilot_competency_timeline (
  timeline_id INTEGER PRIMARY KEY AUTOINCREMENT,
  exercise_code TEXT,
  subject_key TEXT,
  timeline_markdown TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS pilot_ai_assessment (
  ai_assessment_id INTEGER PRIMARY KEY AUTOINCREMENT,
  attempt_id TEXT,
  assessment_text TEXT,
  supporting_evidence_ids_json TEXT,
  model TEXT,
  prompt_version TEXT,
  confidence TEXT,
  instructor_acceptance TEXT, -- PENDING|ACCEPTED|CORRECTED|REJECTED
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS pilot_ai_prompt_proposal (
  proposal_id INTEGER PRIMARY KEY AUTOINCREMENT,
  attempt_id TEXT,
  pilot_flight_id TEXT,
  t_sec REAL,
  evidence_span TEXT,
  confidence REAL,
  proposal_type TEXT, -- possible_prompt_event
  confirmation_status TEXT, -- UNCONFIRMED|CONFIRMED|REJECTED
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS pilot_expert_review (
  review_id INTEGER PRIMARY KEY AUTOINCREMENT,
  attempt_id TEXT NOT NULL,
  reviewer_role TEXT,
  verdict TEXT, -- CORRECT|PARTIALLY_CORRECT|INCORRECT|PENDING
  discrepancy_notes TEXT,
  reviewed_at TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS pilot_disagreement (
  disagreement_id INTEGER PRIMARY KEY AUTOINCREMENT,
  attempt_id TEXT,
  dimension TEXT,
  system_value TEXT,
  human_value TEXT,
  cause_class TEXT,
  notes TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS pilot_early_warning (
  warning_id INTEGER PRIMARY KEY AUTOINCREMENT,
  subject_key TEXT,
  pattern_code TEXT,
  message TEXT,
  lead_context TEXT,
  useful_flag TEXT, -- UNKNOWN|LIKELY_USEFUL|NOISY|INSUFFICIENT
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS phase7_llm_reconciliation (
  finding_name TEXT NOT NULL,
  method TEXT NOT NULL, -- heuristic_only|llm_only|combined
  metric_value REAL,
  n INTEGER,
  notes TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL,
  PRIMARY KEY (finding_name, method, analysis_version)
);

CREATE TABLE IF NOT EXISTS phase7_recorder_contract_gap (
  field_name TEXT PRIMARY KEY,
  availability TEXT, -- AVAILABLE|PARTIAL|MISSING|UNKNOWN
  evidence TEXT,
  notes TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS phase7_workload_estimate (
  metric_name TEXT PRIMARY KEY,
  metric_value REAL,
  unit TEXT,
  notes TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS phase7_secret_injection_status (
  status TEXT NOT NULL,
  mechanism TEXT,
  llm_processed_n INTEGER,
  llm_remaining_n INTEGER,
  notes TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);
