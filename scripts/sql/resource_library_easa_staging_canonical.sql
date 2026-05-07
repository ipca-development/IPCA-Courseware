-- Add canonical body text (normalized for diff/hashing) after plain_text. Run once on existing DBs.

ALTER TABLE easa_erules_import_nodes_staging
  ADD COLUMN canonical_text MEDIUMTEXT NULL COMMENT 'Whitespace-collapsed body for hashing/compare' AFTER plain_text;
