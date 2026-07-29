-- Expiring, revocable, passcode-protected public access to one Cockpit Recorder replay.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET collation_connection = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ipca_replay_debrief_shares (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  share_uuid CHAR(36) NOT NULL,
  debrief_id BIGINT UNSIGNED NOT NULL,
  bundle_id BIGINT UNSIGNED NOT NULL,
  recording_id BIGINT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL,
  passcode_hash VARCHAR(255) NOT NULL,
  status VARCHAR(24) NOT NULL DEFAULT 'active',
  expires_at DATETIME(3) NOT NULL,
  revoked_at DATETIME(3) NULL,
  revoked_by BIGINT UNSIGNED NULL,
  failed_attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
  locked_until DATETIME(3) NULL,
  last_viewed_at DATETIME(3) NULL,
  view_count INT UNSIGNED NOT NULL DEFAULT 0,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ipca_replay_debrief_shares_uuid (share_uuid),
  UNIQUE KEY uk_ipca_replay_debrief_shares_token (token_hash),
  KEY idx_ipca_replay_debrief_shares_debrief (debrief_id, status, expires_at),
  KEY idx_ipca_replay_debrief_shares_recording (recording_id, status),
  CONSTRAINT fk_ipca_replay_debrief_shares_debrief
    FOREIGN KEY (debrief_id) REFERENCES ipca_structured_debriefs(id)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_ipca_replay_debrief_shares_bundle
    FOREIGN KEY (bundle_id) REFERENCES ipca_manual_intake_bundles(id)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_ipca_replay_debrief_shares_recording
    FOREIGN KEY (recording_id) REFERENCES ipca_cockpit_recordings(id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ipca_replay_debrief_share_access (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  access_uuid CHAR(36) NOT NULL,
  share_id BIGINT UNSIGNED NOT NULL,
  status VARCHAR(24) NOT NULL DEFAULT 'unlocked',
  privacy_notice_version VARCHAR(32) NULL,
  privacy_accepted_at DATETIME(3) NULL,
  ip_hash CHAR(64) NOT NULL,
  user_agent_hash CHAR(64) NOT NULL,
  last_accessed_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ipca_replay_debrief_share_access_uuid (access_uuid),
  KEY idx_ipca_replay_debrief_share_access_share (share_id, created_at),
  CONSTRAINT fk_ipca_replay_debrief_share_access_share
    FOREIGN KEY (share_id) REFERENCES ipca_replay_debrief_shares(id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
