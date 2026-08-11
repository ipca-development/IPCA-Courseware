-- Phase 8 production evidence wiring + debrief validation (analytics SQLite)
-- Does not overwrite Phase 6/7 tables. No production migrations.

CREATE TABLE IF NOT EXISTS phase8_meta (
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL,
  notes TEXT
);

CREATE TABLE IF NOT EXISTS phase8_secret_status (
  logical_name TEXT PRIMARY KEY,
  usable INTEGER NOT NULL,
  status_json TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS canonical_exercise (
  canonical_exercise_id TEXT PRIMARY KEY,
  display_name TEXT NOT NULL,
  family TEXT,
  notes TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS canonical_exercise_source_map (
  map_id INTEGER PRIMARY KEY AUTOINCREMENT,
  canonical_exercise_id TEXT NOT NULL,
  source_system TEXT NOT NULL,
  source_label TEXT NOT NULL,
  source_code TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS phase8_marker_attempt (
  marker_attempt_id TEXT PRIMARY KEY,
  operational_session_id TEXT,
  recording_uid TEXT,
  canonical_exercise_id TEXT,
  source_event_id TEXT,
  instructor_device TEXT,
  actual_leg_id TEXT,
  t_start_sec REAL,
  t_end_sec REAL,
  boundary_source TEXT,
  boundary_confidence REAL,
  linked_pilot_attempt_id TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS phase8_transcript_segment (
  segment_id INTEGER PRIMARY KEY AUTOINCREMENT,
  operational_session_id TEXT,
  recording_uid TEXT,
  marker_attempt_id TEXT,
  t_start_sec REAL,
  t_end_sec REAL,
  speaker TEXT, -- INSTRUCTOR|STUDENT|ATC|UNKNOWN
  text TEXT,
  confidence REAL,
  source_audio_chunk TEXT,
  transcription_model TEXT,
  availability TEXT, -- AVAILABLE|MISSING
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS phase8_ai_intervention_proposal (
  proposal_id INTEGER PRIMARY KEY AUTOINCREMENT,
  marker_attempt_id TEXT,
  operational_session_id TEXT,
  t_sec REAL,
  event_type TEXT,
  evidence_text TEXT,
  confidence REAL,
  model_version TEXT,
  confirmation_status TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS phase8_independence_group (
  group_id TEXT PRIMARY KEY,
  operational_session_id TEXT,
  canonical_exercise_id TEXT,
  attempt_ids_json TEXT,
  final_demonstrated_state TEXT, -- ASSISTED|PROMPTED|INDEPENDENT|NOT_OBSERVED
  system_suggested_independence TEXT,
  suggestion_rationale TEXT,
  instructor_confirmation TEXT, -- PENDING|CONFIRMED|CHANGED
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS procedure_pack (
  procedure_pack_id TEXT PRIMARY KEY,
  version TEXT NOT NULL,
  canonical_exercise_id TEXT NOT NULL,
  source_reference TEXT,
  effective_date TEXT,
  notes TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS procedure_step (
  step_id INTEGER PRIMARY KEY AUTOINCREMENT,
  procedure_pack_id TEXT NOT NULL,
  step_order INTEGER NOT NULL,
  step_code TEXT NOT NULL,
  display_name TEXT,
  required_flag INTEGER,
  conditions_json TEXT,
  timing_window_sec REAL,
  evidence_source TEXT, -- TELEMETRY|RECORDER_EVENT|AUDIO|TRANSCRIPT|INSTRUCTOR|AI_DERIVED|NOT_OBSERVABLE
  manual_only INTEGER DEFAULT 0,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS phase8_procedure_observation (
  observation_id INTEGER PRIMARY KEY AUTOINCREMENT,
  marker_attempt_id TEXT,
  procedure_pack_id TEXT,
  step_code TEXT,
  observed INTEGER, -- 1/0/NULL unknown
  evidence_source TEXT,
  evidence_json TEXT,
  within_timing INTEGER,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS phase8_examiner_review (
  review_id INTEGER PRIMARY KEY AUTOINCREMENT,
  attempt_id TEXT NOT NULL,
  reviewer_id TEXT NOT NULL,
  verdict TEXT, -- CORRECT|PARTIALLY_CORRECT|INCORRECT|INSUFFICIENT_EVIDENCE|PENDING
  reason_codes_json TEXT,
  narrative_notes TEXT,
  reviewed_dimensions_json TEXT,
  reviewed_at TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS phase8_inter_rater (
  metric_name TEXT PRIMARY KEY,
  value REAL,
  n INTEGER,
  notes TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS phase8_tolerance_validation (
  tolerance_pack_id TEXT NOT NULL,
  metric TEXT,
  exercise_code TEXT,
  status TEXT, -- VALIDATED|NEEDS_ADJUSTMENT|NEEDS_CONTEXT_RULE|INSUFFICIENT_EVIDENCE
  reason TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL,
  PRIMARY KEY (tolerance_pack_id, metric, exercise_code, analysis_version)
);

CREATE TABLE IF NOT EXISTS phase8_reference_flight (
  reference_id TEXT PRIMARY KEY,
  operational_session_id TEXT,
  recording_uid TEXT,
  recording_id INTEGER,
  aircraft TEXT,
  debrief_json TEXT,
  evidence_completeness TEXT, -- FULL_EVIDENCE|PARTIAL_EVIDENCE|LIMITED_EVIDENCE
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS phase8_debrief_item (
  item_id INTEGER PRIMARY KEY AUTOINCREMENT,
  reference_id TEXT,
  canonical_exercise_id TEXT,
  priority_rank INTEGER,
  priority_reason TEXT,
  payload_json TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS phase8_recommendation (
  recommendation_id INTEGER PRIMARY KEY AUTOINCREMENT,
  reference_id TEXT,
  canonical_exercise_id TEXT,
  recommendation_text TEXT,
  rule_code TEXT,
  evidence_json TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS phase8_evidence_completeness (
  session_key TEXT PRIMARY KEY,
  garmin_complete INTEGER,
  audio_complete INTEGER,
  transcript_complete INTEGER,
  exercise_markers_complete INTEGER,
  context_complete INTEGER,
  instructor_input_complete INTEGER,
  overall_level TEXT,
  notes TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS phase8_failure_mode_test (
  test_code TEXT PRIMARY KEY,
  result TEXT, -- PASS|FAIL|DEGRADED
  observed_behavior TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS phase8_production_contract (
  entity_name TEXT PRIMARY KEY,
  classification TEXT, -- PRODUCTION_REQUIRED|ANALYTICS_ONLY|DERIVED_CACHE|HISTORICAL_ONLY
  notes TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS phase8_acceptance_gate (
  gate_code TEXT PRIMARY KEY,
  status TEXT, -- OPEN|PASS|FAIL|BLOCKED
  threshold_notes TEXT,
  observed_notes TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS phase8_workload_measurement (
  metric_name TEXT PRIMARY KEY,
  metric_value REAL,
  unit TEXT,
  notes TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS phase8_nlp_reconciliation (
  finding_name TEXT NOT NULL,
  method TEXT NOT NULL,
  metric_value REAL,
  n INTEGER,
  notes TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL,
  PRIMARY KEY (finding_name, method, analysis_version)
);
