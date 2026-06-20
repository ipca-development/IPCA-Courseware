-- Convert FAA PPL requirements that can be calculated from trusted logbook rows
-- away from manual tag-count rules.

UPDATE ipca_flight_requirement_categories
SET
  minimum_time = 40.0,
  minimum_distance_nm = NULL,
  minimum_count = NULL,
  automatic_rules_json = JSON_OBJECT('metric', 'total_flight_time'),
  manual_rules_json = JSON_OBJECT('evidence', 'accepted_logbook_rows'),
  updated_at = CURRENT_TIMESTAMP
WHERE authority = 'FAA_PART_61'
  AND certificate = 'PPL'
  AND requirement_key IN ('total_time', 'faa61_ppl_total_experience');

UPDATE ipca_flight_requirement_categories
SET
  minimum_time = 20.0,
  minimum_distance_nm = NULL,
  minimum_count = NULL,
  automatic_rules_json = JSON_OBJECT('metric', 'dual_received_time'),
  manual_rules_json = JSON_OBJECT('evidence', 'accepted_logbook_rows'),
  updated_at = CURRENT_TIMESTAMP
WHERE authority = 'FAA_PART_61'
  AND certificate = 'PPL'
  AND requirement_key IN ('dual_flight_training', 'dual_received_time', 'faa61_ppl_dual_flight_training');

UPDATE ipca_flight_requirement_categories
SET
  minimum_time = 10.0,
  minimum_distance_nm = NULL,
  minimum_count = NULL,
  automatic_rules_json = JSON_OBJECT('metric', 'solo_time'),
  manual_rules_json = JSON_OBJECT('evidence', 'accepted_logbook_rows'),
  updated_at = CURRENT_TIMESTAMP
WHERE authority = 'FAA_PART_61'
  AND certificate = 'PPL'
  AND requirement_key IN ('solo', 'solo_time', 'faa61_ppl_solo_total');

UPDATE ipca_flight_requirement_categories
SET
  minimum_time = 3.0,
  minimum_distance_nm = NULL,
  minimum_count = NULL,
  automatic_rules_json = JSON_OBJECT(
    'type', 'filtered_sum',
    'metric', 'basic_instrument_flying_time',
    'filters', JSON_ARRAY(
      JSON_OBJECT('field', 'dual_received_time', 'operator', 'gt', 'value', 0),
      JSON_OBJECT('field', 'basic_instrument_flying_time', 'operator', 'gt', 'value', 0)
    )
  ),
  manual_rules_json = JSON_OBJECT('evidence', 'accepted_logbook_rows'),
  updated_at = CURRENT_TIMESTAMP
WHERE authority = 'FAA_PART_61'
  AND certificate = 'PPL'
  AND requirement_key IN ('basic_instrument_flying', 'dual_instrument_flight_training');

UPDATE ipca_flight_requirement_categories
SET
  minimum_time = 3.0,
  minimum_distance_nm = NULL,
  minimum_count = NULL,
  automatic_rules_json = JSON_OBJECT(
    'type', 'filtered_sum',
    'metric', 'cross_country_time',
    'filters', JSON_ARRAY(
      JSON_OBJECT('field', 'dual_received_time', 'operator', 'gt', 'value', 0),
      JSON_OBJECT('field', 'cross_country_time', 'operator', 'gt', 'value', 0)
    )
  ),
  manual_rules_json = JSON_OBJECT('evidence', 'accepted_logbook_rows'),
  updated_at = CURRENT_TIMESTAMP
WHERE authority = 'FAA_PART_61'
  AND certificate = 'PPL'
  AND requirement_key IN ('dual_cross_country_training', 'dual_cross_country_time', 'faa61_ppl_dual_xc');

UPDATE ipca_flight_requirement_categories
SET
  minimum_time = 3.0,
  minimum_distance_nm = NULL,
  minimum_count = NULL,
  automatic_rules_json = JSON_OBJECT(
    'type', 'filtered_sum',
    'metric', 'night_time',
    'filters', JSON_ARRAY(
      JSON_OBJECT('field', 'dual_received_time', 'operator', 'gt', 'value', 0),
      JSON_OBJECT('field', 'night_time', 'operator', 'gt', 'value', 0)
    )
  ),
  manual_rules_json = JSON_OBJECT('evidence', 'accepted_logbook_rows'),
  updated_at = CURRENT_TIMESTAMP
WHERE authority = 'FAA_PART_61'
  AND certificate = 'PPL'
  AND requirement_key IN ('dual_night_training', 'night_dual', 'faa61_ppl_dual_night_training');

UPDATE ipca_flight_requirement_categories
SET
  minimum_time = 5.0,
  minimum_distance_nm = NULL,
  minimum_count = NULL,
  automatic_rules_json = JSON_OBJECT(
    'type', 'filtered_sum',
    'metric', 'cross_country_time',
    'filters', JSON_ARRAY(
      JSON_OBJECT('field', 'solo_time', 'operator', 'gt', 'value', 0),
      JSON_OBJECT('field', 'cross_country_time', 'operator', 'gt', 'value', 0)
    )
  ),
  manual_rules_json = JSON_OBJECT('evidence', 'accepted_logbook_rows'),
  updated_at = CURRENT_TIMESTAMP
WHERE authority = 'FAA_PART_61'
  AND certificate = 'PPL'
  AND requirement_key IN ('solo_cross_country_flight', 'solo_cross_country_time', 'faa61_ppl_solo_xc');

UPDATE ipca_flight_requirement_categories
SET
  minimum_time = NULL,
  minimum_distance_nm = NULL,
  minimum_count = 10,
  automatic_rules_json = JSON_OBJECT(
    'type', 'filtered_sum',
    'metric', 'night_landings',
    'filters', JSON_ARRAY(
      JSON_OBJECT('field', 'dual_received_time', 'operator', 'gt', 'value', 0),
      JSON_OBJECT('field', 'night_time', 'operator', 'gt', 'value', 0)
    )
  ),
  manual_rules_json = JSON_OBJECT('evidence', 'accepted_logbook_rows'),
  updated_at = CURRENT_TIMESTAMP
WHERE authority = 'FAA_PART_61'
  AND certificate = 'PPL'
  AND requirement_key = 'dual_night_takeoffs_landings';

UPDATE ipca_flight_requirement_categories
SET
  minimum_time = NULL,
  minimum_distance_nm = NULL,
  minimum_count = 3,
  automatic_rules_json = JSON_OBJECT(
    'type', 'filtered_sum',
    'metric', 'towered_airport_landings',
    'filters', JSON_ARRAY(
      JSON_OBJECT('field', 'solo_time', 'operator', 'gt', 'value', 0),
      JSON_OBJECT('field', 'towered_airport_landings', 'operator', 'gt', 'value', 0)
    )
  ),
  manual_rules_json = JSON_OBJECT('evidence', 'accepted_logbook_rows'),
  updated_at = CURRENT_TIMESTAMP
WHERE authority = 'FAA_PART_61'
  AND certificate = 'PPL'
  AND requirement_key IN ('towered_airport_takeoffs_landings', 'towered_airport_landings');
