-- FAA PPL required event tags for Admin Logbook assignment.
-- These categories are intentionally event-specific so instructors can tag the
-- exact logbook entry or entries used as evidence for each form/checkride item.

INSERT INTO ipca_flight_requirement_categories
  (authority, certificate, requirement_key, label, description, minimum_time, minimum_distance_nm, minimum_count,
   automatic_rules_json, manual_rules_json, allow_one_flight_multiple_requirements, allow_multiple_flights_one_requirement, status)
VALUES
  ('FAA_PART_61', 'PPL', 'dual_cross_country_training',
   'Dual Cross-Country Training',
   '3 hours of dual cross-country flight training - §61.109(a)(1).',
   3.0, NULL, NULL, JSON_OBJECT('type', 'filtered_sum', 'metric', 'cross_country_time', 'filters', JSON_ARRAY(JSON_OBJECT('field', 'dual_received_time', 'operator', 'gt', 'value', 0), JSON_OBJECT('field', 'cross_country_time', 'operator', 'gt', 'value', 0))), JSON_OBJECT('evidence', 'accepted_logbook_rows'), 1, 1, 'active'),

  ('FAA_PART_61', 'PPL', 'dual_night_training',
   'Dual Night Training',
   '3 hours of dual night flight training - §61.109(a)(2).',
   3.0, NULL, NULL, JSON_OBJECT('type', 'filtered_sum', 'metric', 'night_time', 'filters', JSON_ARRAY(JSON_OBJECT('field', 'dual_received_time', 'operator', 'gt', 'value', 0), JSON_OBJECT('field', 'night_time', 'operator', 'gt', 'value', 0))), JSON_OBJECT('evidence', 'accepted_logbook_rows'), 1, 1, 'active'),

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
   '3 hours of flight training by reference to instruments - §61.109(a)(3).',
   3.0, NULL, NULL, JSON_OBJECT('type', 'filtered_sum', 'metric', 'basic_instrument_flying_time', 'filters', JSON_ARRAY(JSON_OBJECT('field', 'dual_received_time', 'operator', 'gt', 'value', 0), JSON_OBJECT('field', 'basic_instrument_flying_time', 'operator', 'gt', 'value', 0))), JSON_OBJECT('evidence', 'accepted_logbook_rows'), 1, 1, 'active'),

  ('FAA_PART_61', 'PPL', 'solo_cross_country_flight',
   'Solo Cross-Country Flight',
   '5 hours of solo cross-country time - §61.109(a)(5)(i).',
   5.0, NULL, NULL, JSON_OBJECT('type', 'filtered_sum', 'metric', 'cross_country_time', 'filters', JSON_ARRAY(JSON_OBJECT('field', 'solo_time', 'operator', 'gt', 'value', 0), JSON_OBJECT('field', 'cross_country_time', 'operator', 'gt', 'value', 0))), JSON_OBJECT('evidence', 'accepted_logbook_rows'), 1, 1, 'active'),

  ('FAA_PART_61', 'PPL', 'long_150nm_solo_cross_country_flight',
   'Long 150 NM Solo Cross-Country Flight',
   'Tagged long solo cross-country flight with at least 150 NM total distance.',
   NULL, 150.0, 1, JSON_OBJECT('type', 'selected_entries_distance'), JSON_OBJECT('evidence', 'selected_logbook_entries', 'requires_distance_nm', true), 1, 1, 'active'),

  ('FAA_PART_61', 'PPL', 'towered_airport_takeoffs_landings',
   'Towered Airport Takeoffs and Landings',
   '3 solo takeoffs and landings to a full stop at an airport with an operating control tower - §61.109(a)(5)(iii).',
   NULL, NULL, 3, JSON_OBJECT('type', 'filtered_sum', 'metric', 'towered_airport_landings', 'filters', JSON_ARRAY(JSON_OBJECT('field', 'solo_time', 'operator', 'gt', 'value', 0), JSON_OBJECT('field', 'towered_airport_landings', 'operator', 'gt', 'value', 0))), JSON_OBJECT('evidence', 'accepted_logbook_rows'), 1, 1, 'active')
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
