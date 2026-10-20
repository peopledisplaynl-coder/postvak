-- 015: muted conversations, follow-up reminders and several signatures per account.

-- Conversations to ignore: new replies are archived by the cron job (ids are Message-IDs of the conversation).
CREATE TABLE IF NOT EXISTS muted_threads (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    account_id      INT UNSIGNED NOT NULL,
    message_id      VARCHAR(255) NOT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_muted_thread (account_id, message_id),
    CONSTRAINT fk_muted_threads_account FOREIGN KEY (account_id) REFERENCES accounts (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- "Remind me if nobody answers": checked by the cron job; without an answer the message comes back to the inbox.
CREATE TABLE IF NOT EXISTS followups (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    account_id      INT UNSIGNED NOT NULL,
    message_id      VARCHAR(255) NOT NULL,
    subject         VARCHAR(500) NOT NULL DEFAULT '',
    remind_at       DATETIME NOT NULL,
    status          VARCHAR(10) NOT NULL DEFAULT 'waiting',   -- waiting | answered | reminded
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_followups_due (status, remind_at),
    CONSTRAINT fk_followups_account FOREIGN KEY (account_id) REFERENCES accounts (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Several signatures: {"list":[{"id","name","html"}], "new": id, "reply": id}. signature_html stays the one for new mail.
ALTER TABLE accounts ADD COLUMN signatures MEDIUMTEXT NULL AFTER signature_html;

INSERT IGNORE INTO schema_migrations (version) VALUES ('015_mute_followup_signatures');
