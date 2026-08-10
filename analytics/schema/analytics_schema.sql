-- Training Effectiveness Analytics schema (SQLite)
-- Completely separate from E-gle production. No writes to source.

PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS etl_run (
  etl_run_id INTEGER PRIMARY KEY AUTOINCREMENT,
  started_at TEXT NOT NULL,
  finished_at TEXT,
  status TEXT NOT NULL,
  source_system TEXT NOT NULL,
  source_database TEXT NOT NULL,
  notes TEXT,
  stats_json TEXT
);

CREATE TABLE IF NOT EXISTS source_record_map (
  map_id INTEGER PRIMARY KEY AUTOINCREMENT,
  etl_run_id INTEGER NOT NULL,
  canonical_table TEXT NOT NULL,
  canonical_id INTEGER NOT NULL,
  source_system TEXT NOT NULL DEFAULT 'egle',
  source_database TEXT NOT NULL,
  source_table TEXT NOT NULL,
  source_pk TEXT NOT NULL,
  source_program_code TEXT,
  UNIQUE(source_system, source_database, source_table, source_pk, canonical_table)
);

CREATE TABLE IF NOT EXISTS dim_curriculum_family (
  curriculum_family_id INTEGER PRIMARY KEY AUTOINCREMENT,
  family_code TEXT NOT NULL UNIQUE,
  family_name TEXT NOT NULL,
  notes TEXT
);

CREATE TABLE IF NOT EXISTS dim_curriculum_version (
  curriculum_version_id INTEGER PRIMARY KEY AUTOINCREMENT,
  curriculum_family_id INTEGER,
  version_code TEXT NOT NULL UNIQUE,
  version_name TEXT NOT NULL,
  generation_order INTEGER,
  is_current INTEGER NOT NULL DEFAULT 0,
  notes TEXT,
  FOREIGN KEY (curriculum_family_id) REFERENCES dim_curriculum_family(curriculum_family_id)
);

CREATE TABLE IF NOT EXISTS dim_program (
  program_id INTEGER PRIMARY KEY AUTOINCREMENT,
  source_program_id INTEGER NOT NULL UNIQUE,
  program_name TEXT NOT NULL,
  source_tracking_table TEXT,
  source_active_raw TEXT,
  location_raw TEXT,
  curriculum_family_id INTEGER,
  curriculum_version_id INTEGER,
  authority_guess TEXT,
  etl_run_id INTEGER,
  FOREIGN KEY (curriculum_family_id) REFERENCES dim_curriculum_family(curriculum_family_id),
  FOREIGN KEY (curriculum_version_id) REFERENCES dim_curriculum_version(curriculum_version_id)
);

CREATE TABLE IF NOT EXISTS dim_student (
  student_id INTEGER PRIMARY KEY AUTOINCREMENT,
  source_user_id INTEGER NOT NULL UNIQUE,
  canonical_student_id INTEGER,
  first_name TEXT,
  last_name TEXT,
  email TEXT,
  dob TEXT,
  phone TEXT,
  nationality TEXT,
  active_until TEXT,
  source_type TEXT,
  etl_run_id INTEGER
);

CREATE TABLE IF NOT EXISTS dim_instructor (
  instructor_id INTEGER PRIMARY KEY AUTOINCREMENT,
  source_user_id INTEGER NOT NULL UNIQUE,
  first_name TEXT,
  last_name TEXT,
  email TEXT,
  active_until TEXT,
  source_type TEXT,
  etl_run_id INTEGER
);

CREATE TABLE IF NOT EXISTS bridge_student_identity (
  bridge_id INTEGER PRIMARY KEY AUTOINCREMENT,
  candidate_group_id INTEGER NOT NULL,
  source_user_id INTEGER NOT NULL,
  match_signals_json TEXT NOT NULL,
  match_score REAL,
  status TEXT NOT NULL DEFAULT 'CANDIDATE', -- CANDIDATE | APPROVED | REJECTED
  reviewed_at TEXT,
  review_notes TEXT,
  UNIQUE(candidate_group_id, source_user_id)
);

