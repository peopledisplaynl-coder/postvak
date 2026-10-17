-- 003: cache of preview lines for the message list

CREATE TABLE IF NOT EXISTS message_snippets (
    account_id      INT UNSIGNED NOT NULL,
    folder_hash     CHAR(40) NOT NULL,
    uidvalidity     BIGINT UNSIGNED NOT NULL,
    uid             INT UNSIGNED NOT NULL,
    snippet         VARCHAR(400) NOT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (account_id, folder_hash, uid),
    CONSTRAINT fk_snippets_account FOREIGN KEY (account_id) REFERENCES accounts (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO schema_migrations (version) VALUES ('003_message_snippets');
