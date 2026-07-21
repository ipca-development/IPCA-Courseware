-- Repair active FlightCircle staging rows for N397EA after aircraft resource rules were expanded.
-- Safe to re-run. Preserves raw/source evidence; only corrects normalized staging classification.

UPDATE ipca_flightcircle_staging_records sr
INNER JOIN ipca_flightcircle_import_batches b ON b.id = sr.batch_id
SET
  sr.resource_type = 'aircraft',
  sr.import_disposition = 'operation_candidate',
  sr.review_status = CASE
    WHEN sr.review_status IN ('ignored', 'approved_ignored') THEN 'needs_review'
    ELSE sr.review_status
  END,
  sr.updated_at = CURRENT_TIMESTAMP(3)
WHERE b.active_dataset = 1
  AND b.import_status = 'completed'
  AND (
    UPPER(TRIM(COALESCE(sr.tail_number, ''))) = 'N397EA'
    OR UPPER(TRIM(COALESCE(sr.resource_identifier, ''))) = 'N397EA'
  )
  AND sr.resource_type <> 'aircraft';

SELECT
  COUNT(*) AS active_n397ea_aircraft_rows
FROM ipca_flightcircle_staging_records sr
INNER JOIN ipca_flightcircle_import_batches b ON b.id = sr.batch_id
WHERE b.active_dataset = 1
  AND b.import_status = 'completed'
  AND sr.resource_type = 'aircraft'
  AND (
    UPPER(TRIM(COALESCE(sr.tail_number, ''))) = 'N397EA'
    OR UPPER(TRIM(COALESCE(sr.resource_identifier, ''))) = 'N397EA'
  );
