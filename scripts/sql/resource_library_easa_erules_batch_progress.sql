-- Import progress / observability for easa_erules_import_batches (run once after resource_library_easa_erules.sql).

ALTER TABLE easa_erules_import_batches
  ADD COLUMN parse_started_at DATETIME NULL COMMENT 'UTC, when streaming parse began' AFTER error_message,
  ADD COLUMN parse_finished_at DATETIME NULL COMMENT 'UTC, when parse completed or failed' AFTER parse_started_at,
  ADD COLUMN parse_rows_so_far INT UNSIGNED NULL COMMENT 'Heartbeat: rows inserted so far' AFTER parse_finished_at,
  ADD COLUMN parse_phase VARCHAR(64) NULL COMMENT 'e.g. streaming, inserting, done' AFTER parse_rows_so_far,
  ADD COLUMN parse_last_node_type VARCHAR(128) NULL COMMENT 'Last inserted regulatory element local name' AFTER parse_phase,
  ADD COLUMN parse_detail VARCHAR(512) NULL COMMENT 'Short status line for UI' AFTER parse_last_node_type;
