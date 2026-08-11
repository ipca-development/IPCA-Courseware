-- Phase 10 live shadow validation (analytics SQLite only)
-- No production migrations. Official training state untouched.

CREATE TABLE IF NOT EXISTS phase10_meta (
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL,
  notes TEXT
);

CREATE TABLE IF NOT EXISTS phase10_runtime_status (
  component TEXT PRIMARY KEY,
  status TEXT NOT NULL, -- OK|BLOCKED|DEGRADED|UNKNOWN
  detail TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS phase10_live_cohort (
  operational_session_uuid TEXT PRIMARY KEY,
  recording_id INTEGER,
  recording_uid TEXT,
  aircraft TEXT,
  student_id TEXT,
  instructor_id TEXT,
  session_start TEXT,
  ingest_mode TEXT, -- LIVE_PRODUCTION|BLOCKED_LOCAL
  evidence_completeness TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS phase10_cohort_composition (
  dimension TEXT NOT NULL,
  value TEXT NOT NULL,
  n INTEGER,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL,
  PRIMARY KEY (dimension, value, analysis_version)
);

CREATE TABLE IF NOT EXISTS phase10_boundary_metrics (
  metric_name TEXT PRIMARY KEY,
  metric_value REAL,
  n INTEGER,
  notes TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS phase10_boundary_failure (
  failure_id INTEGER PRIMARY KEY AUTOINCREMENT,
  operational_session_uuid TEXT,
  attempt_ref TEXT,
  failure_class TEXT,
  detail TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS phase10_transcript_quality (
  operational_session_uuid TEXT PRIMARY KEY,
  audio_available INTEGER,
  transcript_available INTEGER,
  quality_class TEXT, -- GOOD|USABLE|LIMITED|UNUSABLE|MISSING
  latency_notes TEXT,
  speaker_notes TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS phase10_prompt_validation (
  metric_name TEXT PRIMARY KEY,
  metric_value REAL,
  n INTEGER,
  notes TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS phase10_metric_validation (
  metric_name TEXT PRIMARY KEY,
  metric_value REAL,
  n INTEGER,
  notes TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS phase10_clinic_completion (
  metric_name TEXT PRIMARY KEY,
  metric_value REAL,
  n INTEGER,
  notes TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS phase10_inter_rater (
  dimension TEXT PRIMARY KEY,
  agreement_rate REAL,
  n_pairs INTEGER,
  notes TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS phase10_maneuver_verdict (
  canonical_exercise_id TEXT PRIMARY KEY,
  verdict TEXT, -- VALIDATED_FOR_SHADOW|VALIDATED_FOR_INSTRUCTOR_ASSIST|NEEDS_REVISION|INSUFFICIENT_EVIDENCE|NOT_SUITABLE_FOR_AUTOMATION
  rationale TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS phase10_tolerance_disposition (
  pack_id TEXT NOT NULL,
  metric TEXT,
  disposition TEXT,
  mismatch_class TEXT,
  notes TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL,
  PRIMARY KEY (pack_id, metric, analysis_version)
);

CREATE TABLE IF NOT EXISTS phase10_procedure_step_observability (
  pack_id TEXT NOT NULL,
  step_code TEXT NOT NULL,
  observability TEXT, -- AUTO_RELIABLE|AUTO_PARTIAL|TRANSCRIPT_SUPPORTED|INSTRUCTOR_REQUIRED|NOT_OBSERVABLE
  notes TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL,
  PRIMARY KEY (pack_id, step_code, analysis_version)
);

CREATE TABLE IF NOT EXISTS phase10_outcome_process_case (
  case_id INTEGER PRIMARY KEY AUTOINCREMENT,
  pattern TEXT,
  example_ref TEXT,
  notes TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS phase10_independence_metrics (
  metric_name TEXT PRIMARY KEY,
  metric_value REAL,
  n INTEGER,
  notes TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS phase10_workload_live (
  metric_name TEXT NOT NULL,
  segment TEXT NOT NULL,
  median_value REAL,
  p75_value REAL,
  p90_value REAL,
  min_value REAL,
  max_value REAL,
  n INTEGER,
  notes TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL,
  PRIMARY KEY (metric_name, segment, analysis_version)
);

CREATE TABLE IF NOT EXISTS phase10_exception_snr (
  rating TEXT PRIMARY KEY, -- USEFUL|NEUTRAL|NOISY|WRONG|PENDING
  n INTEGER,
  notes TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS phase10_claim_validation (
  metric_name TEXT PRIMARY KEY,
  metric_value REAL,
  n INTEGER,
  notes TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS phase10_ai_unsupported (
  metric_name TEXT PRIMARY KEY,
  metric_value REAL,
  n INTEGER,
  notes TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS phase10_debrief_quality (
  dimension TEXT PRIMARY KEY,
  score_notes TEXT,
  n INTEGER,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS phase10_recommendation_live (
  classification TEXT PRIMARY KEY, -- AGREE|PARTIAL|DISAGREE|NOT_APPLICABLE|PENDING
  n INTEGER,
  notes TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS phase10_early_warning_live (
  pattern_code TEXT PRIMARY KEY,
  useful_flag TEXT,
  notes TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS phase10_degraded_live (
  case_code TEXT PRIMARY KEY,
  observed INTEGER,
  result TEXT,
  notes TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS phase10_schema_review (
  entity_or_column TEXT PRIMARY KEY,
  disposition TEXT, -- KEEP|CHANGE|DROP|ANALYTICS_ONLY
  notes TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS phase10_feature_flag_plan (
  flag_name TEXT PRIMARY KEY,
  phase10_state TEXT, -- must be OFF unless separately instructed
  post_gate_intended TEXT,
  notes TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS phase10_exit_gate (
  gate_code TEXT PRIMARY KEY,
  status TEXT, -- PASS|PASS_WITH_CONDITIONS|FAIL|INSUFFICIENT_EVIDENCE|BLOCKED
  evidence_notes TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS phase10_overall_verdict (
  verdict TEXT NOT NULL,
  rationale TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS phase10_llm_status (
  status TEXT NOT NULL,
  hashes_done INTEGER,
  hashes_remaining INTEGER,
  notes TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS phase10_pilot_instructor_criteria (
  criterion TEXT PRIMARY KEY,
  required INTEGER,
  notes TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS phase10_context_materiality (
  context_field TEXT PRIMARY KEY,
  materiality_class TEXT, -- DEBRIEF_DEFAULT|DEBRIEF_WHEN_MATERIAL|INSTRUCTOR_ONLY|ANALYTICS_ONLY
  notes TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS phase10_longitudinal_case (
  case_id INTEGER PRIMARY KEY AUTOINCREMENT,
  pattern TEXT,
  example_ref TEXT,
  system_vs_instructor TEXT,
  notes TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS phase10_migration_gate (
  gate_name TEXT PRIMARY KEY,
  met INTEGER,
  notes TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);