CREATE TABLE IF NOT EXISTS dim_stage (
  stage_id INTEGER PRIMARY KEY AUTOINCREMENT,
  source_stage_id INTEGER NOT NULL UNIQUE,
  source_program_id INTEGER,
  stage_name TEXT,
  stage_order INTEGER,
  etl_run_id INTEGER
);

CREATE TABLE IF NOT EXISTS dim_phase (
  phase_id INTEGER PRIMARY KEY AUTOINCREMENT,
  source_phase_id INTEGER NOT NULL UNIQUE,
  source_stage_id INTEGER,
  phase_name TEXT,
  phase_order INTEGER,
  min_dual REAL,
  min_pic REAL,
  min_fnpt REAL,
  min_brief REAL,
  etl_run_id INTEGER
);

CREATE TABLE IF NOT EXISTS dim_mission (
  mission_id INTEGER PRIMARY KEY AUTOINCREMENT,
  source_scenario_id INTEGER NOT NULL UNIQUE,
  source_program_id INTEGER,
  source_stage_id INTEGER,
  source_phase_id INTEGER,
  mission_code TEXT,
  mission_name TEXT,
  mission_order INTEGER,
  source_session_type TEXT, -- FLIGHT/FNPT/LB/SAB raw
  easa_solo_raw TEXT,
  ksa_raw TEXT,
  duration_minutes INTEGER,
  active INTEGER,
  event_class TEXT,          -- proposed classification
  event_class_confidence TEXT,
  event_class_reason TEXT,
  event_class_evidence TEXT,
  etl_run_id INTEGER
);

CREATE TABLE IF NOT EXISTS dim_exercise (
  exercise_id INTEGER PRIMARY KEY AUTOINCREMENT,
  source_exercise_id INTEGER NOT NULL UNIQUE,
  source_scenario_id INTEGER,
  exercise_name_raw TEXT NOT NULL,
  exercise_name_normalized TEXT,
  exercise_order INTEGER,
  exercise_type_raw TEXT, -- '' or TITLE
  required_level_raw TEXT, -- '(PE)' etc or null
  required_level_normalized TEXT, -- DE/EX/PR/PE/UNKNOWN/NONE
  required_level_parse_status TEXT,
  is_title INTEGER NOT NULL DEFAULT 0,
  etl_run_id INTEGER
);

CREATE TABLE IF NOT EXISTS dim_device (
  device_id INTEGER PRIMARY KEY AUTOINCREMENT,
  source_device_id INTEGER NOT NULL UNIQUE,
  device_name TEXT,
  device_type_raw TEXT,
  device_kind_raw TEXT,
  device_location_raw TEXT,
  device_active_raw TEXT,
  etl_run_id INTEGER
);

CREATE TABLE IF NOT EXISTS dim_srm_key (
  srm_key_id INTEGER PRIMARY KEY AUTOINCREMENT,
  raw_key TEXT NOT NULL UNIQUE,
  probable_ui_label TEXT,
  confidence TEXT,
  evidence TEXT,
  notes TEXT
);

