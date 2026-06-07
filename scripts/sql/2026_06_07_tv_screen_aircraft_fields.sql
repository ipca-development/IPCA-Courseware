-- TV flip board: per-aircraft ADS-B tracking fields (hex, label, home base).

ALTER TABLE tv_screen_messages
  ADD COLUMN aircraft_hex VARCHAR(8) NULL DEFAULT NULL AFTER body,
  ADD COLUMN aircraft_label VARCHAR(16) NULL DEFAULT NULL AFTER aircraft_hex,
  ADD COLUMN aircraft_home_airport VARCHAR(4) NULL DEFAULT NULL AFTER aircraft_label;

UPDATE tv_screen_messages
SET
  aircraft_hex = LOWER(TRIM(body)),
  aircraft_label = UPPER(TRIM(title)),
  aircraft_home_airport = 'KTRM'
WHERE message_type = 'aircraft'
  AND (aircraft_hex IS NULL OR aircraft_hex = '')
  AND TRIM(body) REGEXP '^[A-Fa-f0-9]{6}$';
