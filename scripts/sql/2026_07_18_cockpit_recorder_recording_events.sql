ALTER TABLE ipca_cockpit_recordings
    ADD COLUMN recording_events_storage_path VARCHAR(512) NULL AFTER beacon_event_count,
    ADD COLUMN recording_events_file_size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER recording_events_storage_path,
    ADD COLUMN recording_event_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER recording_events_file_size_bytes,
    ADD COLUMN recording_warning_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER recording_event_count,
    ADD COLUMN recording_error_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER recording_warning_count;
