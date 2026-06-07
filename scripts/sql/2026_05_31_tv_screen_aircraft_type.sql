-- TV flip board: live ADS-B aircraft status message type.

ALTER TABLE tv_screen_messages
  MODIFY message_type ENUM('standard','urgent','schedule','night','aircraft') NOT NULL DEFAULT 'standard';

INSERT INTO tv_screen_messages (
  screen_key,
  message_type,
  title,
  body,
  priority,
  display_duration_seconds,
  announce_audio_enabled,
  status
)
SELECT
  'aircraft',
  'aircraft',
  'N397EA',
  'N397EA',
  95,
  15,
  0,
  'active'
WHERE NOT EXISTS (
  SELECT 1
  FROM tv_screen_messages
  WHERE screen_key = 'aircraft'
    AND message_type = 'aircraft'
  LIMIT 1
);
