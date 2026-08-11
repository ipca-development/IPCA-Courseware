-- Phase 5B LLM validation tables (do not delete phase5-v1)

CREATE TABLE IF NOT EXISTS analysis_phase5_extractor_comparison (
  row_id INTEGER PRIMARY KEY AUTOINCREMENT,
  narrative_id INTEGER NOT NULL,
  metric_name TEXT NOT NULL,
  heuristic_value TEXT,
  llm_value TEXT,
  agreement INTEGER,
  notes TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS analysis_phase5_extractor_summary (
  metric_name TEXT NOT NULL,
  heuristic_rate REAL,
  llm_rate REAL,
  agreement_rate REAL,
  n INTEGER,
  interpretation TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL,
  PRIMARY KEY (metric_name, analysis_version)
);

CREATE TABLE IF NOT EXISTS analysis_phase5_human_validation (
  narrative_id INTEGER NOT NULL,
  field_name TEXT NOT NULL,
  ground_truth TEXT,
  heuristic_value TEXT,
  llm_value TEXT,
  heuristic_correct INTEGER,
  llm_correct INTEGER,
  notes TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL,
  PRIMARY KEY (narrative_id, field_name, analysis_version)
);

CREATE TABLE IF NOT EXISTS analysis_phase5_human_validation_metrics (
  field_name TEXT NOT NULL,
  extractor TEXT NOT NULL,
  n INTEGER,
  precision_est REAL,
  recall_est REAL,
  f1_est REAL,
  unsupported_rate REAL,
  miss_rate REAL,
  incorrect_rate REAL,
  notes TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL,
  PRIMARY KEY (field_name, extractor, analysis_version)
);

CREATE TABLE IF NOT EXISTS analysis_phase5_mismatch_llm (
  extractor TEXT NOT NULL,
  agreement_category TEXT NOT NULL,
  n INTEGER,
  rate REAL,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL,
  PRIMARY KEY (extractor, agreement_category, analysis_version)
);

CREATE TABLE IF NOT EXISTS analysis_phase5_dimension_validation (
  dimension TEXT NOT NULL,
  extractability TEXT NOT NULL,
  sample_frequency REAL,
  usable_evidence_rate REAL,
  incremental_beyond_grade TEXT,
  downstream_association TEXT,
  overlap_notes TEXT,
  recorder_measurability TEXT,
  instructor_burden TEXT,
  decision TEXT NOT NULL,
  reason TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL,
  PRIMARY KEY (dimension, analysis_version)
);

CREATE TABLE IF NOT EXISTS analysis_phase5_model_comparison (
  model_code TEXT NOT NULL,
  description TEXT,
  information_gain TEXT,
  predictive_usefulness TEXT,
  interpretability TEXT,
  collection_burden TEXT,
  historical_compatibility TEXT,
  recorder_compatibility TEXT,
  recommendation TEXT,
  evidence_notes TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL,
  PRIMARY KEY (model_code, analysis_version)
);

CREATE TABLE IF NOT EXISTS analysis_phase5_bulk_nlp_decision (
  decision TEXT NOT NULL,
  eligible_narratives INTEGER,
  unique_hashes INTEGER,
  recommended_scope_n INTEGER,
  scope_definition_json TEXT,
  avg_input_chars REAL,
  est_input_tokens INTEGER,
  est_output_tokens INTEGER,
  est_total_tokens INTEGER,
  est_batch_count INTEGER,
  est_runtime_hours_low REAL,
  est_runtime_hours_high REAL,
  cache_keys TEXT,
  rationale TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS analysis_phase5_final_architecture (
  field_name TEXT NOT NULL,
  purpose TEXT,
  allowed_states_json TEXT,
  provider TEXT,
  entry_mode TEXT,
  historical_availability TEXT,
  future_recorder_source TEXT,
  retained INTEGER NOT NULL,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL,
  PRIMARY KEY (field_name, analysis_version)
);

CREATE TABLE IF NOT EXISTS analysis_phase5b_meta (
  analysis_version TEXT NOT NULL,
  llm_extractor TEXT,
  heuristic_extractor TEXT,
  generated_at TEXT NOT NULL,
  notes TEXT
);
