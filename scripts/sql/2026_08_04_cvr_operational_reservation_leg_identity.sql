-- Phase 2A: additive canonical reservation / leg identity register.
-- Idempotent for MySQL 8. Does not mutate legacy schedule/Dispatch/evidence rows.
-- Manual apply. Feature flags default off.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET collation_connection = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ipca_operational_reservations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  reservation_uuid CHAR(36) NOT NULL,
  organization_id BIGINT UNSIGNED NOT NULL COMMENT 'Required; never defaulted',
  organization_timezone_iana VARCHAR(64) NOT NULL,
  reservation_type VARCHAR(32) NOT NULL,
  activity_domain VARCHAR(32) NOT NULL COMMENT 'flight|simulator|ground|administrative',
  status VARCHAR(32) NOT NULL COMMENT 'scheduled|active|completed|cancelled',
  source VARCHAR(32) NOT NULL COMMENT 'server_create|ios_offline|schedule_adopt|manual',
  adoption_source_system VARCHAR(64) NULL,
  adoption_provenance_json JSON NULL,
  created_at_utc DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at_utc DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uk_op_reservations_uuid (reservation_uuid),
  KEY idx_op_reservations_org_status (organization_id, status, updated_at_utc),
  KEY idx_op_reservations_org_domain (organization_id, activity_domain, status),
  KEY idx_op_reservations_org_type (organization_id, reservation_type, status),
  CONSTRAINT chk_op_reservations_type CHECK (reservation_type IN (
    'flight_training','briefing','ar_briefing','simulator_training',
    'theory_lesson','theory_mock_exam','practical_exam','meeting',
    'assessment','maintenance','personal','unavailable'
  )),
  CONSTRAINT chk_op_reservations_activity_domain CHECK (activity_domain IN (
    'flight','simulator','ground','administrative'
  )),
  CONSTRAINT chk_op_reservations_status CHECK (status IN (
    'scheduled','active','completed','cancelled'
  )),
  CONSTRAINT chk_op_reservations_source CHECK (source IN (
    'server_create','ios_offline','schedule_adopt','manual'
  )),
  CONSTRAINT chk_op_reservations_uuid_lower CHECK (
    reservation_uuid = LOWER(reservation_uuid)
    AND CHAR_LENGTH(reservation_uuid) = 36
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Canonical operational reservations. No aircraft_id in Phase 2A.';

CREATE TABLE IF NOT EXISTS ipca_operational_reservation_legs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  leg_uuid CHAR(36) NOT NULL,
  reservation_uuid CHAR(36) NOT NULL,
  organization_id BIGINT UNSIGNED NOT NULL COMMENT 'Required; never defaulted',
  sequence_number INT UNSIGNED NOT NULL,
  origin_airport VARCHAR(8) NOT NULL DEFAULT '',
  destination_airport VARCHAR(8) NOT NULL DEFAULT '',
  planned_start_at_utc DATETIME(3) NULL,
  planned_end_at_utc DATETIME(3) NULL,
  planned_start_local DATETIME(3) NULL,
  planned_end_local DATETIME(3) NULL,
  organization_timezone_iana VARCHAR(64) NOT NULL,
  planned_start_utc_offset_minutes INT NULL,
  planned_end_utc_offset_minutes INT NULL,
  planned_start_dst_resolution VARCHAR(32) NULL,
  planned_end_dst_resolution VARCHAR(32) NULL,
  status VARCHAR(32) NOT NULL COMMENT 'scheduled|dispatched|active|checked_in|cancelled',
  source VARCHAR(32) NOT NULL,
  created_at_utc DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at_utc DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uk_op_legs_uuid (leg_uuid),
  UNIQUE KEY uk_op_legs_reservation_seq (reservation_uuid, sequence_number),
  KEY idx_op_legs_org_status (organization_id, status, planned_start_at_utc),
  KEY idx_op_legs_org_reservation (organization_id, reservation_uuid),
  CONSTRAINT fk_op_legs_reservation
    FOREIGN KEY (reservation_uuid)
      REFERENCES ipca_operational_reservations(reservation_uuid)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT chk_op_legs_status CHECK (status IN (
    'scheduled','dispatched','active','checked_in','cancelled'
  )),
  CONSTRAINT chk_op_legs_source CHECK (source IN (
    'server_create','ios_offline','backfill_verified','manual'
  )),
  CONSTRAINT chk_op_legs_sequence CHECK (sequence_number >= 1),
  CONSTRAINT chk_op_legs_dst_start CHECK (
    planned_start_dst_resolution IS NULL OR planned_start_dst_resolution IN (
      'earlier','later','unambiguous','unspecified'
    )
  ),
  CONSTRAINT chk_op_legs_dst_end CHECK (
    planned_end_dst_resolution IS NULL OR planned_end_dst_resolution IN (
      'earlier','later','unambiguous','unspecified'
    )
  ),
  CONSTRAINT chk_op_legs_time_order_utc CHECK (
    planned_start_at_utc IS NULL OR planned_end_at_utc IS NULL
    OR planned_end_at_utc > planned_start_at_utc
  ),
  CONSTRAINT chk_op_legs_uuid_lower CHECK (
    leg_uuid = LOWER(leg_uuid)
    AND CHAR_LENGTH(leg_uuid) = 36
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Operational flight legs. Created only when parent activity_domain=flight.';

CREATE TABLE IF NOT EXISTS ipca_operational_identity_aliases (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL COMMENT 'Required; never defaulted',
  source_system VARCHAR(64) NOT NULL,
  alias_type VARCHAR(64) NOT NULL,
  alias_value VARCHAR(96) NOT NULL,
  alias_version VARCHAR(32) NULL COMMENT 'Nullable; never encoded into alias_value',
  alias_version_key VARCHAR(32) NOT NULL DEFAULT '' COMMENT 'Null-safe uniqueness key matching empty string when alias_version is null',
  target_type VARCHAR(16) NOT NULL COMMENT 'reservation|leg',
  reservation_uuid CHAR(36) NULL,
  leg_uuid CHAR(36) NULL,
  confidence_state VARCHAR(32) NOT NULL COMMENT 'VERIFIED|DETERMINISTIC_BACKFILL',
  linkage_method VARCHAR(32) NOT NULL,
  created_at_utc DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uk_op_identity_aliases_scope (
    organization_id, source_system, alias_type, alias_value, alias_version_key
  ),
  KEY idx_op_identity_aliases_leg (organization_id, leg_uuid),
  KEY idx_op_identity_aliases_reservation (organization_id, reservation_uuid),
  KEY idx_op_identity_aliases_target (organization_id, target_type, alias_type),
  CONSTRAINT fk_op_identity_aliases_reservation
    FOREIGN KEY (reservation_uuid)
      REFERENCES ipca_operational_reservations(reservation_uuid)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_op_identity_aliases_leg
    FOREIGN KEY (leg_uuid)
      REFERENCES ipca_operational_reservation_legs(leg_uuid)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT chk_op_identity_aliases_target_type CHECK (
    target_type IN ('reservation','leg')
  ),
  CONSTRAINT chk_op_identity_aliases_exactly_one_target CHECK (
    (target_type = 'reservation' AND reservation_uuid IS NOT NULL AND leg_uuid IS NULL)
    OR (target_type = 'leg' AND leg_uuid IS NOT NULL AND reservation_uuid IS NULL)
  ),
  CONSTRAINT chk_op_identity_aliases_confidence CHECK (
    confidence_state IN ('VERIFIED','DETERMINISTIC_BACKFILL')
  ),
  CONSTRAINT chk_op_identity_aliases_linkage CHECK (
    linkage_method IN (
      'online_create','offline_create','deterministic_backfill','manual_verified'
    )
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Org+source_system scoped identity aliases. Exactly one canonical target.';

CREATE TABLE IF NOT EXISTS ipca_operational_identity_backfill_quarantine (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL COMMENT 'Required; never defaulted',
  subject_type VARCHAR(64) NOT NULL,
  subject_table VARCHAR(128) NOT NULL,
  subject_pk VARCHAR(96) NOT NULL,
  subject_natural_key VARCHAR(96) NULL,
  reason_code VARCHAR(64) NOT NULL,
  diagnostic_json JSON NOT NULL,
  diagnostic_bytes INT UNSIGNED NOT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'open',
  resolved_by_user_id INT NULL,
  resolution_notes VARCHAR(512) NULL,
  created_at_utc DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at_utc DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  resolved_at_utc DATETIME(3) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_op_identity_quarantine_subject (
    organization_id, subject_type, subject_table, subject_pk, reason_code
  ),
  KEY idx_op_identity_quarantine_org_status (organization_id, status, created_at_utc),
  CONSTRAINT chk_op_identity_quarantine_status CHECK (
    status IN ('open','resolved_manual','wont_fix')
  ),
  CONSTRAINT chk_op_identity_quarantine_bytes CHECK (
    diagnostic_bytes > 0 AND diagnostic_bytes <= 4096
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Org-scoped identity backfill quarantine. Source rows never mutated.';

-- Feature flags (default off). Skip silently if policy definitions table is absent.
SET @policy_defs_exist := (
  SELECT COUNT(*) FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'system_policy_definitions'
);
SET @sql := IF(@policy_defs_exist > 0,
  'INSERT INTO system_policy_definitions
     (policy_key, category, value_type, default_value_text, allowed_values_json, validation_rules_json, description_text, is_admin_editable, sort_order)
   VALUES
     (''operational_identity_backfill_enabled'', ''cvr_operational_identity'', ''bool'', ''0'', ''[\"0\",\"1\"]'', NULL,
      ''Phase 2A: allow deterministic operational identity backfill writes. Default off.'', 1, 9200),
     (''operational_identity_dual_read_enabled'', ''cvr_operational_identity'', ''bool'', ''0'', ''[\"0\",\"1\"]'', NULL,
      ''Phase 2A: prefer verified canonical leg/reservation identity when resolving legacy IDs. Default off.'', 1, 9210),
     (''operational_identity_canonical_write_enabled'', ''cvr_operational_identity'', ''bool'', ''0'', ''[\"0\",\"1\"]'', NULL,
      ''Phase 2A: allow online/offline canonical identity writes. Default off.'', 1, 9220)
   ON DUPLICATE KEY UPDATE
     category = VALUES(category),
     value_type = VALUES(value_type),
     default_value_text = VALUES(default_value_text),
     allowed_values_json = VALUES(allowed_values_json),
     description_text = VALUES(description_text),
     is_admin_editable = VALUES(is_admin_editable),
     sort_order = VALUES(sort_order)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
