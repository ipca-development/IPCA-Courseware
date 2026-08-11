-- Phase 9 shadow production pilot (analytics SQLite)
-- Does not alter production. Does not overwrite Phase 6–8 tables.

CREATE TABLE IF NOT EXISTS phase9_meta (
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL,
  notes TEXT
);

CREATE TABLE IF NOT EXISTS phase9_secret_gate (
  logical_name TEXT PRIMARY KEY,
  usable INTEGER NOT NULL,
  status_json TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS shadow_session (
  shadow_session_id TEXT PRIMARY KEY,
  operational_session_uuid TEXT,
  recording_uid TEXT,
  recording_id INTEGER,
  aircraft TEXT,
  student_id TEXT,
  instructor_id TEXT,
  cohort_mode TEXT, -- LIVE_PRODUCTION|LOCAL_SIMULATION
  evidence_state TEXT,
  evidence_cutoff_timestamp TEXT,
  official_process_untouched INTEGER DEFAULT 1,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS shadow_evidence_state_log (
  log_id INTEGER PRIMARY KEY AUTOINCREMENT,
  shadow_session_id TEXT NOT NULL,
  from_state TEXT,
  to_state TEXT,
  reason TEXT,
  at_timestamp TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS shadow_exercise_attempt (
  shadow_attempt_id TEXT PRIMARY KEY,
  shadow_session_id TEXT NOT NULL,
  operational_session_uuid TEXT,
  source_event_id TEXT,
  canonical_exercise_id TEXT,
  start_timestamp TEXT,
  end_timestamp TEXT,
  t_start_sec REAL,
  t_end_sec REAL,
  start_boundary_source TEXT,
  end_boundary_source TEXT,
  boundary_confidence REAL,
  instructor_device TEXT,
  actual_leg_id TEXT,
  linked_pilot_attempt_id TEXT,
  idempotency_key TEXT UNIQUE,
  review_queue_flags_json TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS shadow_assessment (
  assessment_id TEXT PRIMARY KEY,
  shadow_session_id TEXT NOT NULL,
  shadow_attempt_id TEXT,
  assessment_version INTEGER NOT NULL,
  evidence_cutoff_timestamp TEXT,
  tolerance_pack_version TEXT,
  procedure_pack_version TEXT,
  ai_model_prompt_version TEXT,
  payload_json TEXT,
  generated_at TEXT,
  superseded_by TEXT,
  analysis_version TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS shadow_comparison (
  comparison_id INTEGER PRIMARY KEY AUTOINCREMENT,
  shadow_session_id TEXT,
  shadow_attempt_id TEXT,
  existing_instructor_summary TEXT,
  system_proposal_summary TEXT,
  instructor_after_system_summary TEXT,
  examiner_summary TEXT,
  notes TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS shadow_debrief_claim (
  claim_id TEXT PRIMARY KEY,
  assessment_id TEXT NOT NULL,
  claim_text TEXT NOT NULL,
  supporting_evidence_ids_json TEXT,
  assessment_source TEXT,
  confidence TEXT,
  evidence_completeness TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS shadow_instructor_correction (
  correction_id INTEGER PRIMARY KEY AUTOINCREMENT,
  assessment_id TEXT NOT NULL,
  system_value_json TEXT,
  instructor_value_json TEXT,
  reason_code TEXT,
  narrative TEXT,
  final_human_confirmed_json TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS shadow_workload_event (
  event_id INTEGER PRIMARY KEY AUTOINCREMENT,
  shadow_session_id TEXT,
  instructor_id TEXT,
  event_type TEXT,
  elapsed_ms INTEGER,
  payload_json TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS shadow_workload_summary (
  metric_name TEXT PRIMARY KEY,
  median_value REAL,
  p75_value REAL,
  p90_value REAL,
  n INTEGER,
  unit TEXT,
  notes TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS maneuver_disposition (
  canonical_exercise_id TEXT PRIMARY KEY,
  disposition TEXT, -- APPROVED|APPROVED_WITH_CHANGES|MORE_VALIDATION_REQUIRED|NOT_READY
  rationale TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS phase9_examiner_clinic_status (
  metric_name TEXT PRIMARY KEY,
  metric_value REAL,
  n INTEGER,
  notes TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS phase9_inter_rater (
  dimension TEXT PRIMARY KEY,
  agreement_rate REAL,
  n_pairs INTEGER,
  notes TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS phase9_context_field_class (
  field_name TEXT PRIMARY KEY,
  classification TEXT, -- DISPLAY_BY_DEFAULT|DISPLAY_WHEN_MATERIAL|ANALYTICS_ONLY
  rationale TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS phase9_boundary_source_stats (
  end_boundary_source TEXT PRIMARY KEY,
  n INTEGER,
  share REAL,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS phase9_boundary_review_queue (
  queue_id INTEGER PRIMARY KEY AUTOINCREMENT,
  shadow_attempt_id TEXT,
  flag TEXT,
  detail TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS phase9_recommendation_agreement (
  row_id INTEGER PRIMARY KEY AUTOINCREMENT,
  shadow_session_id TEXT,
  classification TEXT, -- AGREE|PARTIAL_AGREEMENT|DISAGREE|NOT_APPLICABLE|PENDING
  notes TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS phase9_degraded_mode_test (
  test_code TEXT PRIMARY KEY,
  result TEXT,
  observed_behavior TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS phase9_entity_classification (
  entity_name TEXT PRIMARY KEY,
  classification TEXT, -- PRODUCTION_SOURCE_OF_TRUTH|PRODUCTION_DERIVED|ANALYTICS_ONLY|CACHE|HISTORICAL_IMPORT_ONLY
  notes TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS phase9_feature_flag_plan (
  flag_name TEXT PRIMARY KEY,
  intended_initial_state TEXT, -- OFF|SHADOW|PILOT_USERS|ON
  description TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS phase9_readiness_gate (
  gate_code TEXT PRIMARY KEY,
  status TEXT, -- PASS|PASS_WITH_CONDITIONS|FAIL|INSUFFICIENT_EVIDENCE|BLOCKED
  evidence_notes TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS phase9_llm_final_findings (
  finding_name TEXT NOT NULL,
  method TEXT NOT NULL,
  metric_value REAL,
  n INTEGER,
  notes TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL,
  PRIMARY KEY (finding_name, method, analysis_version)
);

CREATE TABLE IF NOT EXISTS phase9_tolerance_rc (
  rc_pack_id TEXT PRIMARY KEY,
  base_pack_id TEXT,
  change_summary TEXT,
  status TEXT, -- PROPOSED|APPROVED|REJECTED|NOT_CREATED
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS phase9_role_visibility (
  audience TEXT NOT NULL,
  data_class TEXT NOT NULL,
  allowed INTEGER,
  notes TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL,
  PRIMARY KEY (audience, data_class, analysis_version)
);