CREATE TABLE IF NOT EXISTS fact_training_session (
  session_id INTEGER PRIMARY KEY AUTOINCREMENT,
  etl_run_id INTEGER NOT NULL,
  source_system TEXT NOT NULL DEFAULT 'egle',
  source_database TEXT NOT NULL,
  source_table TEXT NOT NULL,
  source_sctr_id INTEGER NOT NULL,
  source_program_id INTEGER,
  program_id INTEGER,
  curriculum_family_id INTEGER,
  curriculum_version_id INTEGER,
  student_id INTEGER,
  source_student_id INTEGER,
  instructor_id INTEGER,
  source_instructor_id INTEGER,
  mission_id INTEGER,
  source_scenario_id INTEGER,
  device_id INTEGER,
  source_device_id INTEGER,
  session_date TEXT,
  session_date_valid INTEGER NOT NULL DEFAULT 0,
  source_session_type TEXT,
  session_type_normalized TEXT, -- FLIGHT/SIMULATOR/LONG_BRIEFING/UNKNOWN pending confirmation
  dual_hours REAL,
  pic_hours REAL,
  fnpt_hours REAL,
  brief_hours REAL,
  total_training_hours REAL,
  flight_hours REAL,
  sim_hours REAL,
  ground_hours REAL,
  grading_raw TEXT,
  grading_color TEXT,       -- R/Y/G/B/UNKNOWN/BLANK
  grading_completion TEXT,  -- C/I/UNKNOWN/BLANK
  grading_category TEXT,    -- documented mapping
  sctr_next_raw INTEGER,
  sctr_alternative_raw INTEGER,
  sctr_next_is_none INTEGER,
  sctr_alternative_is_none INTEGER,
  sign_inst_date TEXT,
  sign_stud_date TEXT,
  ex_blob_parse_status TEXT,
  srm_blob_parse_status TEXT,
  ksa_blob_parse_status TEXT,
  exercises_graded_count INTEGER,
  exercises_below_required INTEGER,
  exercises_at_required INTEGER,
  exercises_above_required INTEGER,
  exercises_deferred INTEGER,
  required_level_met_session INTEGER,
  narrative_public_present INTEGER,
  narrative_private_present INTEGER,
  -- temporal / progression features
  previous_session_date TEXT,
  days_since_previous_session INTEGER,
  next_session_date TEXT,
  days_until_next_session INTEGER,
  student_session_number INTEGER,
  mission_attempt_number INTEGER,
  cumulative_training_sessions INTEGER,
  cumulative_flight_time REAL,
  cumulative_sim_time REAL,
  cumulative_training_time REAL,
  previous_instructor_id INTEGER,
  instructor_change_indicator INTEGER,
  previous_mission_id INTEGER,
  next_mission_id INTEGER,
  mission_repeated INTEGER,
  same_mission_next_session INTEGER,
  mission_returned_to_later INTEGER,
  qa_class TEXT, -- HIGH_CONFIDENCE / USABLE_WITH_QUALIFICATION / AMBIGUOUS / EXCLUDE
  qa_notes TEXT,
  UNIQUE(source_system, source_database, source_table, source_sctr_id)
);

CREATE TABLE IF NOT EXISTS fact_exercise_attempt (
  exercise_attempt_id INTEGER PRIMARY KEY AUTOINCREMENT,
  etl_run_id INTEGER NOT NULL,
  session_id INTEGER NOT NULL,
  source_table TEXT NOT NULL,
  source_sctr_id INTEGER NOT NULL,
  source_exercise_id INTEGER,
  exercise_id INTEGER,
  student_id INTEGER,
  instructor_id INTEGER,
  mission_id INTEGER,
  program_id INTEGER,
  session_date TEXT,
  exercise_name_raw TEXT,
  required_level_raw TEXT,
  required_level_normalized TEXT,
  achieved_grade_raw TEXT,          -- R/Y/G/B/D/'' 
  achieved_competency_stage TEXT,   -- DE/EX/PR/PE/DEFERRED/UNKNOWN documented mapping for exercise grades
  required_level_met INTEGER,
  required_level_exceeded INTEGER,
  required_level_not_met INTEGER,
  deferred INTEGER,
  exercise_attempt_number INTEGER,
  previous_achieved_grade_raw TEXT,
  subsequent_achieved_grade_raw TEXT,
  exercise_reappeared INTEGER,
  exercise_regressed INTEGER, -- only when documented ordinal supports it
  parse_status TEXT,
  parse_warnings TEXT,
  FOREIGN KEY (session_id) REFERENCES fact_training_session(session_id)
);

