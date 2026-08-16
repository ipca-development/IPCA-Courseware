-- Community post body text and longer instructor/admin videos.
-- Additive. Re-run safe.

ALTER TABLE ipca_community_posts
  ADD COLUMN body TEXT NULL AFTER caption;
