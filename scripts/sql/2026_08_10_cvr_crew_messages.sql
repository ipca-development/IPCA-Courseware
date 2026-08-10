-- Immutable schedule-editor messages to an actively assigned CVR crew.
-- Both evidence tables are append-only; no cascade may erase operational evidence.

CREATE TABLE IF NOT EXISTS ipca_cvr_crew_messages (
  id                           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  message_uuid                 CHAR(36) NOT NULL,
  organization_id              BIGINT UNSIGNED NOT NULL,
  aircraft_id                  BIGINT UNSIGNED NOT NULL,
  device_id                    BIGINT UNSIGNED NOT NULL,
  operational_session_uuid     CHAR(36) NOT NULL,
  workflow_flight_record_uuid  CHAR(36) NOT NULL,
  dispatch_uuid                CHAR(36) NOT NULL,
  sender_user_id               BIGINT UNSIGNED NOT NULL,
  sender_name                  VARCHAR(255) NOT NULL DEFAULT '',
  sender_role                  VARCHAR(64) NOT NULL DEFAULT '',
  body                         VARCHAR(512) NOT NULL,
  sent_at_utc                  DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ipca_cvr_crew_messages_uuid (message_uuid),
  KEY idx_ipca_cvr_crew_messages_org_session (organization_id, operational_session_uuid, sent_at_utc, id),
  KEY idx_ipca_cvr_crew_messages_device_session (device_id, operational_session_uuid, sent_at_utc, id),
  KEY idx_ipca_cvr_crew_messages_aircraft_session (aircraft_id, operational_session_uuid, sent_at_utc, id),
  KEY idx_ipca_cvr_crew_messages_flight (workflow_flight_record_uuid, sent_at_utc, id),
  KEY idx_ipca_cvr_crew_messages_dispatch (dispatch_uuid, sent_at_utc, id),
  KEY idx_ipca_cvr_crew_messages_sender (sender_user_id, sent_at_utc, id),
  CONSTRAINT fk_ipca_cvr_crew_messages_device
    FOREIGN KEY (device_id) REFERENCES ipca_cvr_devices(id)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_ipca_cvr_crew_messages_aircraft
    FOREIGN KEY (aircraft_id) REFERENCES ipca_aircraft_devices(id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Immutable messages sent to the device assigned to an active Operational Session.';

CREATE TABLE IF NOT EXISTS ipca_cvr_crew_message_acknowledgements (
  id                           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  acknowledgement_uuid         CHAR(36) NOT NULL,
  message_id                   BIGINT UNSIGNED NOT NULL,
  message_uuid                 CHAR(36) NOT NULL,
  organization_id              BIGINT UNSIGNED NOT NULL,
  aircraft_id                  BIGINT UNSIGNED NOT NULL,
  device_id                    BIGINT UNSIGNED NOT NULL,
  operational_session_uuid     CHAR(36) NOT NULL,
  workflow_flight_record_uuid  CHAR(36) NOT NULL,
  device_event_at_utc          DATETIME(3) NOT NULL,
  server_received_at_utc       DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ipca_cvr_crew_ack_uuid (acknowledgement_uuid),
  UNIQUE KEY uk_ipca_cvr_crew_ack_message_device (message_id, device_id),
  KEY idx_ipca_cvr_crew_ack_org_session (organization_id, operational_session_uuid, server_received_at_utc, id),
  KEY idx_ipca_cvr_crew_ack_device_session (device_id, operational_session_uuid, server_received_at_utc, id),
  KEY idx_ipca_cvr_crew_ack_aircraft_session (aircraft_id, operational_session_uuid, server_received_at_utc, id),
  KEY idx_ipca_cvr_crew_ack_flight (workflow_flight_record_uuid, server_received_at_utc, id),
  CONSTRAINT fk_ipca_cvr_crew_ack_message
    FOREIGN KEY (message_id) REFERENCES ipca_cvr_crew_messages(id)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_ipca_cvr_crew_ack_device
    FOREIGN KEY (device_id) REFERENCES ipca_cvr_devices(id)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_ipca_cvr_crew_ack_aircraft
    FOREIGN KEY (aircraft_id) REFERENCES ipca_aircraft_devices(id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Append-only device acknowledgement evidence with device and server UTC times.';
