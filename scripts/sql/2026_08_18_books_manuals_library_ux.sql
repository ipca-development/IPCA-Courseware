ALTER TABLE ipca_publishing_book_profiles
  ADD COLUMN approved_reader_policy
    ENUM('all_readers','selected_reviewers')
    NOT NULL DEFAULT 'all_readers'
    AFTER authority_code;

CREATE TABLE IF NOT EXISTS ipca_publishing_book_reviewers (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  book_id BIGINT UNSIGNED NOT NULL,
  reviewer_user_id INT NOT NULL,
  assigned_by INT NULL,
  assigned_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uk_ipca_pub_book_reviewer (book_id, reviewer_user_id),
  KEY idx_ipca_pub_reviewer_user (reviewer_user_id, book_id),
  CONSTRAINT fk_ipca_pub_book_reviewer_book
    FOREIGN KEY (book_id) REFERENCES ipca_publishing_books(id) ON DELETE CASCADE,
  CONSTRAINT fk_ipca_pub_book_reviewer_user
    FOREIGN KEY (reviewer_user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_ipca_pub_book_reviewer_actor
    FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
