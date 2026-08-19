-- Store annex content snapshots so a revision can be restored.
-- Additive: existing revision rows remain valid with NULL snapshot_json.

ALTER TABLE ipca_publishing_annex_revisions
  MODIFY COLUMN source ENUM(
    'create',
    'content_update',
    'reimport',
    'identity',
    'migrate',
    'delete',
    'restore',
    'revert'
  ) NOT NULL DEFAULT 'content_update';

ALTER TABLE ipca_publishing_annex_revisions
  ADD COLUMN snapshot_json LONGTEXT NULL AFTER note;