CREATE TABLE IF NOT EXISTS fact_srm_attempt (
  srm_attempt_id INTEGER PRIMARY KEY AUTOINCREMENT,
  etl_run_id INTEGER NOT NULL,
  session_id INTEGER NOT NULL,
  source_table TEXT NOT NULL,
  source_sctr_id INTEGER NOT NULL,
  srm_key_raw TEXT NOT NULL,
  srm_value_raw TEXT,
  srm_ui_label TEXT,
  student_id INTEGER,
  instructor_id INTEGER,
  mission_id INTEGER,
  program_id INTEGER,
  session_date TEXT,
  parse_status TEXT,
  FOREIGN KEY (session_id) REFERENCES fact_training_session(session_id)
);

CREATE TABLE IF NOT EXISTS fact_logbook_leg (
  logbook_leg_id INTEGER PRIMARY KEY AUTOINCREMENT,
  etl_run_id INTEGER NOT NULL,
  source_lb_id INTEGER NOT NULL UNIQUE,
  source_tracking_table TEXT,
  source_sctr_id INTEGER,
  session_id INTEGER,
  student_id INTEGER,
  instructor_id INTEGER,
  device_id INTEGER,
  dep TEXT,
  arr TEXT,
  dep_time_unix INTEGER,
  duration_hours REAL,
  landings INTEGER,
  cond_raw TEXT,
  ifr_raw TEXT,
  fnpt_raw TEXT,
  brief_raw TEXT,
  dual_raw TEXT,
  xc_raw TEXT,
  FOREIGN KEY (session_id) REFERENCES fact_training_session(session_id)
);

CREATE TABLE IF NOT EXISTS fact_narrative (
  narrative_id INTEGER PRIMARY KEY AUTOINCREMENT,
  etl_run_id INTEGER NOT NULL,
  session_id INTEGER NOT NULL,
  source_table TEXT NOT NULL,
  source_sctr_id INTEGER NOT NULL,
  student_id INTEGER,
  instructor_id INTEGER,
  comment_type TEXT NOT NULL, -- public | private
  raw_text TEXT NOT NULL,
  text_hash TEXT NOT NULL,
  character_count INTEGER,
  word_count INTEGER,
  UNIQUE(source_table, source_sctr_id, comment_type),
  FOREIGN KEY (session_id) REFERENCES fact_training_session(session_id)
);

CREATE TABLE IF NOT EXISTS qa_exclusion_log (
  exclusion_id INTEGER PRIMARY KEY AUTOINCREMENT,
  etl_run_id INTEGER NOT NULL,
  source_table TEXT,
  source_pk TEXT,
  canonical_table TEXT,
  reason_code TEXT NOT NULL,
  reason_detail TEXT,
  qa_class TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS qa_data_issue (
  issue_id INTEGER PRIMARY KEY AUTOINCREMENT,
  etl_run_id INTEGER NOT NULL,
  severity TEXT NOT NULL,
  issue_code TEXT NOT NULL,
  entity_table TEXT,
  entity_id INTEGER,
  source_table TEXT,
  source_pk TEXT,
  detail TEXT,
  created_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_fts_student_date ON fact_training_session(student_id, session_date);
CREATE INDEX IF NOT EXISTS idx_fts_mission ON fact_training_session(mission_id);
CREATE INDEX IF NOT EXISTS idx_fts_program ON fact_training_session(program_id);
CREATE INDEX IF NOT EXISTS idx_fts_instructor ON fact_training_session(instructor_id);
CREATE INDEX IF NOT EXISTS idx_fea_session ON fact_exercise_attempt(session_id);
CREATE INDEX IF NOT EXISTS idx_fea_exercise ON fact_exercise_attempt(exercise_id);
CREATE INDEX IF NOT EXISTS idx_fea_student_ex ON fact_exercise_attempt(student_id, source_exercise_id, session_date);
CREATE INDEX IF NOT EXISTS idx_fn_hash ON fact_narrative(text_hash);
