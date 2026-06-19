-- FAA PPL required event tags for Admin Logbook assignment.
-- These categories are intentionally event-specific so instructors can tag the
-- exact logbook entry or entries used as evidence for each form/checkride item.

INSERT INTO ipca_flight_requirement_categories
  (authority, certificate, requirement_key, label, description, minimum_time, minimum_distance_nm, minimum_count,
   automatic_rules_json, manual_rules_json, allow_one_flight_multiple_requirements, allow_multiple_flights_one_requirement, status)
VALUES
  ('FAA_PART_61', 'PPL', 'dual_cross_country_training',
   'Dual Cross-Country Training',
   'Tagged dual cross-country training flight(s) used as evidence for FAA PPL eligibility.',
   NULL, NULL, 1, JSON_OBJECT('type', 'manual_assignment'), JSON_OBJECT('evidence', 'selected_logbook_entries'), 1, 1, 'active'),

  ('FAA_PART_61', 'PPL', 'dual_night_training',
   'Dual Night Training',
   'Tagged dual night training flight(s).',
   NULL, NULL, 1, JSON_OBJECT('type', 'manual_assignment'), JSON_OBJECT('evidence', 'selected_logbook_entries'), 1, 1, 'active'),

  ('FAA_PART_61', 'PPL', 'dual_night_cross_country',
   'Dual Night Cross-Country Flight',
   'Tagged dual night cross-country flight including distance covered.',
   NULL, NULL, 1, JSON_OBJECT('type', 'manual_assignment'), JSON_OBJECT('evidence', 'selected_logbook_entries', 'requires_distance_nm', true), 1, 1, 'active'),

  ('FAA_PART_61', 'PPL', 'dual_night_takeoffs_landings',
   'Dual Night Takeoffs and Landings',
   '10 dual takeoffs and landings at night with traffic patterns - §61.109(a)(2)(ii).',
   NULL, NULL, 10, JSON_OBJECT('type', 'selected_entries_sum', 'metric', 'night_landings'), JSON_OBJECT('evidence', 'selected_logbook_entries'), 1, 1, 'active'),

  ('FAA_PART_61', 'PPL', 'dual_instrument_flight_training',
   'Dual Instrument Flight Training',
   'Tagged dual/basic instrument flight training entry or entries.',
   NULL, NULL, 1, JSON_OBJECT('type', 'manual_assignment'), JSON_OBJECT('evidence', 'selected_logbook_entries'), 1, 1, 'active'),

  ('FAA_PART_61', 'PPL', 'solo_cross_country_flight',
   'Solo Cross-Country Flight',
   'Tagged solo cross-country flight entry or entries.',
   NULL, NULL, 1, JSON_OBJECT('type', 'manual_assignment'), JSON_OBJECT('evidence', 'selected_logbook_entries'), 1, 1, 'active'),

  ('FAA_PART_61', 'PPL', 'long_150nm_solo_cross_country_flight',
   'Long 150 NM Solo Cross-Country Flight',
   'Tagged long solo cross-country flight with at least 150 NM total distance.',
   NULL, 150.0, 1, JSON_OBJECT('type', 'selected_entries_distance'), JSON_OBJECT('evidence', 'selected_logbook_entries', 'requires_distance_nm', true), 1, 1, 'active'),

  ('FAA_PART_61', 'PPL', 'towered_airport_takeoffs_landings',
   'Towered Airport Takeoffs and Landings',
   'Tagged takeoff/landing entry or entries at a towered airport.',
   NULL, NULL, 1, JSON_OBJECT('type', 'manual_assignment'), JSON_OBJECT('evidence', 'selected_logbook_entries'), 1, 1, 'active')
ON DUPLICATE KEY UPDATE
  label = VALUES(label),
  description = VALUES(description),
  minimum_time = VALUES(minimum_time),
  minimum_distance_nm = VALUES(minimum_distance_nm),
  minimum_count = VALUES(minimum_count),
  automatic_rules_json = VALUES(automatic_rules_json),
  manual_rules_json = VALUES(manual_rules_json),
  allow_one_flight_multiple_requirements = VALUES(allow_one_flight_multiple_requirements),
  allow_multiple_flights_one_requirement = VALUES(allow_multiple_flights_one_requirement),
  status = 'active',
  updated_at = CURRENT_TIMESTAMP;
