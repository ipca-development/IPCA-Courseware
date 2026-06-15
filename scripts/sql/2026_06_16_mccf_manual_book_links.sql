-- MCCF manual links: anchor to live published book sections (not legacy canonical excerpts).
-- Preserves existing rows and regulation links; backfills section_ref from prior excerpt joins.
-- Re-run safe: skip ADD COLUMN statements if columns already exist.

ALTER TABLE ipca_canonical_requirement_excerpt_links
  ADD COLUMN book_version_id BIGINT UNSIGNED NULL COMMENT 'Live ipca_publishing_book_versions.id' AFTER excerpt_id,
  ADD COLUMN manual_code VARCHAR(32) NULL AFTER book_version_id,
  ADD COLUMN manual_part VARCHAR(64) NULL AFTER manual_code,
  ADD COLUMN section_ref VARCHAR(64) NULL AFTER manual_part,
  ADD COLUMN stable_anchor VARCHAR(191) NULL AFTER section_ref;

UPDATE ipca_canonical_requirement_excerpt_links l
INNER JOIN ipca_canonical_excerpts e ON e.id = l.excerpt_id
SET
  l.manual_code = COALESCE(NULLIF(TRIM(l.manual_code), ''), e.manual_code),
  l.manual_part = COALESCE(NULLIF(TRIM(l.manual_part), ''), e.manual_part),
  l.section_ref = COALESCE(NULLIF(TRIM(l.section_ref), ''), e.section_ref)
WHERE l.section_ref IS NULL OR TRIM(l.section_ref) = '';

UPDATE ipca_canonical_requirement_excerpt_links l
INNER JOIN ipca_publishing_books b
  ON b.book_key = UPPER(TRIM(l.manual_code))
INNER JOIN ipca_publishing_book_versions bv
  ON bv.book_id = b.id
 AND bv.version_label = CASE UPPER(TRIM(l.manual_code))
      WHEN 'OMM' THEN '4.0'
      ELSE '6.0'
    END
SET l.book_version_id = bv.id
WHERE l.book_version_id IS NULL
  AND l.manual_code IS NOT NULL
  AND TRIM(l.manual_code) <> '';

-- Book-based links no longer require legacy excerpt rows.
ALTER TABLE ipca_canonical_requirement_excerpt_links
  DROP FOREIGN KEY fk_ipcarel_excerpt;

ALTER TABLE ipca_canonical_requirement_excerpt_links
  MODIFY excerpt_id BIGINT UNSIGNED NULL COMMENT 'Legacy; optional when book_version_id + section_ref are set';

ALTER TABLE ipca_canonical_requirement_excerpt_links
  ADD UNIQUE KEY uk_ipcarel_req_book_section (requirement_id, book_version_id, section_ref, link_type);
