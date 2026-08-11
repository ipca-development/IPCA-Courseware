-- Phase 10C live validation closure (analytics SQLite only)
-- No production migrations. Architecture freeze — validation tables only.

CREATE TABLE IF NOT EXISTS phase10c_meta (
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL,
  notes TEXT
);

CREATE TABLE IF NOT EXISTS phase10c_cohort_freeze (
  freeze_id TEXT PRIMARY KEY,
  frozen_at TEXT NOT NULL,
  selection_rule TEXT NOT NULL,
  session_count INTEGER,
  student_count INTEGER,
  instructor_count INTEGER,
  aircraft_count INTEGER,
  attempt_count INTEGER,
  date_start TEXT,
  date_end TEXT,
  analysis_version TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS phase10c_cohort_session (
  freeze_id TEXT NOT NULL,
  operational_session_uuid TEXT NOT NULL,
  recording_id INTEGER,
  recording_uid TEXT,
  aircraft TEXT,
  student_id TEXT,
  instructor_id TEXT,
  program_code TEXT,
  session_start TEXT,
  source_class TEXT NOT NULL, -- LIVE_PRODUCTION_SHADOW only in freeze
  evidence_completeness TEXT,
  marker_count INTEGER,
  transcription_status TEXT,
  audio_available INTEGER,
  transcript_available INTEGER,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL,
  PRIMARY KEY (freeze_id, operational_session_uuid)
);

CREATE TABLE IF NOT EXISTS phase10c_cohort_composition (
  freeze_id TEXT NOT NULL,
  dimension TEXT NOT NULL,
  value TEXT NOT NULL,
  n INTEGER,
  analysis_version TEXT NOT NULL,
  PRIMARY KEY (freeze_id, dimension, value)
);

CREATE TABLE IF NOT EXISTS phase10c_source_partition (
  source_class TEXT PRIMARY KEY, -- LIVE_PRODUCTION_SHADOW|LOCAL_SIMULATION|CONTROLLED_FIXTURE|HISTORICAL_ANALYTICS
  n_sessions INTEGER,
  notes TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

-- Dimensional clinic reviews (extends phase8; does not fabricate)
CREATE TABLE IF NOT EXISTS phase10c_clinic_review (
  attempt_id TEXT NOT NULL,
  reviewer_id TEXT NOT NULL,
  reviewed_at TEXT,
  exercise_id TEXT,
  boundary_verdict TEXT, -- CORRECT|PARTIALLY_CORRECT|INCORRECT|INSUFFICIENT_EVIDENCE|PENDING
  objective_verdict TEXT,
  tolerance_verdict TEXT,
  procedure_verdict TEXT,
  independence_verdict TEXT,
  consistency_verdict TEXT,
  overall_verdict TEXT,
  reason_codes_json TEXT,
  narrative_notes TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL,
  PRIMARY KEY (attempt_id, reviewer_id)
);

CREATE TABLE IF NOT EXISTS phase10c_clinic_progress (
  metric_name TEXT PRIMARY KEY,
  metric_value REAL,
  n INTEGER,
  notes TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS phase10c_inter_rater (
  dimension TEXT PRIMARY KEY,
  raw_agreement REAL,
  agreement_excl_ie REAL,
  n_pairs INTEGER,
  n_pairs_excl_ie INTEGER,
  chance_corrected REAL,
  notes TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS phase10c_adjudication_queue (
  queue_id INTEGER PRIMARY KEY AUTOINCREMENT,
  attempt_id TEXT NOT NULL,
  disagreement_class TEXT,
  examiner_a_json TEXT,
  examiner_b_json TEXT,
  status TEXT, -- OPEN|RESOLVED|DEFERRED
  notes TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS phase10c_maneuver_disposition (
  canonical_exercise_id TEXT PRIMARY KEY,
  live_attempt_count INTEGER,
  reviewed_attempt_count INTEGER,
  disposition TEXT, -- VALIDATED_FOR_INSTRUCTOR_ASSIST|VALIDATED_FOR_SHADOW_ONLY|NEEDS_REVISION|INSUFFICIENT_EVIDENCE|NOT_SUITABLE_FOR_AUTOMATION
  boundary_notes TEXT,
  metric_notes TEXT,
  tolerance_notes TEXT,
  procedure_notes TEXT,
  claim_notes TEXT,
  rationale TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS phase10c_tolerance_rule (
  pack_id TEXT NOT NULL,
  metric TEXT NOT NULL,
  disposition TEXT,
  mismatch_class TEXT, -- WRONG_TOLERANCE|WRONG_LEVEL_APPLICABILITY|WRONG_BOUNDARY|WRONG_METRIC|CONTEXT_REQUIRED|HUMAN_JUDGMENT|OTHER|PENDING_CLINIC
  notes TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL,
  PRIMARY KEY (pack_id, metric, analysis_version)
);

CREATE TABLE IF NOT EXISTS phase10c_tolerance_version_change (
  change_id INTEGER PRIMARY KEY AUTOINCREMENT,
  pack_id_old TEXT,
  pack_id_new TEXT,
  metric TEXT,
  old_value TEXT,
  new_value TEXT,
  reason TEXT,
  supporting_cases_json TEXT,
  approval TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS phase10c_transcript_quality (
  operational_session_uuid TEXT PRIMARY KEY,
  audio_available INTEGER,
  transcript_present INTEGER,
  transcript_useful INTEGER, -- 0/1 provisional; human may override
  quality_class TEXT, -- GOOD|USABLE|LIMITED|UNUSABLE|MISSING
  classification_source TEXT, -- SYSTEM_PROVISIONAL|HUMAN_REVIEW
  latency_notes TEXT,
  speaker_notes TEXT,
  aircraft TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS phase10c_transcript_feature_req (
  feature_name TEXT PRIMARY KEY,
  min_quality TEXT, -- GOOD|USABLE|LIMITED
  notes TEXT,
  analysis_version TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS phase10c_prompt_detection (
  metric_name TEXT PRIMARY KEY,
  metric_value REAL,
  n INTEGER,
  readiness TEXT, -- AUTO|AUTO_WITH_CONFIRMATION|SHADOW_ONLY|DISABLE|INSUFFICIENT_EVIDENCE
  notes TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS phase10c_workload (
  segment TEXT NOT NULL,
  n INTEGER,
  median_min REAL,
  p75_min REAL,
  p90_min REAL,
  min_min REAL,
  max_min REAL,
  notes TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL,
  PRIMARY KEY (segment, analysis_version)
);

CREATE TABLE IF NOT EXISTS phase10c_workload_reason (
  reason_code TEXT PRIMARY KEY,
  n INTEGER,
  notes TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS phase10c_exception_snr (
  rating TEXT PRIMARY KEY,
  n INTEGER,
  notes TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS phase10c_claim_review (
  claim_id TEXT PRIMARY KEY,
  claim_type TEXT,
  support_class TEXT, -- FULLY_SUPPORTED|PARTIALLY_SUPPORTED|UNSUPPORTED|MISLEADING|PENDING
  notes TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS phase10c_claim_rate (
  claim_type TEXT NOT NULL,
  support_class TEXT NOT NULL,
  n INTEGER,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL,
  PRIMARY KEY (claim_type, support_class, analysis_version)
);

CREATE TABLE IF NOT EXISTS phase10c_debrief_acceptance (
  shadow_session_id TEXT PRIMARY KEY,
  acceptance TEXT, -- ACCEPT|ACCEPT_WITH_MINOR_EDITS|MAJOR_CORRECTION|REJECT|INSUFFICIENT_EVIDENCE|PENDING
  reason TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS phase10c_system_human (
  record_id INTEGER PRIMARY KEY AUTOINCREMENT,
  entity_ref TEXT,
  system_proposal_json TEXT,
  human_correction_json TEXT,
  final_human_confirmed_json TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS phase10c_procedure_pack (
  pack_id TEXT PRIMARY KEY,
  disposition TEXT, -- VALIDATED|VALIDATED_WITH_LIMITATIONS|NEEDS_REVISION|INSUFFICIENT_EVIDENCE
  notes TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS phase10c_procedure_step (
  pack_id TEXT NOT NULL,
  step_code TEXT NOT NULL,
  observability TEXT,
  notes TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL,
  PRIMARY KEY (pack_id, step_code)
);

CREATE TABLE IF NOT EXISTS phase10c_case_study (
  case_id INTEGER PRIMARY KEY AUTOINCREMENT,
  pattern TEXT,
  example_ref TEXT,
  source_class TEXT,
  notes TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS phase10c_llm_progress (
  eligible INTEGER,
  already_cached INTEGER,
  processed INTEGER,
  successful INTEGER,
  failed INTEGER,
  remaining INTEGER,
  job_status TEXT, -- RUNNING|COMPLETED|FAILED|STOPPED|NOT_STARTED
  notes TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS phase10c_llm_reconciliation (
  finding_code TEXT PRIMARY KEY,
  classification TEXT, -- CONFIRMED|REVISED|NOT_CONFIRMED|PENDING
  notes TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS phase10c_degraded (
  case_code TEXT PRIMARY KEY,
  observed INTEGER,
  source_class TEXT, -- LIVE_PRODUCTION_SHADOW|CONTROLLED_FIXTURE
  result TEXT,
  notes TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS phase10c_exit_gate (
  gate_code TEXT PRIMARY KEY,
  status TEXT,
  evidence_notes TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS phase10c_overall_verdict (
  verdict TEXT NOT NULL,
  rationale TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS phase10c_blocker (
  blocker_id INTEGER PRIMARY KEY AUTOINCREMENT,
  gate_code TEXT,
  why TEXT,
  required_action TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);
