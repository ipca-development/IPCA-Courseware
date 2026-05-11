-- Per-user EASA bookmarks + text highlights for the Live Easy Access Rules tree.
--
-- Two additive tables. Both are scoped to the authenticated user (`user_id`) — a
-- bookmark or highlight created by one user is never visible to anyone else.
-- The categories table only applies to bookmarks; highlights have NULL category.
--
-- Idempotent: re-runs are safe.

CREATE TABLE IF NOT EXISTS easa_user_bookmark_categories (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id       INT UNSIGNED NOT NULL,
  name          VARCHAR(80) NOT NULL,
  color_hex     VARCHAR(7) NULL,
  sort_order    INT NOT NULL DEFAULT 0,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_user_name (user_id, name),
  KEY idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Combined "marks" table: bookmarks (1 per user/node) and highlights (N per user/node).
-- `kind` discriminates; bookmarks set `category_id` (or NULL for Uncategorized) and
-- leave `selection_json` NULL. Highlights leave `category_id` NULL and set
-- `selection_json` to `{ text, prefix, suffix }` plus a `color_hex` swatch.
-- Bookmark uniqueness (one per user/node) is enforced at the application layer in
-- src/easa_bookmarks.php so highlights remain multi-per-node.
CREATE TABLE IF NOT EXISTS easa_user_bookmarks (
  id                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id               INT UNSIGNED NOT NULL,
  kind                  ENUM('bookmark','highlight') NOT NULL DEFAULT 'bookmark',
  category_id           INT UNSIGNED NULL,
  batch_id              INT UNSIGNED NOT NULL,
  node_uid              VARCHAR(255) NOT NULL,
  title_snapshot        VARCHAR(500) NULL,
  breadcrumb_snapshot   TEXT NULL,
  erules_id_snapshot    VARCHAR(64) NULL,
  annotation            TEXT NULL,
  selection_json        TEXT NULL,
  color_hex             VARCHAR(7) NULL,
  created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_user_node (user_id, batch_id, node_uid),
  KEY idx_user_kind_cat (user_id, kind, category_id),
  KEY idx_category (category_id),
  CONSTRAINT fk_easa_buw_cat FOREIGN KEY (category_id)
    REFERENCES easa_user_bookmark_categories (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
