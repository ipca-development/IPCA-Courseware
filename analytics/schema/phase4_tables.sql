-- Phase 4 analysis result tables (analytics SQLite only)

CREATE TABLE IF NOT EXISTS analysis_meta (
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL,
  notes TEXT
);

CREATE TABLE IF NOT EXISTS analysis_mission_role (
  mission_id INTEGER PRIMARY KEY,
  source_scenario_id INTEGER,
  program_id INTEGER,
  mission_code TEXT,
  mission_name TEXT,
  source_session_type TEXT,
  event_class TEXT,
  mission_role TEXT NOT NULL,
  mission_role_confidence TEXT NOT NULL,
  mission_role_reason TEXT NOT NULL,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS analysis_curriculum_comparison (
  comparison_id INTEGER PRIMARY KEY AUTOINCREMENT,
  family_code TEXT NOT NULL,
  version_a TEXT NOT NULL,
  version_b TEXT NOT NULL,
  metric_name TEXT NOT NULL,
  value_a REAL,
  value_b REAL,
  delta REAL,
  effect_size REAL,
  ci_low REAL,
  ci_high REAL,
  n_a INTEGER,
  n_b INTEGER,
  verdict TEXT,
  confidence TEXT,
  notes TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS analysis_student_trajectory (
  student_id INTEGER NOT NULL,
  program_id INTEGER NOT NULL,
  trajectory_label TEXT NOT NULL,
  sessions INTEGER,
  flight_hours REAL,
  sim_hours REAL,
  calendar_days INTEGER,
  progression_mission_repeats INTEGER,
  exercise_regression_count INTEGER,
  median_gap_days REAL,
  instructor_switches INTEGER,
  pe_stability_rate REAL,
  below_required_rate REAL,
  features_json TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL,
  PRIMARY KEY (student_id, program_id)
);

CREATE TABLE IF NOT EXISTS analysis_training_gap_effect (
  row_id INTEGER PRIMARY KEY AUTOINCREMENT,
  model_name TEXT NOT NULL,
  stratum TEXT,
  predictor TEXT NOT NULL,
  outcome TEXT NOT NULL,
  coefficient REAL,
  odds_ratio REAL,
  ci_low REAL,
  ci_high REAL,
  p_value REAL,
  n INTEGER,
  notes TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS analysis_competency_stability (
  exercise_id INTEGER,
  source_exercise_id INTEGER,
  program_id INTEGER,
  exercise_name TEXT,
  required_level TEXT,
  n_reached_pe INTEGER,
  n_reobserved INTEGER,
  median_days_to_reobs REAL,
  median_sessions_to_reobs REAL,
  stable_pe_rate REAL,
  one_time_regression_rate REAL,
  repeated_regression_rate REAL,
  pe_to_pr_rate REAL,
  pe_to_ex_rate REAL,
  pe_to_de_rate REAL,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS analysis_competency_transition (
  row_id INTEGER PRIMARY KEY AUTOINCREMENT,
  program_id INTEGER,
  from_stage TEXT NOT NULL,
  to_stage TEXT NOT NULL,
  n_transitions INTEGER,
  rate REAL,
  median_exposures_between REAL,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS analysis_exercise_learning_curve (
  exercise_id INTEGER,
  source_exercise_id INTEGER,
  program_id INTEGER,
  required_level TEXT,
  attempt_number INTEGER,
  n_students INTEGER,
  n_exposures INTEGER,
  met_rate REAL,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS analysis_prerequisite_candidate (
  row_id INTEGER PRIMARY KEY AUTOINCREMENT,
  program_id INTEGER,
  exercise_a_id INTEGER,
  exercise_b_id INTEGER,
  exercise_a_name TEXT,
  exercise_b_name TEXT,
  n_students INTEGER,
  effect_size REAL,
  lift REAL,
  support REAL,
  confidence_stat REAL,
  p_value REAL,
  evidence_confidence TEXT,
  notes TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS analysis_codifficulty (
  row_id INTEGER PRIMARY KEY AUTOINCREMENT,
  program_id INTEGER,
  exercise_a_id INTEGER,
  exercise_b_id INTEGER,
  exercise_a_name TEXT,
  exercise_b_name TEXT,
  n_co_difficult INTEGER,
  support REAL,
  lift REAL,
  evidence_confidence TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS analysis_instructor_calibration (
  instructor_id INTEGER PRIMARY KEY,
  source_user_id INTEGER,
  instructor_name TEXT,
  n_sessions INTEGER,
  n_exercise_marks INTEGER,
  pe_rate REAL,
  required_met_rate REAL,
  progression_repeat_rate REAL,
  downstream_problem_rate REAL,
  pattern_signal TEXT,
  pattern_notes TEXT,
  sample_sufficiency TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS analysis_checkpoint_predictor (
  row_id INTEGER PRIMARY KEY AUTOINCREMENT,
  program_id INTEGER,
  predictor_name TEXT NOT NULL,
  effect_size REAL,
  odds_ratio REAL,
  ci_low REAL,
  ci_high REAL,
  p_value REAL,
  n INTEGER,
  notes TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS analysis_program_bottleneck (
  row_id INTEGER PRIMARY KEY AUTOINCREMENT,
  program_id INTEGER,
  program_name TEXT,
  curriculum_version TEXT,
  item_type TEXT,
  item_id INTEGER,
  item_label TEXT,
  metric_name TEXT,
  metric_value REAL,
  n INTEGER,
  confidence TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS analysis_era_metrics (
  year INTEGER NOT NULL,
  program_family TEXT,
  metric_name TEXT NOT NULL,
  metric_value REAL,
  n INTEGER,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS analysis_unexpected_finding (
  finding_id INTEGER PRIMARY KEY AUTOINCREMENT,
  title TEXT NOT NULL,
  magnitude TEXT,
  evidence TEXT,
  n INTEGER,
  confidence TEXT,
  notes TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS analysis_narrative_sample (
  narrative_id INTEGER PRIMARY KEY,
  session_id INTEGER,
  program_id INTEGER,
  sample_stratum TEXT,
  raw_text TEXT,
  text_hash TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS analysis_objective_measurement_candidate (
  exercise_id INTEGER PRIMARY KEY,
  source_exercise_id INTEGER,
  exercise_name TEXT,
  candidate TEXT NOT NULL,
  reason TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_amr_role ON analysis_mission_role(mission_role);
CREATE INDEX IF NOT EXISTS idx_ast_label ON analysis_student_trajectory(trajectory_label);
CREATE INDEX IF NOT EXISTS idx_alc_ex ON analysis_exercise_learning_curve(exercise_id, attempt_number);
