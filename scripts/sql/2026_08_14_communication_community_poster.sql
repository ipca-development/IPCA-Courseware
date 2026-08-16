-- Community video poster frames. Additive. Re-run safe via apply script.

ALTER TABLE ipca_community_post_media
  ADD COLUMN poster_storage_key VARCHAR(512) NULL AFTER duration_ms;
