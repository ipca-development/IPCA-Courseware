-- Phase 5 evaluation-model research tables (analytics SQLite only)

CREATE TABLE IF NOT EXISTS analysis_narrative_sample_enriched (
  narrative_id INTEGER PRIMARY KEY,
  session_id INTEGER NOT NULL,
  sample_stratum TEXT NOT NULL,
  text_hash TEXT NOT NULL,
  raw_text TEXT NOT NULL,
  character_count INTEGER,
  student_id INTEGER,
  instructor_id INTEGER,
  program_id INTEGER,
  curriculum_version_id INTEGER,
  curriculum_family_id INTEGER,
  version_code TEXT,
  family_code TEXT,
  program_name TEXT,
  mission_id INTEGER,
  mission_code TEXT,
  mission_name TEXT,
  mission_role TEXT,
  session_date TEXT,
  session_year INTEGER,
  grading_raw TEXT,
  grading_color TEXT,
  grading_completion TEXT,
  exercises_below_required INTEGER,
  mission_attempt_number INTEGER,
  trajectory_label TEXT,
  pe_stability_proxy REAL,
  later_regression_flag INTEGER,
  later_repeat_flag INTEGER,
  later_checkpoint_problem_flag INTEGER,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS analysis_narrative_extraction (
  extraction_id INTEGER PRIMARY KEY AUTOINCREMENT,
  narrative_id INTEGER NOT NULL,
  session_id INTEGER NOT NULL,
  text_hash TEXT NOT NULL,
  sample_stratum TEXT,
  overall_narrative_tone TEXT,
  assistance_level TEXT,
  assistance_reason TEXT,
  assistance_context TEXT,
  assistance_improved_after TEXT,
  consistency_class TEXT,
  learning_response TEXT,
  accuracy_quality TEXT,
  context_tags_json TEXT,
  context_effect TEXT,
  transfer_interpretation TEXT,
  missing_middle_states_json TEXT,
  measurable_deviations_json TEXT,
  summary_flags_json TEXT,
  raw_response_json TEXT,
  llm_model TEXT NOT NULL,
  prompt_version TEXT NOT NULL,
  extraction_version TEXT NOT NULL,
  parse_status TEXT NOT NULL,
  parse_warnings TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL,
  UNIQUE(narrative_id, extraction_version)
);

CREATE TABLE IF NOT EXISTS analysis_narrative_evidence (
  evidence_id INTEGER PRIMARY KEY AUTOINCREMENT,
  extraction_id INTEGER NOT NULL,
  narrative_id INTEGER NOT NULL,
  text_hash TEXT NOT NULL,
  evidence_span TEXT NOT NULL,
  observation_polarity TEXT NOT NULL,
  interpretation TEXT,
  competency_dimensions_json TEXT NOT NULL,
  severity TEXT,
  confidence TEXT,
  span_verified INTEGER,
  llm_model TEXT NOT NULL,
  prompt_version TEXT NOT NULL,
  extraction_version TEXT NOT NULL,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS analysis_narrative_validation (
  validation_id INTEGER PRIMARY KEY AUTOINCREMENT,
  narrative_id INTEGER NOT NULL,
  extraction_id INTEGER,
  validation_stratum TEXT,
  in_human_validation_set INTEGER NOT NULL DEFAULT 0,
  original_narrative TEXT,
  structured_grade_raw TEXT,
  llm_evidence_json TEXT,
  llm_dimensions_json TEXT,
  llm_assistance TEXT,
  llm_consistency TEXT,
  llm_context_tags_json TEXT,
  llm_confidence_notes TEXT,
  human_review_status TEXT,
  unsupported_extraction_flag INTEGER,
  missed_deficiency_flag INTEGER,
  missed_positive_flag INTEGER,
  incorrect_dimension_flag INTEGER,
  incorrect_assistance_flag INTEGER,
  incorrect_consistency_flag INTEGER,
  incorrect_context_flag INTEGER,
  review_notes TEXT,
  reviewer TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS analysis_narrative_grade_agreement (
  row_id INTEGER PRIMARY KEY AUTOINCREMENT,
  narrative_id INTEGER NOT NULL,
  session_id INTEGER NOT NULL,
  agreement_category TEXT NOT NULL,
  program_id INTEGER,
  version_code TEXT,
  instructor_id INTEGER,
  session_year INTEGER,
  grading_raw TEXT,
  grading_color TEXT,
  assistance_level TEXT,
  consistency_class TEXT,
  notes TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS analysis_assistance_outcomes (
  row_id INTEGER PRIMARY KEY AUTOINCREMENT,
  group_name TEXT NOT NULL,
  n INTEGER,
  later_regression_rate REAL,
  later_repeat_rate REAL,
  later_checkpoint_problem_rate REAL,
  pe_stability_proxy_mean REAL,
  notes TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS analysis_consistency_outcomes (
  row_id INTEGER PRIMARY KEY AUTOINCREMENT,
  group_name TEXT NOT NULL,
  n INTEGER,
  later_regression_rate REAL,
  later_repeat_rate REAL,
  later_checkpoint_problem_rate REAL,
  pe_stability_proxy_mean REAL,
  notes TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS analysis_context_transfer (
  row_id INTEGER PRIMARY KEY AUTOINCREMENT,
  narrative_id INTEGER,
  session_id INTEGER,
  interpretation TEXT NOT NULL,
  context_tags_json TEXT,
  context_effect TEXT,
  later_regression_flag INTEGER,
  notes TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS analysis_dimension_value (
  dimension TEXT PRIMARY KEY,
  sample_frequency REAL,
  n_evidence INTEGER,
  reliability TEXT,
  incremental_predictive_value TEXT,
  overlap TEXT,
  recorder_measurability TEXT,
  instructor_burden TEXT,
  recommendation TEXT NOT NULL,
  reason TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS analysis_evaluation_model_candidate (
  config_code TEXT PRIMARY KEY,
  description TEXT,
  dimensions_json TEXT,
  explanatory_gain TEXT,
  instructor_burden TEXT,
  recommendation TEXT,
  evidence_notes TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS analysis_future_competency_measurement (
  dimension TEXT PRIMARY KEY,
  measurement_class TEXT NOT NULL,
  possible_data_sources TEXT,
  confidence TEXT,
  limitations TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS analysis_bulk_nlp_recommendation (
  decision TEXT NOT NULL,
  total_narratives INTEGER,
  eligible_narratives INTEGER,
  unique_hashes INTEGER,
  expected_llm_calls INTEGER,
  expected_token_volume_estimate INTEGER,
  filters_json TEXT,
  rationale TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS analysis_phase5_meta (
  analysis_version TEXT NOT NULL,
  prompt_version TEXT,
  extraction_version TEXT,
  llm_model TEXT,
  generated_at TEXT NOT NULL,
  notes TEXT
);
