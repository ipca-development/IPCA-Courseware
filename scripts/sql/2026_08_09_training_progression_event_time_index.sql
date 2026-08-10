-- Prevent the Admin dashboard's latest-events query from sorting the full
-- training progression audit table on every page load.
ALTER TABLE training_progression_events
    ADD INDEX idx_training_progression_event_time (event_time DESC, id DESC),
    ALGORITHM=INPLACE,
    LOCK=NONE;
