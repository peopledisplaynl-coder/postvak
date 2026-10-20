-- 016: senders the user moved to another inbox tab ("from now on, mail from this sender goes to Promotions").

CREATE TABLE IF NOT EXISTS inbox_tab_senders (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    account_id      INT UNSIGNED NOT NULL,
    sender          VARCHAR(255) NOT NULL,           -- lower-case address
    category        VARCHAR(20) NOT NULL,            -- primary | promotions | social | updates
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_inbox_tab_sender (account_id, sender),
    CONSTRAINT fk_inbox_tab_senders_account FOREIGN KEY (account_id) REFERENCES accounts (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO schema_migrations (version) VALUES ('016_inbox_tab_senders');
