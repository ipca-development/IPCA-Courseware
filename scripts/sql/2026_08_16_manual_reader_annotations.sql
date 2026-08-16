ALTER TABLE users
    ADD COLUMN can_manual_reviewer TINYINT(1) NOT NULL DEFAULT 0;

CREATE TABLE IF NOT EXISTS ipca_manual_reader_annotations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    annotation_uuid CHAR(36) NOT NULL,
    user_id INT NOT NULL,
    book_version_id BIGINT UNSIGNED NOT NULL,
    book_key VARCHAR(96) NOT NULL,
    kind ENUM('bookmark','highlight') NOT NULL,
    page_number_snapshot INT NOT NULL,
    label VARCHAR(255) NULL,
    selected_text TEXT NULL,
    source_fragment_id VARCHAR(191) NULL,
    stable_anchor VARCHAR(191) NULL,
    character_start INT UNSIGNED NULL,
    character_end INT UNSIGNED NULL,
    prefix_text VARCHAR(255) NULL,
    suffix_text VARCHAR(255) NULL,
    color_key VARCHAR(32) NULL,
    personal_note TEXT NULL,
    client_updated_at_utc DATETIME(3) NOT NULL,
    server_updated_at_utc DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
        ON UPDATE CURRENT_TIMESTAMP(3),
    deleted_at_utc DATETIME(3) NULL,
    created_at_utc DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    UNIQUE KEY uk_imra_uuid (annotation_uuid),
    KEY idx_imra_user_version (user_id, book_version_id, deleted_at_utc),
    KEY idx_imra_anchor (book_version_id, stable_anchor, source_fragment_id),
    CONSTRAINT fk_imra_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_imra_version FOREIGN KEY (book_version_id)
        REFERENCES ipca_publishing_book_versions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ipca_manual_reader_review_threads (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    thread_uuid CHAR(36) NOT NULL,
    book_version_id BIGINT UNSIGNED NOT NULL,
    book_key VARCHAR(96) NOT NULL,
    page_number_snapshot INT NOT NULL,
    selected_text TEXT NOT NULL,
    source_fragment_id VARCHAR(191) NULL,
    stable_anchor VARCHAR(191) NULL,
    character_start INT UNSIGNED NULL,
    character_end INT UNSIGNED NULL,
    status ENUM('open','resolved') NOT NULL DEFAULT 'open',
    created_by_user_id INT NOT NULL,
    created_at_utc DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at_utc DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
        ON UPDATE CURRENT_TIMESTAMP(3),
    resolved_by_user_id INT NULL,
    resolved_at_utc DATETIME(3) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_imrrt_uuid (thread_uuid),
    KEY idx_imrrt_version_anchor (book_version_id, stable_anchor, source_fragment_id),
    CONSTRAINT fk_imrrt_version FOREIGN KEY (book_version_id)
        REFERENCES ipca_publishing_book_versions(id) ON DELETE CASCADE,
    CONSTRAINT fk_imrrt_creator FOREIGN KEY (created_by_user_id)
        REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_imrrt_resolver FOREIGN KEY (resolved_by_user_id)
        REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ipca_manual_reader_review_comments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    comment_uuid CHAR(36) NOT NULL,
    thread_id BIGINT UNSIGNED NOT NULL,
    user_id INT NOT NULL,
    body TEXT NOT NULL,
    regulation_reference_json JSON NULL,
    created_at_utc DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at_utc DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
        ON UPDATE CURRENT_TIMESTAMP(3),
    deleted_at_utc DATETIME(3) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_imrrc_uuid (comment_uuid),
    KEY idx_imrrc_thread (thread_id, id),
    CONSTRAINT fk_imrrc_thread FOREIGN KEY (thread_id)
        REFERENCES ipca_manual_reader_review_threads(id) ON DELETE CASCADE,
    CONSTRAINT fk_imrrc_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
