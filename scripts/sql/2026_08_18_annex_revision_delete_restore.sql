ALTER TABLE ipca_publishing_annex_revisions
  MODIFY COLUMN source ENUM(
    'create',
    'content_update',
    'reimport',
    'identity',
    'migrate',
    'delete',
    'restore'
  ) NOT NULL DEFAULT 'content_update';
