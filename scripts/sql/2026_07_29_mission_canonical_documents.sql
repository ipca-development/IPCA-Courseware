-- Separate immutable scenario-plan and evaluation-rubric records linked to a mission version.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET collation_connection = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ipca_mission_canonical_documents (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  document_uuid CHAR(36) NOT NULL,
  mission_version_id BIGINT UNSIGNED NOT NULL,
  document_type VARCHAR(32) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  source_document VARCHAR(255) NOT NULL,
  source_revision VARCHAR(64) NOT NULL,
  source_date DATE NULL,
  content_sha256 CHAR(64) NOT NULL,
  content_json JSON NOT NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ipca_mission_canonical_documents_uuid (document_uuid),
  UNIQUE KEY uk_ipca_mission_canonical_documents_type (mission_version_id, document_type),
  KEY idx_ipca_mission_canonical_documents_mission (mission_version_id),
  CONSTRAINT fk_ipca_mission_canonical_documents_version
    FOREIGN KEY (mission_version_id) REFERENCES ipca_mission_versions(id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
