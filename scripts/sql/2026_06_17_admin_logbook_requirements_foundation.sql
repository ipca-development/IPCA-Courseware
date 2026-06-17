-- IPCA Flight Training Admin Logbook + Requirements Engine foundation.
-- MVP scope: structured logbook rows, uploaded source pages, totals/variables cache,
-- requirement categories, requirement assignments, evaluations, and audit trail.
-- Re-run safe: CREATE TABLE IF NOT EXISTS plus idempotent seed inserts.

CREATE TABLE IF NOT EXISTS ipca_admin_logbooks (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  student_user_id INT NULL,
  cohort_id       INT NULL,
  created_by      INT NULL,
  status          VARCHAR(32) NOT NULL DEFAULT 'active' COMMENT 'active | archived',
  source_type     VARCHAR(32) NOT NULL DEFAULT 'admin' COMMENT 'admin | image_import | mixed',
  metadata_json   JSON NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_ipca_admin_logbooks_student_cohort (student_user_id, cohort_id),
  KEY idx_ipca_admin_logbooks_student (student_user_id),
  KEY idx_ipca_admin_logbooks_cohort (cohort_id),
  KEY idx_ipca_admin_logbooks_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Flight Training - structured admin logbooks per student.';

CREATE TABLE IF NOT EXISTS ipca_admin_logbook_pages (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  logbook_id        BIGINT UNSIGNED NOT NULL,
  page_number       INT NOT NULL DEFAULT 1,
  image_url         VARCHAR(512) NOT NULL,
  original_filename VARCHAR(255) NULL,
  mime_type         VARCHAR(96) NULL,
  extraction_status VARCHAR(32) NOT NULL DEFAULT 'manual' COMMENT 'manual | pending | extracted | reviewed',
  extracted_json    JSON NULL,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ipca_logbook_pages_logbook_page (logbook_id, page_number),
  CONSTRAINT fk_ipca_logbook_pages_logbook
    FOREIGN KEY (logbook_id) REFERENCES ipca_admin_logbooks (id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Flight Training - original uploaded logbook page images.';

CREATE TABLE IF NOT EXISTS ipca_admin_logbook_entries (
  id                            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  logbook_id                    BIGINT UNSIGNED NOT NULL,
  source_page_id                BIGINT UNSIGNED NULL,
  entry_date                    DATE NULL,
  departure_airport             VARCHAR(16) NULL,
  departure_time                TIME NULL,
  arrival_airport               VARCHAR(16) NULL,
  arrival_time                  TIME NULL,
  aircraft_type                 VARCHAR(64) NULL,
  aircraft_registration         VARCHAR(32) NULL,
  single_engine_time            DECIMAL(8,2) NOT NULL DEFAULT 0.00,
  multi_engine_time             DECIMAL(8,2) NOT NULL DEFAULT 0.00,
  pic_time                      DECIMAL(8,2) NOT NULL DEFAULT 0.00,
  copilot_time                  DECIMAL(8,2) NOT NULL DEFAULT 0.00,
  dual_received_time            DECIMAL(8,2) NOT NULL DEFAULT 0.00,
  instructor_time               DECIMAL(8,2) NOT NULL DEFAULT 0.00,
  solo_time                     DECIMAL(8,2) NOT NULL DEFAULT 0.00,
  cross_country_time            DECIMAL(8,2) NOT NULL DEFAULT 0.00,
  cross_country_distance_nm     DECIMAL(8,1) NOT NULL DEFAULT 0.0,
  night_time                    DECIMAL(8,2) NOT NULL DEFAULT 0.00,
  instrument_time               DECIMAL(8,2) NOT NULL DEFAULT 0.00,
  actual_instrument_time        DECIMAL(8,2) NOT NULL DEFAULT 0.00,
  simulated_instrument_time     DECIMAL(8,2) NOT NULL DEFAULT 0.00,
  basic_instrument_flying_time  DECIMAL(8,2) NOT NULL DEFAULT 0.00,
  day_landings                  INT NOT NULL DEFAULT 0,
  night_landings                INT NOT NULL DEFAULT 0,
  towered_airport_landings      INT NOT NULL DEFAULT 0,
  total_flight_time             DECIMAL(8,2) NOT NULL DEFAULT 0.00,
  instructor_name               VARCHAR(255) NULL,
  remarks                       TEXT NULL,
  endorsements                  TEXT NULL,
  review_status                 VARCHAR(32) NOT NULL DEFAULT 'ok' COMMENT 'ok | flagged | merged | split | deleted',
  metadata_json                 JSON NULL,
  created_at                    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at                    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_ipca_logbook_entries_logbook_date (logbook_id, entry_date),
  KEY idx_ipca_logbook_entries_page (source_page_id),
  KEY idx_ipca_logbook_entries_review (logbook_id, review_status),
  CONSTRAINT fk_ipca_logbook_entries_logbook
    FOREIGN KEY (logbook_id) REFERENCES ipca_admin_logbooks (id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ipca_logbook_entries_page
    FOREIGN KEY (source_page_id) REFERENCES ipca_admin_logbook_pages (id)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Flight Training - editable structured flight logbook entries.';

CREATE TABLE IF NOT EXISTS ipca_admin_logbook_entry_audit (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  logbook_id    BIGINT UNSIGNED NULL,
  entry_id      BIGINT UNSIGNED NULL,
  actor_user_id INT NULL,
  event_type    VARCHAR(64) NOT NULL,
  before_json   JSON NULL,
  after_json    JSON NULL,
  event_json    JSON NULL,
  ip_address    VARCHAR(64) NULL,
  user_agent    VARCHAR(255) NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ipca_logbook_audit_logbook_time (logbook_id, created_at),
  KEY idx_ipca_logbook_audit_entry_time (entry_id, created_at),
  KEY idx_ipca_logbook_audit_event_time (event_type, created_at),
  CONSTRAINT fk_ipca_logbook_audit_logbook
    FOREIGN KEY (logbook_id) REFERENCES ipca_admin_logbooks (id)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_ipca_logbook_audit_entry
    FOREIGN KEY (entry_id) REFERENCES ipca_admin_logbook_entries (id)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Flight Training - audit trail for logbook entry changes.';

CREATE TABLE IF NOT EXISTS ipca_flight_requirement_categories (
  id                                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  authority                               VARCHAR(32) NOT NULL COMMENT 'FAA_PART_61 | FAA_PART_141 | EASA',
  certificate                             VARCHAR(32) NOT NULL COMMENT 'PPL | IR | CPL | OTHER',
  requirement_key                         VARCHAR(128) NOT NULL,
  label                                   VARCHAR(255) NOT NULL,
  description                             TEXT NULL,
  minimum_time                            DECIMAL(8,2) NULL,
  minimum_distance_nm                     DECIMAL(8,1) NULL,
  minimum_count                           INT NULL,
  automatic_rules_json                    JSON NULL,
  manual_rules_json                       JSON NULL,
  allow_one_flight_multiple_requirements  TINYINT(1) NOT NULL DEFAULT 1,
  allow_multiple_flights_one_requirement  TINYINT(1) NOT NULL DEFAULT 1,
  status                                  VARCHAR(32) NOT NULL DEFAULT 'active',
  created_at                              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at                              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_ipca_flight_req_category_key (authority, certificate, requirement_key),
  KEY idx_ipca_flight_req_category_status (authority, certificate, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Flight Training - configurable requirement definitions.';

CREATE TABLE IF NOT EXISTS ipca_flight_requirement_assignments (
  id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  student_user_id          INT NULL,
  logbook_id               BIGINT UNSIGNED NOT NULL,
  requirement_category_id  BIGINT UNSIGNED NOT NULL,
  assigned_by              INT NULL,
  status                   VARCHAR(32) NOT NULL DEFAULT 'assigned' COMMENT 'assigned | verified | rejected',
  metadata_json            JSON NULL,
  created_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_ipca_flight_req_assign_logbook (logbook_id),
  KEY idx_ipca_flight_req_assign_student (student_user_id),
  KEY idx_ipca_flight_req_assign_category (requirement_category_id),
  CONSTRAINT fk_ipca_flight_req_assign_logbook
    FOREIGN KEY (logbook_id) REFERENCES ipca_admin_logbooks (id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ipca_flight_req_assign_category
    FOREIGN KEY (requirement_category_id) REFERENCES ipca_flight_requirement_categories (id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Flight Training - requirement assignments for selected flights.';

CREATE TABLE IF NOT EXISTS ipca_flight_requirement_assignment_entries (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  assignment_id  BIGINT UNSIGNED NOT NULL,
  entry_id       BIGINT UNSIGNED NOT NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_ipca_flight_req_assignment_entry (assignment_id, entry_id),
  KEY idx_ipca_flight_req_assignment_entry_entry (entry_id),
  CONSTRAINT fk_ipca_flight_req_assignment_entries_assignment
    FOREIGN KEY (assignment_id) REFERENCES ipca_flight_requirement_assignments (id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ipca_flight_req_assignment_entries_entry
    FOREIGN KEY (entry_id) REFERENCES ipca_admin_logbook_entries (id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Flight Training - selected entries attached to requirement assignments.';

CREATE TABLE IF NOT EXISTS ipca_flight_requirement_evaluations (
  id                       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  student_user_id           INT NULL,
  logbook_id                BIGINT UNSIGNED NOT NULL,
  requirement_category_id   BIGINT UNSIGNED NOT NULL,
  status                    VARCHAR(16) NOT NULL DEFAULT 'fail' COMMENT 'pass | fail | warning',
  result_json               JSON NULL,
  warnings_json             JSON NULL,
  missing_items_json        JSON NULL,
  evaluated_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_ipca_flight_req_eval (logbook_id, requirement_category_id),
  KEY idx_ipca_flight_req_eval_student (student_user_id),
  CONSTRAINT fk_ipca_flight_req_eval_logbook
    FOREIGN KEY (logbook_id) REFERENCES ipca_admin_logbooks (id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ipca_flight_req_eval_category
    FOREIGN KEY (requirement_category_id) REFERENCES ipca_flight_requirement_categories (id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Flight Training - cached PASS/FAIL requirement evaluations.';

CREATE TABLE IF NOT EXISTS ipca_flight_variable_snapshots (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  student_user_id   INT NULL,
  logbook_id        BIGINT UNSIGNED NOT NULL,
  scope_key         VARCHAR(128) NOT NULL DEFAULT 'default',
  variables_json    JSON NOT NULL,
  calculated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_ipca_flight_variable_snapshot (logbook_id, scope_key),
  KEY idx_ipca_flight_variable_snapshot_student (student_user_id),
  CONSTRAINT fk_ipca_flight_variable_snapshot_logbook
    FOREIGN KEY (logbook_id) REFERENCES ipca_admin_logbooks (id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Flight Training - calculated flight variables for future form auto-fill.';

INSERT INTO ipca_flight_requirement_categories
  (authority, certificate, requirement_key, label, description, minimum_time, minimum_distance_nm, minimum_count, automatic_rules_json, manual_rules_json)
VALUES
  ('FAA_PART_61', 'PPL', 'first_solo', 'First Solo', 'First solo flight verification.', NULL, NULL, 1, JSON_OBJECT('type', 'manual_assignment'), JSON_OBJECT()),
  ('FAA_PART_61', 'PPL', 'long_solo_cross_country', 'Long Solo Cross Country', 'Long solo cross-country flight.', NULL, NULL, 1, JSON_OBJECT('type', 'manual_assignment'), JSON_OBJECT()),
  ('FAA_PART_61', 'PPL', 'solo_cross_country_150_nm', 'Solo Cross Country 150 NM', 'Solo cross-country requirement with 150 NM total distance.', NULL, 150.0, 1, JSON_OBJECT('type', 'selected_entries_distance'), JSON_OBJECT()),
  ('FAA_PART_61', 'PPL', 'towered_airport_landings', 'Towered Airport Full Stop Landings', 'Towered airport full-stop landings.', NULL, NULL, 3, JSON_OBJECT('metric', 'towered_airport_landings'), JSON_OBJECT()),
  ('FAA_PART_61', 'PPL', 'night_dual', 'Night Dual', 'Night dual instruction.', 3.0, NULL, NULL, JSON_OBJECT('metric', 'night_time'), JSON_OBJECT()),
  ('FAA_PART_61', 'PPL', 'basic_instrument_flying', 'Basic Instrument Flying', 'Basic instrument flying training time.', 3.0, NULL, NULL, JSON_OBJECT('metric', 'basic_instrument_flying_time'), JSON_OBJECT()),
  ('FAA_PART_61', 'PPL', 'total_time', 'Total Time', 'Total flight time.', 40.0, NULL, NULL, JSON_OBJECT('metric', 'total_flight_time'), JSON_OBJECT()),
  ('FAA_PART_61', 'PPL', 'solo_time', 'Solo Time', 'Solo flight time.', 10.0, NULL, NULL, JSON_OBJECT('metric', 'solo_time'), JSON_OBJECT()),
  ('FAA_PART_141', 'PPL', 'stage_check_eligible', 'Stage Check Eligible', 'Stage check readiness marker.', NULL, NULL, 1, JSON_OBJECT('type', 'manual_assignment'), JSON_OBJECT()),
  ('FAA_PART_141', 'PPL', 'end_of_course_eligible', 'End Of Course Eligible', 'End of course readiness marker.', NULL, NULL, 1, JSON_OBJECT('type', 'manual_assignment'), JSON_OBJECT()),
  ('EASA', 'PPL', 'dual_instruction', 'Dual Instruction', 'EASA PPL dual instruction.', NULL, NULL, NULL, JSON_OBJECT('metric', 'dual_received_time'), JSON_OBJECT()),
  ('EASA', 'PPL', 'solo_flight', 'Solo Flight', 'EASA PPL solo flight.', NULL, NULL, NULL, JSON_OBJECT('metric', 'solo_time'), JSON_OBJECT()),
  ('EASA', 'PPL', 'navigation_flight', 'Navigation Flight', 'EASA PPL navigation flight.', NULL, NULL, 1, JSON_OBJECT('type', 'manual_assignment'), JSON_OBJECT()),
  ('EASA', 'PPL', 'basic_instrument_flying', 'Basic Instrument Flying', 'EASA basic instrument flying.', NULL, NULL, NULL, JSON_OBJECT('metric', 'basic_instrument_flying_time'), JSON_OBJECT())
ON DUPLICATE KEY UPDATE
  label = VALUES(label),
  description = VALUES(description),
  minimum_time = VALUES(minimum_time),
  minimum_distance_nm = VALUES(minimum_distance_nm),
  minimum_count = VALUES(minimum_count),
  automatic_rules_json = VALUES(automatic_rules_json),
  manual_rules_json = VALUES(manual_rules_json),
  status = 'active',
  updated_at = CURRENT_TIMESTAMP;
