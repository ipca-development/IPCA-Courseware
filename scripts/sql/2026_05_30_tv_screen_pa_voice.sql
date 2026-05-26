-- TV flip board: per-message OpenAI PA voice selection.

ALTER TABLE tv_screen_messages
  ADD COLUMN voice VARCHAR(32) NULL DEFAULT NULL AFTER voice_text;
