-- Add cross-country overlap requirement metrics for FAA/EASA verification.
-- These metrics are calculated from trusted Admin Logbook entries.

INSERT INTO ipca_flight_requirement_categories
  (authority, certificate, requirement_key, label, description, minimum_time, minimum_distance_nm, minimum_count, automatic_rules_json, manual_rules_json)
VALUES
  ('FAA_PART_61', 'PPL', 'dual_cross_country_time', 'Dual Cross Country Time', 'Dual received cross-country flight time.', 3.0, NULL, NULL, JSON_OBJECT('metric', 'dual_cross_country_time'), JSON_OBJECT()),
  ('FAA_PART_61', 'PPL', 'solo_cross_country_time', 'Solo Cross Country Time', 'Solo cross-country flight time.', 5.0, NULL, NULL, JSON_OBJECT('metric', 'solo_cross_country_time'), JSON_OBJECT()),
  ('FAA_PART_61', 'PPL', 'pic_cross_country_time', 'PIC Cross Country Time', 'PIC cross-country flight time.', NULL, NULL, NULL, JSON_OBJECT('metric', 'pic_cross_country_time'), JSON_OBJECT()),
  ('EASA', 'PPL', 'dual_cross_country_time', 'Dual Cross Country Time', 'Dual cross-country/navigation instruction time.', NULL, NULL, NULL, JSON_OBJECT('metric', 'dual_cross_country_time'), JSON_OBJECT()),
  ('EASA', 'PPL', 'solo_cross_country_time', 'Solo Cross Country Time', 'Solo cross-country flight time.', 5.0, NULL, NULL, JSON_OBJECT('metric', 'solo_cross_country_time'), JSON_OBJECT()),
  ('EASA', 'PPL', 'pic_cross_country_time', 'PIC Cross Country Time', 'PIC cross-country flight time.', NULL, NULL, NULL, JSON_OBJECT('metric', 'pic_cross_country_time'), JSON_OBJECT())
ON DUPLICATE KEY UPDATE
  label = VALUES(label),
  description = VALUES(description),
  minimum_time = VALUES(minimum_time),
  minimum_distance_nm = VALUES(minimum_distance_nm),
  minimum_count = VALUES(minimum_count),
  automatic_rules_json = VALUES(automatic_rules_json),
  manual_rules_json = VALUES(manual_rules_json),
  status = 'active',
  updated_at = CURRENT_TIMESTAMP;
