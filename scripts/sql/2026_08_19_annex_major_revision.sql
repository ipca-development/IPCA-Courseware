ALTER TABLE ipca_publishing_annex_revisions
  MODIFY COLUMN source ENUM(
    'create',
    'content_update',
    'reimport',
    'identity',
    'major_revision',
    'migrate',
    'delete',
    'restore',
    'revert'
  ) NOT NULL DEFAULT 'content_update';
