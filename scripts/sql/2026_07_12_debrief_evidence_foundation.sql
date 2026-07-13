-- Evidence packages, instructor notes, AI debrief versions, approvals, and release controls.

CREATE TABLE IF NOT EXISTS ipca_flight_evidence_packages (
  id                         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  evidence_package_uuid       CHAR(36) NOT NULL,
  flight_record_version_id    BIGINT UNSIGNED NOT NULL,
  package_version             INT UNSIGNED NOT NULL,
  evidence_manifest_json      JSON NOT NULL,
  sha256                      CHAR(64) NOT NULL DEFAULT '',
  created_by                  BIGINT UNSIGNED NULL,
  created_at                  DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ipca_flight_evidence_packages_uuid (evidence_package_uuid),
  UNIQUE KEY uk_ipca_flight_evidence_packages_version (flight_record_version_id, package_version),
  CONSTRAINT fk_ipca_flight_evidence_packages_record_version
    FOREIGN KEY (flight_record_version_id) REFERENCES ipca_operational_flight_record_versions(id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Immutable evidence manifests for Flight Record debriefs.';

CREATE TABLE IF NOT EXISTS ipca_instructor_debrief_notes (
  id                         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  note_uuid                   CHAR(36) NOT NULL,
  flight_record_version_id    BIGINT UNSIGNED NOT NULL,
  author_user_id              BIGINT UNSIGNED NOT NULL,
  visibility                  VARCHAR(32) NOT NULL DEFAULT 'instructor_private',
  note_text                   TEXT NOT NULL,
  evidence_refs_json          JSON NULL,
  superseded_by_note_id       BIGINT UNSIGNED NULL,
  created_at                  DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ipca_instructor_debrief_notes_uuid (note_uuid),
  KEY idx_ipca_instructor_debrief_notes_record (flight_record_version_id, created_at),
  CONSTRAINT fk_ipca_instructor_debrief_notes_record_version
    FOREIGN KEY (flight_record_version_id) REFERENCES ipca_operational_flight_record_versions(id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Versionable instructor notes for debriefing.';

CREATE TABLE IF NOT EXISTS ipca_ai_debrief_versions (
  id                         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  debrief_uuid                CHAR(36) NOT NULL,
  flight_record_version_id    BIGINT UNSIGNED NOT NULL,
  evidence_package_id         BIGINT UNSIGNED NULL,
  provider                    VARCHAR(64) NOT NULL DEFAULT 'openai',
  model                       VARCHAR(128) NOT NULL DEFAULT '',
  prompt_template_key         VARCHAR(128) NOT NULL DEFAULT '',
  prompt_template_version     INT UNSIGNED NOT NULL DEFAULT 1,
  status                      VARCHAR(32) NOT NULL DEFAULT 'draft',
  summary_text                TEXT NULL,
  strengths_text              TEXT NULL,
  improvement_text            TEXT NULL,
  action_items_json           JSON NULL,
  evidence_refs_json          JSON NULL,
  uncertainty_json            JSON NULL,
  approved_by                 BIGINT UNSIGNED NULL,
  approved_at                 DATETIME(3) NULL,
  created_at                  DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at                  DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ipca_ai_debrief_versions_uuid (debrief_uuid),
  KEY idx_ipca_ai_debrief_versions_record (flight_record_version_id, status),
  CONSTRAINT fk_ipca_ai_debrief_versions_record_version
    FOREIGN KEY (flight_record_version_id) REFERENCES ipca_operational_flight_record_versions(id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ipca_ai_debrief_versions_package
    FOREIGN KEY (evidence_package_id) REFERENCES ipca_flight_evidence_packages(id)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='AI-assisted debrief versions with evidence references and approval.';

CREATE TABLE IF NOT EXISTS ipca_flight_record_release_controls (
  id                         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  release_uuid                CHAR(36) NOT NULL,
  flight_record_version_id    BIGINT UNSIGNED NOT NULL,
  recipient_user_id           BIGINT UNSIGNED NOT NULL,
  summary_released            TINYINT(1) NOT NULL DEFAULT 0,
  replay_released             TINYINT(1) NOT NULL DEFAULT 0,
  transcript_released         TINYINT(1) NOT NULL DEFAULT 0,
  debrief_released            TINYINT(1) NOT NULL DEFAULT 0,
  audio_released              TINYINT(1) NOT NULL DEFAULT 0,
  released_by                 BIGINT UNSIGNED NULL,
  released_at                 DATETIME(3) NULL,
  created_at                  DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at                  DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ipca_flight_record_release_controls_uuid (release_uuid),
  UNIQUE KEY uk_ipca_flight_record_release_controls_recipient (flight_record_version_id, recipient_user_id),
  CONSTRAINT fk_ipca_flight_record_release_controls_record_version
    FOREIGN KEY (flight_record_version_id) REFERENCES ipca_operational_flight_record_versions(id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Separate release switches for summary, replay, transcript, debrief, and audio.';
