-- TV flipboard playlist view types (radar + aircraft board slots).
-- Apply once after tv_screen_messages exists.

ALTER TABLE tv_screen_messages
  MODIFY message_type ENUM(
    'standard',
    'urgent',
    'schedule',
    'night',
    'aircraft',
    'radar',
    'aircraft_board'
  ) NOT NULL DEFAULT 'standard';
