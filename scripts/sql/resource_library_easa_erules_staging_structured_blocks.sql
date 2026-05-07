-- Adds structured canonical display payload for easa_erules_import_nodes_staging.
-- Run once on existing databases. If the column already exists, skip (you will get “Duplicate column name”).
--
--   mysql ... < scripts/sql/resource_library_easa_erules_staging_structured_blocks.sql

ALTER TABLE easa_erules_import_nodes_staging
  ADD COLUMN structured_blocks_json JSON NULL
    COMMENT 'Canonical display blocks for UI — heading, paragraph, list_item, table (no raw OOXML/XML in the viewer)'
    AFTER canonical_text;
