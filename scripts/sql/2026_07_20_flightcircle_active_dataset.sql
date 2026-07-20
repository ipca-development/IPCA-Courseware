ALTER TABLE ipca_flightcircle_import_batches
  ADD COLUMN active_dataset TINYINT(1) NOT NULL DEFAULT 0 AFTER operation_candidate_count,
  ADD COLUMN superseded_by_batch_id BIGINT UNSIGNED NULL AFTER active_dataset,
  ADD COLUMN superseded_at DATETIME(3) NULL AFTER superseded_by_batch_id,
  ADD KEY idx_ipca_flightcircle_batches_active (active_dataset, import_status, completed_at);

