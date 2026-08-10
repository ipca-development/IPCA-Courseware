CREATE TABLE IF NOT EXISTS ipca_operational_session_leg_reviews (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  revision_uuid CHAR(36) NOT NULL,
  operational_session_uuid CHAR(36) NOT NULL,
  dispatch_id BIGINT UNSIGNED NOT NULL,
  workflow_flight_record_uuid CHAR(36) NOT NULL,
  revision_number INT UNSIGNED NOT NULL,
  status VARCHAR(24) NOT NULL DEFAULT 'ACCEPTED',
  evidence_sha256 CHAR(64) NULL,
  legs_json JSON NOT NULL,
  reviewed_by_device_id BIGINT UNSIGNED NOT NULL,
  reviewed_at_utc DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  supersedes_revision_uuid CHAR(36) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_ipca_session_leg_review_uuid (revision_uuid),
  UNIQUE KEY uk_ipca_session_leg_review_number (operational_session_uuid, revision_number),
  KEY idx_ipca_session_leg_review_flight (workflow_flight_record_uuid),
  KEY idx_ipca_session_leg_review_dispatch (dispatch_id),
  CONSTRAINT fk_ipca_session_leg_review_dispatch
    FOREIGN KEY (dispatch_id) REFERENCES ipca_cvr_dispatches(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
