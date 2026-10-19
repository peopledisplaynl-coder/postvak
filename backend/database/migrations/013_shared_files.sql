-- 013: large attachments sent as a download link. The file itself lives in storage/files/ (outside the web root);
-- this table holds who uploaded it, the secret link token and when it expires.

CREATE TABLE IF NOT EXISTS shared_files (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id         INT UNSIGNED NOT NULL,
    token           CHAR(32) NOT NULL,
    name            VARCHAR(255) NOT NULL,
    mime            VARCHAR(100) NOT NULL DEFAULT 'application/octet-stream',
    size            BIGINT UNSIGNED NOT NULL,
    received        BIGINT UNSIGNED NOT NULL DEFAULT 0,
    complete        TINYINT(1) NOT NULL DEFAULT 0,
    downloads       INT UNSIGNED NOT NULL DEFAULT 0,
    created_at      DATETIME NOT NULL,
    expires_at      DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_shared_file_token (token),
    KEY idx_shared_files_user (user_id),
    KEY idx_shared_files_expires (expires_at),
    CONSTRAINT fk_shared_files_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO schema_migrations (version) VALUES ('013_shared_files');
