-- Reservation-first Aircraft Safety Occurrence Report intake.
-- Additive and re-run safe for MySQL 8.

INSERT IGNORE INTO ipca_safety_config (organization_id, config_key, config_value) VALUES
  (1, 'reporter_occurrence_type_node_type', JSON_OBJECT('value', 'reporter_occurrence_type')),
  (1, 'flight_schedule_timezone_iana', JSON_OBJECT('value', 'America/Los_Angeles'));

SET @table_name := 'ipca_safety_reports';

SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=@table_name AND COLUMN_NAME='occurrence_type_node_id');
SET @sql := IF(@col_exists=0, 'ALTER TABLE ipca_safety_reports ADD COLUMN occurrence_type_node_id BIGINT UNSIGNED NULL AFTER category_code', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=@table_name AND COLUMN_NAME='phase_of_flight');
SET @sql := IF(@col_exists=0, 'ALTER TABLE ipca_safety_reports ADD COLUMN phase_of_flight VARCHAR(64) NULL AFTER immediate_action', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=@table_name AND COLUMN_NAME='injury_state');
SET @sql := IF(@col_exists=0, 'ALTER TABLE ipca_safety_reports ADD COLUMN injury_state VARCHAR(16) NOT NULL DEFAULT ''unknown'' AFTER phase_of_flight', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=@table_name AND COLUMN_NAME='injury_details');
SET @sql := IF(@col_exists=0, 'ALTER TABLE ipca_safety_reports ADD COLUMN injury_details TEXT NULL AFTER injury_state', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=@table_name AND COLUMN_NAME='damage_state');
SET @sql := IF(@col_exists=0, 'ALTER TABLE ipca_safety_reports ADD COLUMN damage_state VARCHAR(16) NOT NULL DEFAULT ''unknown'' AFTER injury_details', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=@table_name AND COLUMN_NAME='damage_details');
SET @sql := IF(@col_exists=0, 'ALTER TABLE ipca_safety_reports ADD COLUMN damage_details TEXT NULL AFTER damage_state', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=@table_name AND COLUMN_NAME='weather_relevance');
SET @sql := IF(@col_exists=0, 'ALTER TABLE ipca_safety_reports ADD COLUMN weather_relevance VARCHAR(16) NOT NULL DEFAULT ''unknown'' AFTER damage_details', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=@table_name AND COLUMN_NAME='weather_details');
SET @sql := IF(@col_exists=0, 'ALTER TABLE ipca_safety_reports ADD COLUMN weather_details TEXT NULL AFTER weather_relevance', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=@table_name AND COLUMN_NAME='intake_context_json');
SET @sql := IF(@col_exists=0, 'ALTER TABLE ipca_safety_reports ADD COLUMN intake_context_json JSON NULL AFTER weather_details', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=@table_name AND INDEX_NAME='idx_safety_report_occurrence_type');
SET @sql := IF(@idx_exists=0, 'CREATE INDEX idx_safety_report_occurrence_type ON ipca_safety_reports (organization_id, occurrence_type_node_id)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk_exists := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME=@table_name AND CONSTRAINT_NAME='fk_safety_report_occurrence_type');
SET @sql := IF(@fk_exists=0, 'ALTER TABLE ipca_safety_reports ADD CONSTRAINT fk_safety_report_occurrence_type FOREIGN KEY (occurrence_type_node_id) REFERENCES ipca_safety_taxonomy_nodes(id) ON DELETE RESTRICT ON UPDATE CASCADE', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS ipca_safety_report_flight_links (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  report_id BIGINT UNSIGNED NOT NULL,
  link_choice VARCHAR(32) NOT NULL,
  schedule_slot_id BIGINT UNSIGNED NULL,
  dispatch_id BIGINT UNSIGNED NULL,
  flight_session_id BIGINT UNSIGNED NULL,
  operational_flight_record_id BIGINT UNSIGNED NULL,
  resolution_state VARCHAR(32) NOT NULL DEFAULT 'pending',
  selection_method VARCHAR(48) NOT NULL DEFAULT 'reporter_confirmed',
  selected_by_user_id BIGINT UNSIGNED NULL,
  context_snapshot_json JSON NULL,
  selected_at_utc DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  resolved_at_utc DATETIME(3) NULL,
  updated_at_utc DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_safety_report_flight_report (report_id),
  KEY idx_safety_report_flight_slot (organization_id, schedule_slot_id),
  KEY idx_safety_report_flight_resolution (organization_id, resolution_state, updated_at_utc),
  CONSTRAINT fk_safety_report_flight_report FOREIGN KEY (report_id)
    REFERENCES ipca_safety_reports(id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_safety_report_flight_slot FOREIGN KEY (schedule_slot_id)
    REFERENCES ipca_flight_schedule_slots(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_safety_report_flight_dispatch FOREIGN KEY (dispatch_id)
    REFERENCES ipca_cvr_dispatches(id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_safety_report_flight_session FOREIGN KEY (flight_session_id)
    REFERENCES ipca_flight_sessions(id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_safety_report_flight_record FOREIGN KEY (operational_flight_record_id)
    REFERENCES ipca_operational_flight_records(id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT chk_safety_report_flight_choice CHECK (link_choice IN ('scheduled_flight','no_reservation')),
  CONSTRAINT chk_safety_report_flight_choice_target CHECK (
    (link_choice = 'scheduled_flight' AND schedule_slot_id IS NOT NULL)
    OR (link_choice = 'no_reservation' AND schedule_slot_id IS NULL)
  ),
  CONSTRAINT chk_safety_report_flight_resolution CHECK (
    resolution_state IN ('pending','resolved','review_required','not_applicable')
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Reporter-confirmed flight context and late evidence-resolution pointers; no evidence payloads are copied.';
