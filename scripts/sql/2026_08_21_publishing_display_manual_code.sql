ALTER TABLE ipca_publishing_books
    ADD COLUMN display_manual_code VARCHAR(32) NULL
    COMMENT 'Editable reader-facing code; book_key and manual_code remain stable machine identities'
    AFTER manual_code;

UPDATE ipca_publishing_books
SET display_manual_code = REPLACE(manual_code, '_', ' ')
WHERE display_manual_code IS NULL
  AND manual_code IS NOT NULL
  AND manual_code <> '';
