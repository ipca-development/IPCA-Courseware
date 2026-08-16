-- IPCA Communication Phase 4 — system messages, acknowledgements, Needs Attention.
-- Additive. Event identity already exists on ipca_communication_messages (source_*).
-- Re-run safe.

INSERT INTO ipca_communication_app_config (config_key, config_value)
VALUES ('system_messages_enabled', '1')
ON DUPLICATE KEY UPDATE config_value = '1';
