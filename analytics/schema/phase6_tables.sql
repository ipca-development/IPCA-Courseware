-- Phase 6 competency evidence model (analytics SQLite)
-- Does not delete Phase 3–5B tables.

CREATE TABLE IF NOT EXISTS phase6_meta (
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL,
  notes TEXT
);

-- Canonical conceptual field registry
CREATE TABLE IF NOT EXISTS competency_architecture_field (
  field_name TEXT PRIMARY KEY,
  conceptual_layer TEXT NOT NULL, -- CURRICULUM_EXPECTATION|OBSERVED_EXECUTION|OBJECTIVE_EVIDENCE|HUMAN_ASSESSMENT|COMPETENCY_STATE|CONTEXT|AI_INTERPRETATION
  purpose TEXT,
  allowed_states_json TEXT,
  provider TEXT,
  capture_mode TEXT, -- AUTO|AUTO_WITH_CONFIRMATION|MANUAL|DERIVED_LATER|HISTORICAL_ONLY
  historical_availability TEXT,
  future_recorder_source TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS independence_state_recommendation (
  recommended_scale_name TEXT NOT NULL,
  states_json TEXT NOT NULL,
  rejected_granularity_json TEXT,
  rationale TEXT,
  separate_intervention_events_json TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS consistency_state_recommendation (
  recommended_scale_name TEXT NOT NULL,
  states_json TEXT NOT NULL,
  min_attempts_for_state INTEGER,
  attempt_repeatability_rule TEXT,
  longitudinal_stability_rule TEXT,
  rationale TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

-- High-value historical NLP population
CREATE TABLE IF NOT EXISTS analysis_phase6_nlp_population (
  narrative_id INTEGER PRIMARY KEY,
  session_id INTEGER,
  text_hash TEXT NOT NULL,
  sample_bucket TEXT NOT NULL,
  program_id INTEGER,
  version_code TEXT,
  session_year INTEGER,
  character_count INTEGER,
  already_extracted INTEGER DEFAULT 0,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS analysis_phase6_narrative_extraction (
  extraction_id INTEGER PRIMARY KEY AUTOINCREMENT,
  narrative_id INTEGER NOT NULL,
  session_id INTEGER,
  text_hash TEXT NOT NULL,
  sample_bucket TEXT,
  extractor TEXT NOT NULL,
  prompt_version TEXT,
  schema_version TEXT,
  model TEXT,
  overall_narrative_tone TEXT,
  assistance_level TEXT,
  independence_mapped TEXT,
  consistency_class TEXT,
  accuracy_quality TEXT,
  learning_response TEXT,
  context_tags_json TEXT,
  context_effect TEXT,
  transfer_interpretation TEXT,
  summary_flags_json TEXT,
  raw_response_json TEXT,
  parse_status TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL,
  UNIQUE(narrative_id, extractor, prompt_version, schema_version, model)
);

CREATE TABLE IF NOT EXISTS analysis_phase6_narrative_evidence (
  evidence_id INTEGER PRIMARY KEY AUTOINCREMENT,
  extraction_id INTEGER NOT NULL,
  narrative_id INTEGER NOT NULL,
  text_hash TEXT,
  evidence_span TEXT,
  observation_polarity TEXT,
  interpretation TEXT,
  competency_dimensions_json TEXT,
  severity TEXT,
  confidence TEXT,
  span_verified INTEGER,
  extractor TEXT NOT NULL,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS analysis_phase6_nlp_qa (
  row_id INTEGER PRIMARY KEY AUTOINCREMENT,
  stratum TEXT,
  metric_name TEXT,
  metric_value REAL,
  n INTEGER,
  notes TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS analysis_phase6_scale_findings (
  finding_name TEXT NOT NULL,
  metric_value REAL,
  n INTEGER,
  population TEXT,
  notes TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL,
  PRIMARY KEY (finding_name, population, analysis_version)
);

CREATE TABLE IF NOT EXISTS analysis_phase6_early_warning_pattern (
  pattern_code TEXT NOT NULL,
  description TEXT,
  n_students INTEGER,
  n_episodes INTEGER,
  later_problem_rate REAL,
  baseline_rate REAL,
  explainable_template TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL,
  PRIMARY KEY (pattern_code, analysis_version)
);

-- Future-facing canonical entities (prototype population from historical where possible)
CREATE TABLE IF NOT EXISTS competency_expectation (
  expectation_id INTEGER PRIMARY KEY AUTOINCREMENT,
  student_id INTEGER,
  program_id INTEGER,
  mission_id INTEGER,
  exercise_id INTEGER,
  source_exercise_id INTEGER,
  curriculum_expected_level TEXT,
  source TEXT,
  session_id INTEGER,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS exercise_attempt_proto (
  attempt_id INTEGER PRIMARY KEY AUTOINCREMENT,
  student_id INTEGER,
  program_id INTEGER,
  session_id INTEGER,
  mission_id INTEGER,
  exercise_id INTEGER,
  source_exercise_id INTEGER,
  attempt_number INTEGER,
  session_date TEXT,
  achieved_grade_raw TEXT,
  required_level TEXT,
  required_met INTEGER,
  independence_state TEXT, -- NOT_OBSERVED historically unless narrative/intervention evidence
  consistency_within_session TEXT,
  objective_quality_state TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS evidence_item (
  evidence_id INTEGER PRIMARY KEY AUTOINCREMENT,
  evidence_source TEXT NOT NULL,
  student_id INTEGER,
  session_id INTEGER,
  narrative_id INTEGER,
  exercise_attempt_id INTEGER,
  timestamp_start TEXT,
  timestamp_end TEXT,
  raw_or_derived TEXT,
  confidence TEXT,
  model_or_algorithm_version TEXT,
  source_reference TEXT,
  payload_json TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS objective_measurement (
  measurement_id INTEGER PRIMARY KEY AUTOINCREMENT,
  evidence_id INTEGER,
  exercise_attempt_id INTEGER,
  metric TEXT NOT NULL,
  actual_value REAL,
  target_value REAL,
  lower_tolerance REAL,
  upper_tolerance REAL,
  unit TEXT,
  within_standard INTEGER,
  severity TEXT,
  source TEXT,
  confidence TEXT,
  time_range TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS context_snapshot (
  context_id INTEGER PRIMARY KEY AUTOINCREMENT,
  session_id INTEGER,
  exercise_attempt_id INTEGER,
  context_json TEXT,
  derivation_mode TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS instructor_intervention (
  intervention_id INTEGER PRIMARY KEY AUTOINCREMENT,
  session_id INTEGER,
  exercise_attempt_id INTEGER,
  event_type TEXT,
  timestamp TEXT,
  reason TEXT,
  severity TEXT,
  confirmation_status TEXT,
  source TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS competency_state (
  state_id INTEGER PRIMARY KEY AUTOINCREMENT,
  student_id INTEGER,
  program_id INTEGER,
  exercise_family TEXT,
  source_exercise_id INTEGER,
  as_of_date TEXT,
  expected_level TEXT,
  observed_independence TEXT,
  observed_quality TEXT,
  observed_consistency TEXT,
  context_summary TEXT,
  trend TEXT,
  confidence TEXT,
  evidence_ids_json TEXT,
  explanation TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS competency_timeline_example (
  example_id INTEGER PRIMARY KEY AUTOINCREMENT,
  example_code TEXT NOT NULL,
  title TEXT,
  student_id INTEGER,
  program_id INTEGER,
  exercise_family TEXT,
  pattern_type TEXT,
  timeline_markdown TEXT,
  evidence_notes TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS cockpit_recorder_contract_field (
  field_name TEXT PRIMARY KEY,
  required INTEGER,
  semantic_type TEXT,
  description TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS automation_opportunity (
  field_name TEXT PRIMARY KEY,
  automation_class TEXT NOT NULL, -- AUTO|AUTO_WITH_CONFIRMATION|MANUAL|DERIVED_LATER
  rationale TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);
