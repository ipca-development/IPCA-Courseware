-- =============================================================================
-- Compliance Operating System — Calendar wiring
-- =============================================================================
-- Adds persistent manual calendar events and a pending change-request queue for
-- proposed calendar moves/resizes. Source compliance tables remain canonical.
-- =============================================================================

CREATE TABLE IF NOT EXISTS ipca_compliance_calendar_events (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  title                 VARCHAR(255) NOT NULL,
  event_type            VARCHAR(64) NOT NULL DEFAULT 'other',
  status                VARCHAR(32) NOT NULL DEFAULT 'SCHEDULED',
  governance_state      VARCHAR(32) NOT NULL DEFAULT 'approved',
  starts_at             DATETIME NOT NULL,
  ends_at               DATETIME NULL,
  is_all_day            TINYINT(1) NOT NULL DEFAULT 0,
  timezone              VARCHAR(64) NOT NULL DEFAULT 'UTC',
  description           TEXT NULL,
  linked_object_type    VARCHAR(64) NULL,
  linked_object_id      BIGINT UNSIGNED NULL,
  color_key             VARCHAR(64) NOT NULL DEFAULT 'other',
  icon_key              VARCHAR(64) NOT NULL DEFAULT 'calendar',
  is_locked             TINYINT(1) NOT NULL DEFAULT 0,
  requires_approval_to_move TINYINT(1) NOT NULL DEFAULT 0,
  created_by            INT UNSIGNED NULL,
  updated_by            INT UNSIGNED NULL,
  locked_at             DATETIME NULL,
  locked_by             INT UNSIGNED NULL,
  created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_ipcacal_events_start (starts_at),
  KEY idx_ipcacal_events_type_start (event_type, starts_at),
  KEY idx_ipcacal_events_linked (linked_object_type, linked_object_id),
  KEY idx_ipcacal_events_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Compliance OS — manual calendar events; source table projections remain canonical elsewhere.';

CREATE TABLE IF NOT EXISTS ipca_compliance_calendar_change_requests (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  source_event_id       VARCHAR(191) NOT NULL COMMENT 'Normalized calendar id e.g. meeting:123 or cap:456',
  source_type           VARCHAR(64) NOT NULL,
  source_table          VARCHAR(128) NULL,
  source_id             BIGINT UNSIGNED NULL,
  linked_object_type    VARCHAR(64) NULL,
  linked_object_id      BIGINT UNSIGNED NULL,
  change_kind           VARCHAR(32) NOT NULL DEFAULT 'move' COMMENT 'move | resize | move_resize',
  title                 VARCHAR(255) NOT NULL,
  event_type            VARCHAR(64) NOT NULL DEFAULT 'other',
  current_starts_at     DATETIME NULL,
  current_ends_at       DATETIME NULL,
  proposed_starts_at    DATETIME NOT NULL,
  proposed_ends_at      DATETIME NULL,
  timezone              VARCHAR(64) NOT NULL DEFAULT 'UTC',
  governance_state      VARCHAR(32) NOT NULL DEFAULT 'pending_approval',
  status                VARCHAR(32) NOT NULL DEFAULT 'pending' COMMENT 'pending | approved | rejected | cancelled',
  reason                TEXT NULL,
  reviewer_notes        TEXT NULL,
  requested_by          INT UNSIGNED NULL,
  requested_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reviewed_by           INT UNSIGNED NULL,
  reviewed_at           DATETIME NULL,
  applied_at            DATETIME NULL,
  apply_error           TEXT NULL,
  payload_json          JSON NULL,
  created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_ipcacalcr_status_requested (status, requested_at),
  KEY idx_ipcacalcr_source_event (source_event_id),
  KEY idx_ipcacalcr_source (source_type, source_id),
  KEY idx_ipcacalcr_linked (linked_object_type, linked_object_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Compliance OS — pending/approved calendar change requests for governed projected events.';

CREATE TABLE IF NOT EXISTS ipca_compliance_calendar_event_audit (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  calendar_event_id     BIGINT UNSIGNED NOT NULL,
  event_kind            VARCHAR(32) NOT NULL,
  summary               VARCHAR(255) NOT NULL,
  before_json           JSON NULL,
  after_json            JSON NULL,
  actor_user_id         INT UNSIGNED NULL,
  occurred_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ipcacal_audit_event_time (calendar_event_id, occurred_at),
  KEY idx_ipcacal_audit_actor_time (actor_user_id, occurred_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Compliance OS — audit trail for manual calendar event create/edit/link/move actions.';

