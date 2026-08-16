-- IPCA Communication Phase 5 — Training companion (read-only).
-- Additive. Re-run safe.

INSERT INTO ipca_communication_app_config (config_key, config_value)
VALUES ('training_enabled', '1')
ON DUPLICATE KEY UPDATE config_value = '1';
