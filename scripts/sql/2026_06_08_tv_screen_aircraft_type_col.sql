-- TV flip board: aircraft type column for operations grid (Alpha, C172SP, etc.).

ALTER TABLE tv_screen_messages
  ADD COLUMN aircraft_type VARCHAR(24) NULL DEFAULT NULL AFTER aircraft_label;
